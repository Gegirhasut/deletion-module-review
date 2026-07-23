<?php

declare(strict_types=1);

namespace Tests\Fixture;

use Shared\Deletion\Attribute\RelationTo;
use Shared\Deletion\Enum\RelationType;

/**
 * Скалярный FK на родителя. Тип mixed — чтобы в тестах гонять через поле
 * null / 0 / '' и проверять guard на DeletionService.php:150.
 */
#[RelationTo(entity: RootEntity::class, field: 'root', type: RelationType::BLOCKING)]
final class ScalarFkChild
{
    public mixed $root = 7;
}
