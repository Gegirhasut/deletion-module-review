<?php

declare(strict_types=1);

namespace Tests\Fixture;

use Shared\Deletion\Attribute\RelationTo;
use Shared\Deletion\Enum\{DeletionCascade, RelationType};

/** Ячейка матрицы RelationType × DeletionCascade: BLOCKING + DETACH_RELATIONS. */
#[RelationTo(
    entity: RootEntity::class,
    field: 'root',
    type: RelationType::BLOCKING,
    cascade: DeletionCascade::DETACH_RELATIONS,
)]
final class BlockingDetachChild
{
    public ?int $root = 7;
}
