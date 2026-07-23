<?php

declare(strict_types=1);

namespace Tests\Fixture;

/**
 * Своих #[RelationTo] нет — только унаследованные от AnnotatedBase.
 * ReflectionClass::getAttributes() по цепочке предков не поднимается.
 */
final class SubclassOfAnnotated extends AnnotatedBase
{
}
