<?php

declare(strict_types=1);

namespace Tests\Fixture;

use Shared\Deletion\Attribute\RelationTo;
use Shared\Deletion\Enum\{DeletionCascade, RelationType};

/**
 * JSON-ветка. mixed — чтобы подставлять массив, JSON-строку и мусор.
 * cascade=DELETE_CHILD, иначе (REFERENCE + NONE) дочерняя сторона не порождает группу
 * вообще — см. матрицу в ChildrenMatrixTest.
 */
#[RelationTo(
    entity: RootEntity::class,
    field: 'rootIds',
    type: RelationType::REFERENCE,
    cascade: DeletionCascade::DELETE_CHILD,
)]
final class JsonArrayChild
{
    public mixed $rootIds = null;
}
