<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Shared\Deletion\Dto\DependentGroupDto;
use Tests\Fixture\{
    BlockingDeleteChild,
    BlockingDetachChild,
    BlockingNoneChild,
    ReferenceDeleteChild,
    ReferenceDetachChild,
    ReferenceNoneChild,
    RootEntity
};
use Tests\Support\DeletionServiceTestCase;

/**
 * Матрица RelationType × DeletionCascade на дочерних связях — 6 ячеек.
 *
 * Это та таблица, которая поймала бы находки 1 и 2. Каждая ячейка проверяется по трём
 * независимым граням отдельными тестами, чтобы падение называло сломанную грань:
 * вердикт canDelete, ведро (childrenDelete / childrenDetach / никуда) и флаг hard.
 */
final class ChildrenMatrixTest extends DeletionServiceTestCase
{
    /**
     * @return iterable<string, array{0: class-string, 1: bool, 2: string, 3: bool}>
     */
    public static function matrix(): iterable
    {
        yield 'BLOCKING + NONE → блокирует родителя и попадает в childrenDelete' => [
            BlockingNoneChild::class, false, 'childrenDelete', true,
        ];
        yield 'BLOCKING + DELETE_CHILD → блокирует родителя и попадает в childrenDelete' => [
            BlockingDeleteChild::class, false, 'childrenDelete', true,
        ];
        yield 'BLOCKING + DETACH_RELATIONS → НЕ блокирует родителя, попадает в childrenDetach' => [
            BlockingDetachChild::class, true, 'childrenDetach', true,
        ];
        yield 'REFERENCE + NONE → не блокирует и не попадает никуда' => [
            ReferenceNoneChild::class, true, 'none', false,
        ];
        yield 'REFERENCE + DELETE_CHILD → не блокирует, попадает в childrenDelete' => [
            ReferenceDeleteChild::class, true, 'childrenDelete', false,
        ];
        yield 'REFERENCE + DETACH_RELATIONS → не блокирует, попадает в childrenDetach' => [
            ReferenceDetachChild::class, true, 'childrenDetach', false,
        ];
    }

    #[Test]
    #[DataProvider('matrix')]
    public function canDelete_verdict_for_matrix_cell(string $childClass, bool $expected): void
    {
        self::assertSame($expected, $this->analyzeRoot($childClass)->canDelete);
    }

    #[Test]
    #[DataProvider('matrix')]
    public function bucket_placement_for_matrix_cell(string $childClass, bool $_, string $bucket): void
    {
        $relations = $this->analyzeRoot($childClass);

        $actual = match (true) {
            $relations->childrenDelete !== [] => 'childrenDelete',
            $relations->childrenDetach !== [] => 'childrenDetach',
            default => 'none',
        };

        self::assertSame($bucket, $actual);
    }

    /**
     * Только те ячейки, что вообще порождают группу: у REFERENCE + NONE флагу hard
     * негде появиться, и это уже зафиксировано в bucket_placement_for_matrix_cell.
     *
     * @return iterable<string, array{0: class-string, 1: bool, 2: string, 3: bool}>
     */
    public static function matrixCellsThatEmitGroups(): iterable
    {
        foreach (self::matrix() as $name => $case) {
            if ($case[2] !== 'none') {
                yield $name => $case;
            }
        }
    }

    #[Test]
    #[DataProvider('matrixCellsThatEmitGroups')]
    public function hard_flag_for_matrix_cell(string $childClass, bool $_, string $bucket, bool $hard): void
    {
        $relations = $this->analyzeRoot($childClass);
        $group = array_merge($relations->childrenDelete, $relations->childrenDetach)[0];

        self::assertInstanceOf(DependentGroupDto::class, $group);
        self::assertSame($hard, $group->hard);
    }

    /**
     * Фиксирует текущее поведение. Находка 2: докблок Enum/RelationType.php:9 и
     * README.md:24-25 утверждают ровно обратное — «OrderEntity можно удалить свободно».
     * Тест НЕ решает, кто прав: он записывает противоречие. См. REVIEW.md §5, вопрос 1.
     */
    #[Test]
    public function blocking_child_blocks_parent_deletion(): void
    {
        self::assertFalse(
            $this->analyzeRoot(BlockingNoneChild::class)->canDelete,
            'Реализация блокирует РОДИТЕЛЯ; спецификация в README обещает обратное.',
        );
    }

    /**
     * Находка 1: так быть не должно. Связь BLOCKING + cascade=NONE объявлена как
     * «удалять нельзя», но попадает в childrenDelete (DeletionService.php:288), откуда
     * DeletionOrchestrator::buildRecursive (:111) забирает её как цель удаления и не
     * может отличить от записи, приехавшей из DELETE_CHILD.
     *
     * Дефект держится при ОБЕИХ трактовках BLOCKING из находки 2, поэтому в отличие от
     * находки 2 здесь корректное поведение однозначно: причина отказа не является целью удаления.
     */
    #[Test]
    #[Group('defects')]
    public function blocking_none_child_must_not_be_a_deletion_target(): void
    {
        self::assertSame(
            [],
            $this->analyzeRoot(BlockingNoneChild::class)->childrenDelete,
            'BLOCKING + NONE — причина отказа, а не цель удаления; ведро childrenDelete '
            . 'потребляется оркестратором напрямую.',
        );
    }

    private function analyzeRoot(string $childClass): \Shared\Deletion\Dto\RelationsDto
    {
        $this->mapEntities($childClass);
        $this->finderReturnsOneChild(new $childClass());

        return $this->service()->analyze(new RootEntity());
    }
}
