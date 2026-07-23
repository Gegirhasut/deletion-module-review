<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Tests\Fixture\{JsonArrayChild, RootEntity};
use Tests\Support\DeletionServiceTestCase;

/**
 * JSON-ветка: isJsonField() (:313-326), getJsonArrayParentIds() (:173-199)
 * и дочерний поиск через findByJsonContains() (:261-265).
 */
final class JsonFieldRelationTest extends DeletionServiceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->mapJsonField(JsonArrayChild::class, 'rootIds');
    }

    #[Test]
    public function json_array_value_yields_parent_ids(): void
    {
        self::assertSame([1, 2, 3], $this->parentIdsFor([1, 2, 3]));
    }

    #[Test]
    public function json_string_value_is_decoded(): void
    {
        self::assertSame([4, 5], $this->parentIdsFor('[4,5]'));
    }

    /** json_last_error() !== JSON_ERROR_NONE → ранний возврат на :190. */
    #[Test]
    public function malformed_json_yields_no_group(): void
    {
        self::assertSame([], $this->analyze('{не json')->parents);
    }

    /** Не массив после декодирования → ранний возврат на :195. */
    #[Test]
    public function non_array_value_yields_no_group(): void
    {
        self::assertSame([], $this->analyze(42)->parents);
    }

    /** empty($jsonValue) на :186 отбрасывает и пустой массив, и null. */
    #[Test]
    public function empty_value_yields_no_group(): void
    {
        self::assertSame([], $this->analyze([])->parents);
    }

    /**
     * Фиксирует текущую форму результата. array_filter() на :198 сохраняет ключи, поэтому
     * при «дырах» во входном массиве ids перестаёт быть списком, и json_encode отдаёт
     * объект вместо массива — а README.md:57-60 кладёт этот DTO прямо в JsonResponse.
     *
     * Отдельной находки в REVIEW.md на это нет; тест лишь протоколирует поведение.
     */
    #[Test]
    public function ids_from_json_are_not_a_list_when_input_has_holes(): void
    {
        $ids = $this->parentIdsFor([10, 'x', null, 30]);

        self::assertSame([0 => 10, 1 => 'x', 3 => 30], $ids);
        self::assertFalse(array_is_list($ids));
        self::assertSame('{"0":10,"1":"x","3":30}', json_encode($ids));
    }

    /** Дочерняя сторона JSON-связи уходит в finder именованными аргументами (:261-265). */
    #[Test]
    public function json_children_are_looked_up_via_find_by_json_contains(): void
    {
        $this->mapEntities(JsonArrayChild::class);
        $this->finder->method('getId')->willReturn(100);
        $this->finder->expects(self::once())
            ->method('findByJsonContains')
            ->with(JsonArrayChild::class, 'rootIds', 100)
            ->willReturn([new JsonArrayChild()]);

        $relations = $this->service()->analyze(new RootEntity());

        self::assertCount(1, $relations->childrenDelete);
    }

    /** @return list<int|string> */
    private function parentIdsFor(mixed $value): array
    {
        return $this->analyze($value)->parents[0]->ids;
    }

    private function analyze(mixed $value): \Shared\Deletion\Dto\RelationsDto
    {
        $this->mapEntities();
        $this->finder->method('getId')->willReturn(1);

        $child = new JsonArrayChild();
        $child->rootIds = $value;

        return $this->service()->analyze($child);
    }
}
