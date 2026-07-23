<?php

declare(strict_types=1);

/*
 * |==========================================================================|
 * | ВНИМАНИЕ: ЭТО НЕ КОД КОМПАНИИ. ЭТО РЕКОНСТРУКЦИЯ.                        |
 * |==========================================================================|
 *
 * Настоящий класс Shared\Persistence\GenericReadRepository НЕ ВХОДИЛ в извлечённый
 * срез модуля (см. REVIEW.md §1.2). Данный файл — восстановленный по точкам вызова
 * контракт, и ничего больше:
 *
 *   - здесь только СИГНАТУРЫ, выведенные из того, как DeletionService вызывает методы;
 *   - поведения нет и не подразумевается: все тела бросают LogicException;
 *   - файл живёт под tests/ и подключается только через autoload-dev; в модуль он
 *     не попадает и в продакшн-автозагрузку не входит;
 *   - FQCN настоящий исключительно потому, что иначе не резолвится use-строка
 *     DeletionService.php:13. Совпадение имени НЕ означает совпадения реализации.
 *
 * Откуда взята каждая сигнатура (номера строк — DeletionService.php):
 *
 *   getId()              :213, :253, :267, :273, :279
 *                        Результат кладётся в массив $ids[] и передаётся как
 *                        аргумент value: в findByJsonContains(), который здесь
 *                        типизирован int|string. Отсюда int|string.
 *
 *   findByAssociation()  :277 — три позиционных аргумента, результат обходится foreach.
 *
 *   findByJsonContains() :261-265 — вызывается ИМЕНОВАННЫМИ аргументами
 *                        (entityClass:, field:, value:). Имена параметров здесь
 *                        несущие: переименование сломает вызов в модуле.
 *
 *   findByJoinTable()    :271 — пять позиционных аргументов, результат обходится foreach.
 *
 * Чего точки вызова НЕ говорят (зафиксировано как допущение, не как знание):
 *
 *   1. Может ли getId() вернуть null для неперсистентной сущности. Здесь объявлено
 *      int|string, потому что :265 подставляет результат в позицию int|string.
 *      Если в реальном классе тип nullable — тесты на JSON-ветку надо пересмотреть.
 *   2. Возвращают ли finder-методы array или ленивый итератор. Объявлено iterable —
 *      более слабое из двух: :266, :272, :278 делают только foreach.
 *   3. Есть ли у класса другие методы. Модуль их не вызывает, поэтому их здесь нет.
 */

namespace Shared\Persistence;

use LogicException;

/**
 * Реконструкция контракта по точкам вызова. Не final — тесты подменяют его через
 * PHPUnit createMock(), а DeletionService.php:23 требует именно этот конкретный тип.
 */
class GenericReadRepository
{
    public function getId(object $entity): int|string
    {
        throw new LogicException(self::message(__FUNCTION__));
    }

    /**
     * @param class-string $entityClass
     *
     * @return iterable<object>
     */
    public function findByAssociation(string $entityClass, string $field, object $parent): iterable
    {
        throw new LogicException(self::message(__FUNCTION__));
    }

    /**
     * Имена параметров совпадают с именованными аргументами на DeletionService.php:261-265.
     *
     * @param class-string $entityClass
     *
     * @return iterable<object>
     */
    public function findByJsonContains(string $entityClass, string $field, int|string $value): iterable
    {
        throw new LogicException(self::message(__FUNCTION__));
    }

    /**
     * @param class-string $entityClass
     *
     * @return iterable<object>
     */
    public function findByJoinTable(
        string $entityClass,
        string $joinTable,
        string $joinColumn,
        string $inverseJoinColumn,
        object $parent
    ): iterable {
        throw new LogicException(self::message(__FUNCTION__));
    }

    private static function message(string $method): string
    {
        return sprintf(
            '%s::%s() — заглушка без поведения. Настоящий класс не входил в срез модуля; '
            . 'в тестах его нужно подменять через createMock().',
            self::class,
            $method,
        );
    }
}
