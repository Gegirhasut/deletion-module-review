<?php

declare(strict_types=1);

namespace Tests\Fixture;

use Shared\Deletion\Attribute\RelationTo;
use Shared\Deletion\Enum\{DeletionCascade, RelationType};

/** Ячейка матрицы RelationType × DeletionCascade: BLOCKING + NONE. */
#[RelationTo(
    entity: RootEntity::class,
    field: 'root',
    type: RelationType::BLOCKING,
    cascade: DeletionCascade::NONE,
)]
final class BlockingNoneChild
{
    public ?int $root = 7;
}
