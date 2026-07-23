<?php

declare(strict_types=1);

namespace Tests\Fixture;

use Shared\Deletion\Attribute\RelationTo;
use Shared\Deletion\Enum\RelationType;

/** Many-to-many как в README.md:85-92. Находка 4. */
#[RelationTo(
    entity: RootEntity::class,
    field: 'id',
    type: RelationType::BLOCKING,
    joinTable: 'advert_tag_relation',
    joinColumn: 'advert_id',
    inverseJoinColumn: 'advert_tag_id',
)]
final class JoinTableChild
{
    public int $id = 3;
}
