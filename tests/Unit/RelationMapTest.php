<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Shared\Deletion\Enum\DeletionCascade;
use Tests\Fixture\{
    BlockingNoneChild,
    MultiRelationChild,
    RootEntity,
    SecondRootEntity,
    SubclassOfAnnotated
};
use Tests\Support\DeletionServiceTestCase;

/**
 * Построение карты связей: ensureMap() (:74-102) и getChildRelationRules() (:306-311).
 */
final class RelationMapTest extends DeletionServiceTestCase
{
    #[Test]
    public function map_is_built_once_across_repeated_calls(): void
    {
        $this->mapEntities(BlockingNoneChild::class);
        $this->finderReturnsOneChild(new BlockingNoneChild());

        $service = $this->service();
        $service->analyze(new RootEntity());
        $service->analyze(new RootEntity());
        $service->getChildRelationRules(RootEntity::class);

        self::assertSame(1, $this->getAllMetadataCalls, 'ensureMap() должен отработать один раз на процесс.');
    }

    #[Test]
    public function get_child_relation_rules_triggers_map_build(): void
    {
        $this->mapEntities(BlockingNoneChild::class);

        $rules = $this->service()->getChildRelationRules(RootEntity::class);

        self::assertSame(1, $this->getAllMetadataCalls);
        self::assertSame(
            [[BlockingNoneChild::class, 'root', true, null, null, null, DeletionCascade::NONE->value]],
            $rules,
            'Карта хранит 7-элементные кортежи, а cascade — строкой (:98).',
        );
    }

    #[Test]
    public function repeatable_attributes_produce_one_rule_each(): void
    {
        $this->mapEntities(MultiRelationChild::class);

        $service = $this->service();

        self::assertCount(1, $service->getChildRelationRules(RootEntity::class));
        self::assertCount(1, $service->getChildRelationRules(SecondRootEntity::class));
    }

    #[Test]
    public function unmapped_parent_class_yields_no_rules(): void
    {
        $this->mapEntities(BlockingNoneChild::class);

        self::assertSame([], $this->service()->getChildRelationRules(SecondRootEntity::class));
    }

    /**
     * Фиксирует текущее поведение. Находка 7 (её более широкая половина): так быть не должно.
     * ReflectionClass::getAttributes() (:117) не поднимается по цепочке предков, поэтому
     * наследник размеченной сущности молча теряет все связи — без ошибки, просто «связей нет».
     */
    #[Test]
    public function subclass_of_annotated_entity_reports_no_parent_relations(): void
    {
        $this->mapEntities();
        $this->finder->method('getId')->willReturn(1);

        self::assertSame(
            [],
            $this->service()->analyze(new SubclassOfAnnotated())->parents,
            'Связь объявлена на AnnotatedBase и теряется у наследника.',
        );
    }

    /**
     * Находка 7: так быть не должно. Наследник размеченной сущности обязан наследовать
     * её связи — иначе single/joined table inheritance тихо выключает весь модуль.
     */
    #[Test]
    #[Group('defects')]
    public function subclass_should_inherit_parent_relations_from_base(): void
    {
        $this->mapEntities();
        $this->finder->method('getId')->willReturn(1);

        $parents = $this->service()->analyze(new SubclassOfAnnotated())->parents;

        self::assertCount(1, $parents);
        self::assertSame(RootEntity::class, $parents[0]->childClass);
    }
}
