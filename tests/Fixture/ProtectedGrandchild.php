<?php

declare(strict_types=1);

namespace Tests\Fixture;

use Shared\Deletion\Attribute\RelationTo;
use Shared\Deletion\Enum\{DeletionCascade, RelationType};

/** Внук, помеченный как неудаляемый: BLOCKING + NONE. Находка 1. */
#[RelationTo(
    entity: ChainChild::class,
    field: 'parent',
    type: RelationType::BLOCKING,
    cascade: DeletionCascade::NONE,
)]
final class ProtectedGrandchild
{
    public int $id = 100;
    public ?int $parent = 10;
}
