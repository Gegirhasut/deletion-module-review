<?php

declare(strict_types=1);

namespace Tests\Fixture;

use Shared\Deletion\Attribute\RelationTo;
use Shared\Deletion\Enum\RelationType;

/**
 * Разметка ровно как в README.md:18-22: field указывает на поле-АССОЦИАЦИЮ,
 * а не на скалярный столбец. Находка 3.
 */
#[RelationTo(entity: RootEntity::class, field: 'root', type: RelationType::BLOCKING)]
final class AssociationChild
{
    public function __construct(public RootEntity $root = new RootEntity())
    {
    }
}
