<?php

declare(strict_types=1);

namespace Tests\Fixture;

use Shared\Deletion\Attribute\RelationTo;
use Shared\Deletion\Enum\{DeletionCascade, RelationType};

/** Ячейка матрицы RelationType × DeletionCascade: REFERENCE + DETACH_RELATIONS. */
#[RelationTo(
    entity: RootEntity::class,
    field: 'root',
    type: RelationType::REFERENCE,
    cascade: DeletionCascade::DETACH_RELATIONS,
)]
final class ReferenceDetachChild
{
    public ?int $root = 7;
}
