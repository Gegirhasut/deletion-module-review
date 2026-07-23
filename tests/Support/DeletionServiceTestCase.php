<?php

declare(strict_types=1);

namespace Tests\Support;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\Mapping\ClassMetadataFactory;
use Doctrine\Persistence\Mapping\RuntimeReflectionService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shared\Deletion\DeletionService;
use Shared\Persistence\GenericReadRepository;

/**
 * Базовый класс: собирает DeletionService на трёх дублях, без БД и без бутстрапа ORM.
 *
 * ClassMetadata здесь НАСТОЯЩИЙ, а не мок. На doctrine/orm 2.x класс не final, конструктор
 * требует только имя сущности, а fieldMappings — публичный массив, который isJsonField()
 * (DeletionService.php:317-325) читает напрямую. Стена mock-ожиданий вместо этого была бы
 * нечитаемой и привязала бы тесты к внутренностям Doctrine.
 */
abstract class DeletionServiceTestCase extends TestCase
{
    protected EntityManagerInterface&MockObject $em;
    protected GenericReadRepository&MockObject $finder;

    /** Сколько раз ensureMap() ходил в фабрику метаданных — для проверки ленивости. */
    protected int $getAllMetadataCalls = 0;

    /** @var array<class-string, ClassMetadata> */
    private array $metadata = [];

    /** @var list<class-string> Классы, которые вернёт getAllMetadata() — вход ensureMap(). */
    private array $mapped = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->metadata = [];
        $this->mapped = [];
        $this->getAllMetadataCalls = 0;

        $this->finder = $this->createMock(GenericReadRepository::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->em->method('getClassMetadata')->willReturnCallback($this->metadataFor(...));
    }

    /**
     * Регистрирует классы, которые ensureMap() увидит через getAllMetadata().
     * Вызывать ДО service(). Тест регистрирует только то, что ему нужно, — поэтому
     * вёдра в результате никогда не бывают неоднозначными.
     *
     * @param class-string ...$classes
     */
    protected function mapEntities(string ...$classes): void
    {
        $this->mapped = array_values($classes);
    }

    /**
     * Помечает поле как json — ровно то единственное, что читает isJsonField()
     * (DeletionService.php:321-325). На ORM 2.x записи fieldMappings — обычные массивы.
     */
    protected function mapJsonField(string $class, string $field): void
    {
        $this->metadataFor($class)->fieldMappings[$field] = [
            'fieldName' => $field,
            'type' => 'json',
            'columnName' => $field,
        ];
    }

    protected function metadataFor(string $class): ClassMetadata
    {
        return $this->metadata[$class] ??= new ClassMetadata($class);
    }

    /**
     * Задаёт идентификатор сущности.
     *
     * Нужно для DeletionOrchestrator: :92 безусловно зовёт getIdentifierValues() у корня,
     * а свежий ClassMetadata на этом падает («Call to a member function getValue() on null»),
     * потому что reflFields пуст. Поэтому для сущностей, попадающих в buildRecursive как
     * $parent, метаданные приходится доводить до рабочего состояния целиком.
     *
     * Если свойства с таким именем у класса нет (случай «PK называется не id» из находки 6),
     * достаточно списка имён: :112 спрашивает только getIdentifier().
     */
    protected function withIdentifier(string $class, string $field = 'id'): void
    {
        $meta = $this->metadataFor($class);

        if (!property_exists($class, $field)) {
            $meta->identifier = [$field];

            return;
        }

        $reflection = new RuntimeReflectionService();
        $meta->initializeReflection($reflection);
        $meta->mapField(['fieldName' => $field, 'id' => true, 'type' => 'integer']);
        $meta->wakeupReflection($reflection);
    }

    protected function service(): DeletionService
    {
        $list = array_map($this->metadataFor(...), $this->mapped);

        $factory = $this->createMock(ClassMetadataFactory::class);
        $factory->method('getAllMetadata')->willReturnCallback(function () use ($list): array {
            ++$this->getAllMetadataCalls;

            return $list;
        });

        $this->em->method('getMetadataFactory')->willReturn($factory);

        return new DeletionService($this->em, $this->finder);
    }

    /**
     * Стандартная настройка finder-а: у родителя ровно один ребёнок с id 100.
     * getId() отдаёт 100 и для родителя, и для ребёнка — тестам матрицы этого хватает,
     * они смотрят на вёдра и флаги, а не на конкретные идентификаторы.
     */
    protected function finderReturnsOneChild(object $child, int $id = 100): void
    {
        $this->finder->method('getId')->willReturn($id);
        $this->finder->method('findByAssociation')->willReturn([$child]);
    }
}
