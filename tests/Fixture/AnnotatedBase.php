<?php

declare(strict_types=1);

namespace Tests\Fixture;

use Shared\Deletion\Attribute\RelationTo;
use Shared\Deletion\Enum\RelationType;

/** Базовый класс со связью. Наследник её теряет — находка 7. */
#[RelationTo(entity: RootEntity::class, field: 'root', type: RelationType::BLOCKING)]
class AnnotatedBase
{
    public ?int $root = 7;
}
