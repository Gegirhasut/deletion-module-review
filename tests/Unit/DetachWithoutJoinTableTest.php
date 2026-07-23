<?php

declare(strict_types=1);

namespace Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Shared\Deletion\Attribute\RelationTo;
use Shared\Deletion\Enum\{DeletionCascade, RelationType};
use Tests\Fixture\{BlockingDetachChild, RootEntity};
use Tests\Support\DeletionServiceTestCase;

/**
 * Находка 9: DETACH_RELATIONS без joinTable.
 *
 * Матрица (ChildrenMatrixTest) уже фиксирует, что такая связь попадает в childrenDetach.
 * Здесь проверяется следствие: правило сохраняется с joinTable = null, а
 * DeletionOrchestrator::buildRecursive (:95) действует только при
 * `$joinTable && $cascade === 'detach'` — значит план не тронет эту связь никогда.
 */
final class DetachWithoutJoinTableTest extends DeletionServiceTestCase
{
    #[Test]
    public function detach_rule_without_join_table_is_stored_with_null_join_columns(): void
    {
        $this->mapEntities(BlockingDetachChild::class);

        [$rule] = $this->service()->getChildRelationRules(RootEntity::class);

        self::assertSame(DeletionCascade::DETACH_RELATIONS->value, $rule[6]);
        self::assertNull($rule[3], 'joinTable');
        self::assertNull($rule[4], 'joinColumn');
        self::assertNull($rule[5], 'inverseJoinColumn');
    }

    /**
     * Фиксирует текущее поведение. Связь видна в анализе и при этом не блокирует
     * родителя (:63-64 — осознанное решение авторов), а в план не попадёт из-за
     * guard-а на :95. Итог: em->remove($root) + flush() (:55-56) упрётся в FK-констрейнт.
     */
    #[Test]
    public function detach_without_join_table_is_visible_but_does_not_block_parent(): void
    {
        $this->mapEntities(BlockingDetachChild::class);
        $this->finderReturnsOneChild(new BlockingDetachChild());

        $relations = $this->service()->analyze(new RootEntity());

        self::assertCount(1, $relations->childrenDetach);
        self::assertTrue($relations->canDelete);
    }

    /**
     * Находка 9: так быть не должно. Комбинация «detach без join-параметров» нерабочая
     * по построению, и запрещать её надо на входе — в конструкторе атрибута, где ошибка
     * видна разработчику сразу, а не в виде FK-нарушения из СУБД во время удаления.
     */
    #[Test]
    #[Group('defects')]
    public function detach_cascade_without_join_table_should_be_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RelationTo(
            entity: RootEntity::class,
            field: 'root',
            type: RelationType::REFERENCE,
            cascade: DeletionCascade::DETACH_RELATIONS,
        );
    }
}
