<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Tests\Fixture\{BlockingNoneChild, RootEntity, ScalarFkChild};
use Tests\Support\DeletionServiceTestCase;

/**
 * Публичный контракт canDelete() (:28-36) и форма DependentGroupDto.
 */
final class CanDeleteContractTest extends DeletionServiceTestCase
{
    #[Test]
    public function entity_without_relations_can_be_deleted(): void
    {
        $this->mapEntities();
        $this->finder->method('getId')->willReturn(1);

        $result = $this->service()->canDelete(new RootEntity());

        self::assertTrue($result->canDelete);
        self::assertSame([], $result->dependents);
    }

    /** Ветка `if ($ids !== [])` на :283: правило в карте есть, но finder пуст — группы нет. */
    #[Test]
    public function empty_finder_result_emits_no_group(): void
    {
        $this->mapEntities(BlockingNoneChild::class);
        $this->finder->method('getId')->willReturn(100);
        $this->finder->method('findByAssociation')->willReturn([]);

        $relations = $this->service()->analyze(new RootEntity());

        self::assertSame([], $relations->childrenDelete);
        self::assertSame([], $relations->childrenDetach);
        self::assertTrue($relations->canDelete);
    }

    /**
     * Фиксирует текущее поведение. canDelete() склеивает parents, childrenDelete и
     * childrenDetach одним array_merge (:34), а поле DTO называется childClass — поэтому
     * для родительской записи там лежит класс РОДИТЕЛЯ. Потребитель не может отличить
     * «это меня блокирует» от «это будет удалено вместе со мной». Находка 13.
     */
    #[Test]
    public function dependents_contains_parent_class_in_a_field_named_child_class(): void
    {
        $this->mapEntities();
        $this->finder->method('getId')->willReturn(1);

        $dependents = $this->service()->canDelete(new ScalarFkChild())->dependents;

        self::assertCount(1, $dependents);
        self::assertSame(RootEntity::class, $dependents[0]->childClass);
        self::assertFalse($dependents[0]->hard);
    }

    /**
     * Поле count всегда равно count(ids) — оно избыточно по построению.
     * Пинит вопрос 3 из REVIEW.md §5 («зачем count рядом с ids»).
     */
    #[Test]
    public function count_always_equals_number_of_ids(): void
    {
        $this->mapEntities(BlockingNoneChild::class);
        $this->finder->method('getId')->willReturn(100);
        $this->finder->method('findByAssociation')->willReturn([
            new BlockingNoneChild(),
            new BlockingNoneChild(),
            new BlockingNoneChild(),
        ]);

        $dependents = $this->service()->canDelete(new RootEntity())->dependents;

        self::assertNotSame([], $dependents);

        foreach ($dependents as $group) {
            self::assertSame(count($group->ids), $group->count);
        }
    }
}
