<?php

declare(strict_types=1);

namespace Tests\Fixture;

use Shared\Deletion\Attribute\RelationTo;
use Shared\Deletion\Enum\{DeletionCascade, RelationType};

/** Третий уровень для проверки порядка удаления. Находка 5. */
#[RelationTo(
    entity: ChainChild::class,
    field: 'parent',
    type: RelationType::REFERENCE,
    cascade: DeletionCascade::DELETE_CHILD,
)]
final class CascadeGrandchild
{
    public int $id = 200;
    public ?int $parent = 10;
}
