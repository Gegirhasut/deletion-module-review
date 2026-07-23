<?php

declare(strict_types=1);

namespace Tests\Fixture;

use Shared\Deletion\Attribute\RelationTo;
use Shared\Deletion\Enum\{DeletionCascade, RelationType};

/**
 * Средний уровень цепочки R → C → G. REFERENCE + DELETE_CHILD, то есть hard=false:
 * именно поэтому canDelete(R) отвечает true, хотя ниже по цепочке живёт защищённый внук.
 */
#[RelationTo(
    entity: RootEntity::class,
    field: 'root',
    type: RelationType::REFERENCE,
    cascade: DeletionCascade::DELETE_CHILD,
)]
final class ChainChild
{
    public int $id = 10;
    public ?int $root = 1;
}
