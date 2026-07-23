<?php

declare(strict_types=1);

namespace Tests\Fixture;

use Shared\Deletion\Attribute\RelationTo;
use Shared\Deletion\Enum\{DeletionCascade, RelationType};

/** Ячейка матрицы RelationType × DeletionCascade: REFERENCE + DELETE_CHILD. */
#[RelationTo(
    entity: RootEntity::class,
    field: 'root',
    type: RelationType::REFERENCE,
    cascade: DeletionCascade::DELETE_CHILD,
)]
final class ReferenceDeleteChild
{
    public ?int $root = 7;
}
