<?php

declare(strict_types=1);

namespace Tests\Unit;

use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Error;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixture\{
    AssociationChild,
    BlockingNoneChild,
    JoinTableChild,
    ReferenceNoneChild,
    RootEntity,
    ScalarFkChild,
    UninitializedFkChild
};
use Tests\Support\DeletionServiceTestCase;
use TypeError;

/**
 * Родительская сторона: findParentsByAttributes() (DeletionService.php:111-163).
 */
final class ParentSideTest extends DeletionServiceTestCase
{
    /**
     * @return iterable<string, array{0: class-string}>
     */
    public static function parentRelationTypes(): iterable
    {
        yield 'RelationType::BLOCKING на ребёнке' => [BlockingNoneChild::class];
        yield 'RelationType::REFERENCE на ребёнке' => [ReferenceNoneChild::class];
    }

    /**
     * Фиксирует текущее поведение. Находка 2: DeletionService.php:122 жёстко зашивает
     * $isBlocking = false для ЛЮБОГО типа связи, и это значение уезжает в поле hard
     * (:131, :142, :153). Поэтому цикл :47-52 не может выставить $hasHardParent = true
     * ни при каких данных — половина условия в :70 мертва.
     *
     * Тест не решает, какая трактовка BLOCKING верна (см. REVIEW.md §5, вопрос 1);
     * он делает недостижимость исполняемо доказанной.
     */
    #[Test]
    #[DataProvider('parentRelationTypes')]
    public function parent_relations_never_carry_hard_flag(string $childClass): void
    {
        $this->mapEntities();
        $this->finder->method('getId')->willReturn(1);

        $parents = $this->service()->analyze(new $childClass())->parents;

        self::assertCount(1, $parents);
        self::assertFalse($parents[0]->hard, 'Родительская связь не может быть hard — :122 зашивает false.');
    }

    #[Test]
    public function parent_group_is_emitted_for_scalar_fk(): void
    {
        $this->mapEntities();
        $this->finder->method('getId')->willReturn(1);

        $parents = $this->service()->analyze(new ScalarFkChild())->parents;

        self::assertCount(1, $parents);
        self::assertSame(RootEntity::class, $parents[0]->childClass);
        self::assertSame([7], $parents[0]->ids);
        self::assertSame('root', $parents[0]->field);
    }

    /**
     * @return iterable<string, array{0: mixed}>
     */
    public static function falsyForeignKeys(): iterable
    {
        yield 'FK = null' => [null];
        yield 'FK = 0' => [0];
        yield "FK = '' (пустая строка)" => [''];
    }

    /** Guard на DeletionService.php:150 отбрасывает три «пустых» значения FK. */
    #[Test]
    #[DataProvider('falsyForeignKeys')]
    public function parent_group_is_skipped_for_falsy_fk_values(mixed $fk): void
    {
        $this->mapEntities();
        $this->finder->method('getId')->willReturn(1);

        $child = new ScalarFkChild();
        $child->root = $fk;

        self::assertSame([], $this->service()->analyze($child)->parents);
    }

    /**
     * Фиксирует текущее поведение. Находка 3: так быть не должно.
     * README.md:18-22 предлагает ровно такую разметку — field указывает на поле-ассоциацию.
     * getScalarFkValue() (:227) объявлен как int|string|null и возвращает сырое
     * ReflectionProperty::getValue() (:238), то есть объект.
     */
    #[Test]
    public function association_object_in_fk_field_throws_type_error(): void
    {
        $this->mapEntities();
        $this->finder->method('getId')->willReturn(1);

        $this->expectException(TypeError::class);
        // PHP нормализует порядок в union, поэтому в сообщении будет string|int|null.
        $this->expectExceptionMessageMatches('/getScalarFkValue\(\): Return value must be of type/');

        $this->service()->analyze(new AssociationChild());
    }

    /**
     * Фиксирует текущее поведение. Находка 3, второй режим отказа: проверяется только
     * hasProperty() (:232), инициализация — нет, и getValue() (:238) бросает Error.
     */
    #[Test]
    public function uninitialized_typed_property_throws_error(): void
    {
        $this->mapEntities();
        $this->finder->method('getId')->willReturn(1);

        $this->expectException(Error::class);
        $this->expectExceptionMessageMatches('/must not be accessed before initialization/');

        $this->service()->analyze(new UninitializedFkChild());
    }

    /**
     * Фиксирует текущее поведение. Находка 4: getJoinTableParentIds() (:211-225) берёт
     * $this->em->createQueryBuilder() (:215) — то есть Doctrine\ORM\QueryBuilder, DQL —
     * и подставляет в from() ИМЯ ТАБЛИЦЫ, а в select()/where() имена столбцов.
     *
     * Тест не изображает работающий разбор DQL: он фиксирует, какие аргументы уходят в
     * билдер, и отдельно показывает, что 'advert_tag_relation' не является классом,
     * тогда как DQL::from() принимает только FQCN сущности.
     */
    #[Test]
    public function join_table_parent_lookup_builds_dql_over_a_raw_table_name(): void
    {
        $this->mapEntities();
        $this->finder->method('getId')->willReturn(3);

        $query = $this->createMock(Query::class);
        $query->method('getArrayResult')->willReturn([['advert_id' => 5]]);

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);
        $qb->expects(self::once())
            ->method('from')
            ->with('advert_tag_relation', 'jt')
            ->willReturnSelf();

        $this->em->expects(self::once())->method('createQueryBuilder')->willReturn($qb);

        $parents = $this->service()->analyze(new JoinTableChild())->parents;

        self::assertFalse(
            class_exists('advert_tag_relation'),
            'В from() уехало имя таблицы, а DQL принимает только FQCN сущности.',
        );
        self::assertSame([5], $parents[0]->ids);
    }

    /**
     * Находка 3: так быть не должно. Корректное поведение — разрешить ассоциацию в
     * идентификатор родителя (REVIEW.md, решение находки 3: getId() у связанного объекта).
     */
    #[Test]
    #[Group('defects')]
    public function association_object_should_resolve_to_parent_id(): void
    {
        $this->mapEntities();
        $this->finder->method('getId')->willReturn(42);

        $parents = $this->service()->analyze(new AssociationChild())->parents;

        self::assertCount(1, $parents);
        self::assertSame([42], $parents[0]->ids);
    }

    /**
     * Находка 3, второй режим: так быть не должно. Неинициализированное свойство —
     * это «связи нет», а не аварийная остановка анализа.
     */
    #[Test]
    #[Group('defects')]
    public function uninitialized_typed_property_should_be_treated_as_null(): void
    {
        $this->mapEntities();
        $this->finder->method('getId')->willReturn(1);

        self::assertSame([], $this->service()->analyze(new UninitializedFkChild())->parents);
    }
}
