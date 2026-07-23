<?php

declare(strict_types=1);

namespace Tests\Fixture;

use Shared\Deletion\Attribute\RelationTo;
use Shared\Deletion\Enum\RelationType;

/** Типизированное свойство без инициализации — второй режим отказа находки 3. */
#[RelationTo(entity: RootEntity::class, field: 'root', type: RelationType::REFERENCE)]
final class UninitializedFkChild
{
    public int $root; // намеренно не инициализировано
}
