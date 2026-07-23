<?php

declare(strict_types=1);

namespace Tests\Fixture;

/** Цель связей. Намеренно без #[RelationTo], чтобы parents-сторона не шумела в тестах на детей. */
final class RootEntity
{
    public int $id = 1;
}
