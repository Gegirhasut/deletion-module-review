<?php

declare(strict_types=1);

namespace Tests\Fixture;

use Shared\Deletion\Attribute\RelationTo;
use Shared\Deletion\Enum\{DeletionCascade, RelationType};

/** Повторяемый атрибут: два родителя, разные типы (README.md:110-121). */
#[RelationTo(entity: RootEntity::class, field: 'root', type: RelationType::BLOCKING, cascade: DeletionCascade::DELETE_CHILD)]
#[RelationTo(entity: SecondRootEntity::class, field: 'second', type: RelationType::REFERENCE)]
final class MultiRelationChild
{
    public ?int $root = 7;
    public ?int $second = 8;
}
