# Code review: модуль `Shared\Deletion`

## 1. Резюме

Модуль даёт декларативное описание графа зависимостей через атрибуты и строит план каскадного
удаления. Идея верная, разделение «анализатор / исполнитель» верное, DTO-слой аккуратный;
реализация — нет: 4 блокера, все в основных путях, не в углах.

1. **Связь, объявленная как запрещающая удаление, приводит к удалению.** `BLOCKING` + `NONE`
   попадает в `childrenDelete` (`DeletionService.php:288`), которое оркестратор удаляет
   (`DeletionOrchestrator.php:111`). Вердикт `canDelete` проверяется только для корня, поэтому
   защищённые внуки удаляются даже у корректного вызывающего.
2. **Спецификация и реализация определяют `BLOCKING` взаимно обратно** — докблок енама и README
   против кода; из-за инверсии блок `DeletionService.php:47-52` недостижим.
3. **Основной пример README падает с `TypeError`**: `field: 'order'` над `private OrderEntity
   $order` уходит в `getScalarFkValue()` с типом `int|string|null` (`DeletionService.php:227`).

Каркас переживает исправления: чинить нужно реализацию, а не переписывать модуль.

## 1.1. Карта находок

| № | Severity | Раздел | Название | Файл:строка |
|---|---|---|---|---|
| 1 | BLOCKER | Корректность | `BLOCKING` + `cascade=NONE` приводит к удалению ребёнка | `DeletionService.php:288` → `Service/DeletionOrchestrator.php:111` |
| 2 | BLOCKER | Корректность | Спецификация и реализация определяют `BLOCKING` взаимно обратно | `Enum/RelationType.php:9`, `README.md:24-25`, `DeletionService.php:120-122` |
| 3 | BLOCKER | Корректность | `getScalarFkValue()` возвращает объект при типе `int\|string\|null` | `DeletionService.php:227-239` |
| 4 | BLOCKER | Корректность | `getJoinTableParentIds()` строит DQL по имени таблицы и столбцов | `DeletionService.php:211-225` |
| 5 | MAJOR | Корректность | Нет защиты от циклов и глубины; план не топологически упорядочен | `Service/DeletionOrchestrator.php:89-141,76-79` |
| 6 | MAJOR | Корректность | Повсеместное допущение о единственном идентификаторе с именем `id` | `Service/DeletionOrchestrator.php:112-116,130-131,92-93` |
| 7 | MAJOR | Корректность | Класс объекта берётся без нормализации прокси | `DeletionService.php:113,252`, `Service/DeletionOrchestrator.php:92` |
| 8 | MAJOR | Корректность | Границы транзакции: план снаружи, `after*` до коммита, нет `clear()` | `Service/DeletionOrchestrator.php:30-33,40,49,58` |
| 9 | MAJOR | Корректность | `DETACH_RELATIONS` без `joinTable` — молчаливый no-op, роняющий транзакцию | `DeletionService.php:286-287`, `Service/DeletionOrchestrator.php:95` |
| 10 | MAJOR | Архитектура | `execute()` игнорирует собственный вердикт `canDelete` | `Service/DeletionOrchestrator.php:28-33` |
| 11 | MAJOR | Архитектура | `supports()` не вызывается; «middleware» не является middleware | `Middleware/DeletionMiddlewareInterface.php:9`, `Service/DeletionOrchestrator.php:156-166` |
| 12 | MAJOR | Архитектура | Зависимость от конкретного `GenericReadRepository` — модуль нетестируем | `DeletionService.php:13,23` |
| 13 | MINOR | Архитектура | Внутренние 7-кортежи в публичном API; `CanDeleteDto` смешивает роли | `DeletionService.php:306-311,34` |
| 14 | MAJOR | Производительность | `O(D × R)` запросов и полная гидратация ради идентификаторов | `Service/DeletionOrchestrator.php:124-139`, `DeletionService.php:266-280,82-101` |
| 15 | MINOR | Конвенции | README описывает несуществующий атрибут; сравнение каскада по литералу | `README.md:65-72`, `Service/DeletionOrchestrator.php:95`, `DeletionService.php:323-325` |
| 16 | MAJOR | Эксплуатация | `notify()` молча проглатывает любое исключение из middleware | `Service/DeletionOrchestrator.php:156-166` |
| 17 | MINOR | Эксплуатация | По логам нельзя разобрать инцидент: `dryRun` неотличим, нет id операции | `Middleware/LoggingDeletionMiddleware.php:20-48` |

---

## 1.2. Контекст и допущения

- **В срезе:** 11 PHP-файлов и `README.md`; все прочитаны целиком, `php -l` проходит (PHP 8.4.21).
- **Отсутствуют:** `composer.json`, конфигурация DI, тесты, консольная команда `deletion:check`
  (описана в `README.md:124-142`), класс `Shared\Persistence\GenericReadRepository`.
- **`GenericReadRepository`** ревьюится строго по требованиям точек вызова (`DeletionService.php:213,253,261,271,277`);
  о его внутренностях предположений не делается.
- **Допущение A — версия Doctrine ORM.** Определить не удалось. Исходим из **ORM 2.x**, потому что
  `DeletionService.php:317,321` обращается к `fieldMappings` как к массиву; в ORM 3.x это объекты
  `FieldMapping`, и находка 15 становится строже, а не мягче. Решения в находках 7, 14 и 15
  намеренно работают на обеих ветках.
- **Допущение B — прокси.** Исходим из того, что в `analyze()` может попасть Doctrine-прокси
  (`getReference()`, ленивая навигация). Достижимость находки 7 зависит от стиля вызова — оговорено
  внутри находки.
- **Что подтверждено исполняемо.** Часть 2 содержит юнит-тесты `DeletionService` и планировщика.
  Воспроизводятся тестами: находки 1, 3, 4, 6, 9, наследственная половина 7 и порядок удаления
  из 5. Не покрыты: находка 8 целиком (транзакции — вне периметра `DeletionService`),
  прокси-половина 7, циклы из 5 (у `buildRecursive` нет множества посещённых — тест увёл бы прогон
  в бесконечную рекурсию) и нарушение FK-констрейнта из 5 и 9 (требует схемы в БД).

---

## 2. Что сделано хорошо

**Направление атрибута выбрано верно.** `RelationTo` объявляется на «ребёнке» и указывает на
«родителя» (`Attribute/RelationTo.php:10-21`) — единственный работоспособный вариант: иначе каждый
новый модуль, ссылающийся на `User`, требовал бы правки `User`. Здесь модуль приносит свои связи с
собой, а карта строится инверсией на старте (`DeletionService.php:91-99`): OCP получен бесплатно,
без реестров и конфигурации.

**Два ортогональных измерения вместо одного флага.** `RelationType` и `DeletionCascade` разведены в
разные енама; один `bool $hard` не выразил бы `DETACH_RELATIONS`. Комментарий
`DeletionService.php:63-64` показывает, что неочевидный случай — «detach не должен блокировать
родителя» — продуман сознательно, и вывод правильный.

**План как данные.** `getOrderedPlan()` (`DeletionOrchestrator.php:190-195`) и `$dryRun` (`:28`)
позволяют показать последствия до их наступления. То, что план — это `OrderedPlanDto`, а не
побочный эффект, даёт опору и для подтверждения в UI, и для авторизации по всему набору жертв (4.5).

**Дедупликация id через ключи массива.** `$deleteMap[$class]['ids'][(string) $cid] = $cid`
(`DeletionOrchestrator.php:120`): приведение к строке снимает смешивание `int` и `string`, которые
`finder->getId()` возвращает вперемешку.

**Правильный инструмент для join-таблиц в оркестраторе.** `detachJoinRow()` берёт
`$this->em->getConnection()` (`DeletionOrchestrator.php:170`) — join-таблица не сущность, и DBAL
здесь единственный корректный путь. Это делает находку 4 тем обиднее.

---

## 3. Находки

### Корректность

#### 1. [BLOCKER] Связь `BLOCKING` с `cascade=NONE` приводит к удалению ребёнка

`Deletion/DeletionService.php:288` → `Deletion/Service/DeletionOrchestrator.php:111`

**Проблема.** `findChildrenByAttributes()` кладёт `BLOCKING`-детей в `childrenDelete` через ветку
`elseif ($hard)` (`:288-292`) — по комментарию авторов, чтобы они попали в проверку `:57-62`; ведро
переиспользовано как канал для двух разных смыслов. `DeletionOrchestrator::buildRecursive()` читает
то же поле (`:111`) и не может отличить запись из `DELETE_CHILD` от записи из `elseif ($hard)` —
обе уезжают в `deleteMap` (`:120`) и затем в `deleteByIds()`.

**Последствие.** Вердикт `canDelete` вычисляется только для корня, а `buildRecursive` спускается по
уровням (`:137-138`) и потребляет `childrenDelete` каждого потомка. Пусть `R` → `C`
(`REFERENCE, DELETE_CHILD`, то есть `hard=false`, `canDelete(R) === true`), а у `C` есть `G`
(`BLOCKING, NONE`): вызывающий честно проверяет `canDelete($R)`, получает `true`, вызывает
`execute()` — и `G` удаляется. Счета, помеченные как неудаляемые, исчезают в успешно закоммиченной
транзакции при полностью корректном коде вызывающего. Цепочка воспроизведена тестом:
`canDelete($R)` возвращает `true`, и `G` при этом оказывается в `$plan->delete`. Сам `DELETE`
тестом не исполнялся — он следует из `deleteByIds()` (`:180-188`), читающего ровно это поле.

**Решение.** Отдельное ведро для причин блокировки: исполнитель тогда физически не может их
удалить.

```php
// DeletionService::findChildrenByAttributes(), заменить :283-293
if ($ids === []) {
    continue;
}
$group = new DependentGroupDto($childClass, $hard, count($ids), $ids, $field);

if ($isDelete) {
    $childrenDelete[] = $group;
} elseif ($isDetach) {
    $childrenDetach[] = $group;
} elseif ($hard) {
    $blockers[] = $group; // НЕ в childrenDelete: причина отказа, а не цель удаления
}
```

`RelationsDto` получает четвёртый массив `$blockers`, `analyze()` считает
`canDelete: !$hasHardParent && $blockers === []`, а `buildRecursive()` обязан проверять
`$childRelations->canDelete` на каждом уровне, а не только на корне.

#### 2. [BLOCKER] Спецификация и реализация определяют `BLOCKING` взаимно обратно

`Deletion/Enum/RelationType.php:9`, `README.md:24-25,95,104`, `DeletionService.php:120-122,288-292`

**Проблема.** Четыре утверждения о смысле `BLOCKING` делятся на две несовместимые группы.

| Источник | Что утверждает |
|---|---|
| `Enum/RelationType.php:9` | блокируется удаление **текущей** сущности (аннотированного ребёнка) |
| `README.md:24-25,95,104` | «OrderItemEntity нельзя удалить… **OrderEntity можно удалить свободно**» |
| `DeletionService.php:120-122` | «BLOCKING… означает "нельзя удалить **РОДИТЕЛЯ**"» + `$isBlocking = false` |
| `DeletionService.php:288-292` | реализует комментарий: `BLOCKING`-ребёнок блокирует родителя |

Докблок енама и README согласованы друг с другом; код делает противоположное. Прямое следствие —
недостижимый код: `$isBlocking = false` (`:122`) уезжает в поле `$hard` (`:131,142,153`), поэтому
цикл `:47-52` не может выставить `$hasHardParent = true` ни при каких данных, и половина условия
в `:70` мертва.

**Последствие.** Если авторитетна спецификация — модуль запрещает удалять родителей, которых
разрешено удалять, и продакшн упирается в ложные отказы «нельзя удалить заказ, у него есть
позиции». Если авторитетна реализация — README и докблок вводят в заблуждение каждого, кто
размечает новую сущность, и связи расставляются наоборот; этот сценарий хуже, он тихий.

**Решение.** Применить ровно одну правку.

*Вариант A — авторитетна реализация:*

```php
// Enum/RelationType.php:9
/** Наличие детей запрещает удалить РОДИТЕЛЯ ($entity). Аналог ON DELETE RESTRICT. */
case BLOCKING = 'blocking';
```
плюс переписать `README.md:24,95,104` в обратную сторону.

*Вариант B — авторитетна спецификация:*

```php
// DeletionService.php:122, вместо $isBlocking = false;
$isBlocking = $attribute->type === RelationType::BLOCKING;
```
плюс удалить ветку `elseif ($hard)` в `:288-292` целиком (блокировка считается только по `$parents`,
цикл `:47-52` оживает).

**Рекомендация, не вердикт.** Ставлю на вариант A: реализация совпадает с `ON DELETE RESTRICT` —
отраслевым стандартом смысла «блокирующая связь», тогда как вариант B описывает связь, защищающую
саму себя (обычно это называют `immutable`/`protected`). Сильнейший аргумент против моей же ставки:
README отрицает вариант A дважды и независимо — `README.md:25` («OrderEntity можно удалить
свободно») и `README.md:104` («При проверке Advert — система НЕ блокирует удаление родителя»). Это
связная спецификация в двух разных разделах, а не одна протухшая фраза, и гипотеза «документация
отстала» от этого заметно слабеет. Мой аргумент — от конвенции, ваш — от домена; см. вопрос 1.

#### 3. [BLOCKER] `getScalarFkValue()` возвращает объект ассоциации при объявленном `int|string|null`

`Deletion/DeletionService.php:227-239`, вызов из `:149`

**Проблема.** README предлагает `field: 'order'` над `private OrderEntity $order`
(`README.md:18,22`); метод объявлен как `getScalarFkValue(...): int|string|null` (`:227`) и
возвращает сырое `ReflectionProperty::getValue()` (`:238`). Путь безальтернативен: `isJsonField()`
для ассоциации даёт `false` (её нет в `fieldMappings`), `joinTable` равен `null`, остаётся ветка
`else` (`:148`). Комментарий `// @var int|string|null $value` (`:237`) — не аннотация, а допущение,
которое README нарушает. В рантайме PHP нормализует порядок в union, поэтому в сообщении будет
`string|int|null`, а не объявленный на `:227` `int|string|null`: искать в логах нужно по
нормализованной форме.

**Последствие.** `canDelete()` падает с `TypeError` на сущности, размеченной ровно как в README §1 —
на первом же скопированном примере. Ошибка возникает при проверке **ребёнка**, поэтому пример
контроллера из README §2 (`canDelete($order)`) её не ловит и дефект доживает до продакшна: 500 на
кнопке «удалить позицию заказа». Второй режим отказа того же метода — неинициализированное
типизированное свойство: проверяется только `hasProperty()` (`:232`), и `getValue()` бросает
`Error`.

**Решение.** Метаданные вместо голой рефлексии; заодно исчезает `Error`, так как Doctrine
оборачивает типизированные свойства в `TypedNoDefaultReflectionProperty`, возвращающий `null`.

```php
private function getScalarFkValue(object $object, string $field): int|string|null
{
    $meta = $this->getEntityMetadata($this->realClass($object)); // realClass() — находка 7

    if ($meta->hasAssociation($field)) {
        $related = $meta->getFieldValue($object, $field);

        return $related === null ? null : $this->finder->getId($related);
    }
    $value = $meta->hasField($field) ? $meta->getFieldValue($object, $field) : null;

    return is_int($value) || is_string($value) ? $value : null;
}
```

#### 4. [BLOCKER] `getJoinTableParentIds()` строит DQL по имени таблицы и столбцов

`Deletion/DeletionService.php:211-225`, достижимо из `:138` для каждого потомка

**Проблема.** `$qb = $this->em->createQueryBuilder()` (`:215`) — это `Doctrine\ORM\QueryBuilder`,
то есть DQL; подтверждается независимо строкой `:222` (`getQuery()->getArrayResult()` есть только у
ORM-билдера). В `from()` передаётся имя таблицы, в `select()`/`where()` — имена столбцов
(`:216-218`), тогда как DQL оперирует FQCN и именами полей, а join-таблица не является сущностью и
никогда не будет разрешена `ClassMetadataFactory`.

**Последствие.** Весь сценарий many-to-many из README §4 не работает: первый вызов
`canDelete($advertTag)` даёт `QueryException`: `[Semantical Error] ... Class 'advert_tag_relation'
is not defined`. До обращения к БД дело не доходит — в момент броска
`$connection->isConnected() === false`. Целый раздел документации описывает нерабочий код. Радиус при этом шире, чем «`canDelete()` на m2m-сущности»:
`buildRecursive()` вызывает `analyze($child)` для каждого потомка (`DeletionOrchestrator.php:137`),
а `analyze()` всегда запускает `findParentsByAttributes()` (`:42`), который упирается в сломанную
ветку `:138`. То есть любой `execute()`, в поддереве которого есть сущность с m2m-`RelationTo`,
падает на этапе планирования — до того, как транзакция вообще откроется.

**Решение.** DBAL — как уже сделано в `DeletionOrchestrator.php:170`.

```php
private function getJoinTableParentIds(object $object, string $joinTable, string $joinColumn, string $inverseJoinColumn): array
{
    $conn = $this->em->getConnection();
    $sql = sprintf(
        'SELECT %s FROM %s WHERE %s = :objectId',
        $conn->quoteIdentifier($joinColumn),
        $conn->quoteIdentifier($joinTable),
        $conn->quoteIdentifier($inverseJoinColumn),
    );

    return $conn->fetchFirstColumn($sql, ['objectId' => $this->finder->getId($object)]);
}
```

#### 5. [MAJOR] Нет защиты от циклов и глубины; план не топологически упорядочен

`Deletion/Service/DeletionOrchestrator.php:89-141`, порядок формируется в `:76-79`

**Проблема.** `buildRecursive()` вызывает себя (`:138`) без множества посещённых и без лимита
глубины: самоссылка (`CategoryEntity.parent`) или цикл A→B→A даёт бесконечную рекурсию, а
дедупликация в `:120` на решение спускаться не влияет. Отдельно: несмотря на имена
`buildOrderedPlan()`/`OrderedPlanDto`, кода упорядочивания нет — порядок `$delete` равен порядку
вставки в `$deleteMap` (`:76-79`), то есть обнаружения сверху вниз, а FK требуют обратного.

**Последствие.** Цикл — исчерпание памяти воркера или `Maximum function nesting level`. Порядок:
для цепочки `Order → OrderItem → OrderItemOption` план выйдет как
`[OrderItem, OrderItemOption]`, и первый же `DELETE FROM order_item` нарушит FK
`order_item_option.order_item_id` (Doctrine по умолчанию генерирует FK без `ON DELETE CASCADE`).
Это шумно и безопасно — откат всей транзакции, 500 на удалении заказа без обходного пути.

**Про доказуемость.** Порядок предъявлен исполняемо: на цепочке из трёх уровней `getOrderedPlan()`
выдаёт `[ChainChild, CascadeGrandchild]` — родителя раньше ребёнка. Само нарушение FK-констрейнта
тестом не воспроизведено: для этого нужна схема в БД, а в README примеров глубины ≥ 3 нет (на
глубине 2 порядок случайно верен — корень удаляется вне цикла, `:53-57`). Половина про циклы не
покрыта сознательно: у `buildRecursive` нет множества посещённых, поэтому фикстура с самоссылкой не
дала бы читаемого падения, а увела бы прогон в бесконечную рекурсию. Отсюда MAJOR, а не BLOCKER:
неверный порядок наблюдается в тесте, нарушение FK-констрейнта — по-прежнему вывод из схемы, а не
воспроизведённый отказ.

**Решение.** Циклы — минимальный диф в `buildRecursive()`:

```php
private function buildRecursive(object $parent, RelationsDto $relations, array &$deleteMap, array &$detach, array &$visited = [], int $depth = 0): void
{
    if ($depth > self::MAX_DEPTH) {
        throw new DeletionDepthExceededException($parent::class, self::MAX_DEPTH);
    }
    // ...
    foreach ($group->ids as $cid) {
        $key = $group->childClass . '#' . $cid;
        if (isset($visited[$key])) {
            continue;
        }
        $visited[$key] = true;
        // ... существующая загрузка ребёнка и рекурсия, с $visited и $depth + 1
    }
}
```

Порядок требует смены структуры: `$deleteMap` (map по классу) не выражает post-order, если класс
встречается на разных уровнях. `OrderedPlanDto::$delete` должен стать списком, а запись уровня —
добавляться **после** возврата из рекурсии (`:118-121` уезжает ниже `:138`).

#### 6. [MAJOR] Повсеместное допущение о единственном идентификаторе с именем `id`

`Deletion/Service/DeletionOrchestrator.php:112-116,130-131,180-188` и `:92-93`

**Проблема.** `:112-116`: если идентификатор назван не `id`, `$idField` подменяется на
`$group->field`. Но `$group->ids` — это идентификаторы детей (`DeletionService.php:266-280`), а
`$group->field` — имя поля ребёнка, ссылающегося на **родителя** (`RelationTo.php:14`). Отдельно
`:92-93`: для составного ключа берётся произвольная первая компонента, а `?? null` превращает её
отсутствие в `NULL`.

**Последствие.** Для сущности с `uuid` в качестве первичного ключа `deleteByIds()` строит
`DELETE FROM child WHERE child.<fk_to_parent> IN (<child ids>)` — сравнение значений из разных
доменов: либо ноль удалённых строк, либо удаление чужих при пересечении диапазонов. `WHERE
join_column = NULL` в `detachJoinRow()` не истинно ни для одной строки. В обоих случаях транзакция
коммитится, лог рапортует `Deleted children`, а данные на месте — расхождение всплывёт через недели.
Хуже того, фолбэк `findOneBy([$group->field => $cid])` (`:130-131`) ищет ребёнка, у которого FK на
родителя равен идентификатору **другого** ребёнка: при пересечении диапазонов он вернёт постороннюю,
вполне реальную сущность, которая тут же уходит в `analyze()` и `buildRecursive()` (`:137-138`) и
затягивает в план всё своё поддерево. Для класса с PK не по имени `id` отказ — это не «ничего не
удалилось», а «удалились чужие строки вместе со своими потомками».

**Решение.** Ограничение сделать явным и падать громко там, где оно не выполняется.

```php
private function identifierFieldOf(string $class): string
{
    $fields = $this->em->getClassMetadata($class)->getIdentifierFieldNames();

    if (count($fields) !== 1) {
        throw new UnsupportedIdentifierException(sprintf(
            'Entity %s has a composite identifier (%s); only single-column ones are supported.',
            $class, implode(', ', $fields),
        ));
    }

    return $fields[0];
}
```

Далее `deleteByIds($class, $ids, $this->identifierFieldOf($class))`; аналогично для `$parentId` в
`:92-93`. Поддержка составных ключей — отдельная задача; до неё честнее отказать, чем удалить не то.

#### 7. [MAJOR] Класс объекта берётся без нормализации прокси

`Deletion/DeletionService.php:113,175,229,252`, `Deletion/Service/DeletionOrchestrator.php:36,92`

**Проблема.** `ReflectionClass::getAttributes()` (`:117`) не поднимается по цепочке наследования,
поэтому любой наследник размеченной сущности теряет все её связи: `findParentsByAttributes()`
возвращает пустой массив, а `?? []` на `:254` тихо подставляет пустоту вместо правил. Тот же
механизм бьёт по Doctrine-прокси: класс берётся как `$object::class` (`:113`, `:252`), для прокси
это `Proxies\__CG__\…` — снова наследник, снова ни атрибутов, ни ключа в карте.

**Последствие.** Наследование доказано исполняемо: `analyze()` над наследником размеченного класса
возвращает `parents === []`. Для single/joined table inheritance это значит, что модуль молча
выключается на целой ветке иерархии — без ошибки, просто «связей нет». Прокси-половина зависит от
стиля вызова и тестами не покрыта: через `MapEntity` приходит реальная сущность, через ленивую
навигацию (`$order->getCustomer()`) — прокси, и тогда `canDelete()` ответит «можно» сущности с
блокирующими зависимостями. Гарантии в коде нет ни в том, ни в другом случае.

**Решение.** Нормализация в одной точке. `ClassMetadata::getName()` даёт реальный класс и для
прокси ORM 2.x, и для lazy ghost ORM 3.x (канонический эквивалент для ORM 2 —
`Doctrine\Common\Util\ClassUtils::getClass()`).

```php
private function realClass(object $object): string
{
    return $this->em->getClassMetadata($object::class)->getName();
}
// для наследования — читать атрибуты по цепочке предков:
for ($rc = new ReflectionClass($class); $rc !== false; $rc = $rc->getParentClass()) {
    foreach ($rc->getAttributes(RelationTo::class) as $ref) {
        $attributes[] = $ref->newInstance();
    }
}
```

#### 8. [MAJOR] Границы транзакции: план строится снаружи, `after*` срабатывают до коммита, identity map не сбрасывается

`Deletion/Service/DeletionOrchestrator.php:30-33,40,49,58,55-56`

**Проблема.** Три дефекта одной природы. План строится до `wrapInTransaction` (`:30-31` против
`:33`), корень не блокируется — `LockMode` в модуле не используется нигде. Хуки `after*`
(`:40,49,58`) находятся внутри замыкания и отрабатывают, когда транзакция ещё может откатиться.
DQL `DELETE` идёт мимо UnitOfWork, а загруженные в `:129` сущности остаются managed.

**Последствие.** Пользователь A видит в плане «будет удалено 3 заказа», B создаёт четвёртый, A
подтверждает — четвёртый остаётся с внешним ключом на несуществующего клиента. Middleware,
отправляющее `CustomerDeleted` в очередь из `beforeDeleteRoot`, сделает это до коммита: транзакция
откатывается, клиент жив, а потребитель уже вычистил его из индекса и биллинга. Любой код после
`execute()` в том же запросе увидит удалённые сущности живыми.

**Решение.** Чтение и решение — внутрь транзакции под блокировкой, внешние эффекты — после коммита.

```php
public function execute(object $root, DeletionOptions $options = new DeletionOptions()): DeletionReport
{
    $report = $this->em->wrapInTransaction(function () use ($root, $options): DeletionReport {
        $this->em->lock($root, LockMode::PESSIMISTIC_WRITE);
        $relations = $this->analyzer->analyze($root); // после блокировки: план не устареет

        if (!$relations->canDelete && !$options->ignoreBlockers) {
            throw new DeletionBlockedException($root::class, $relations->blockers);
        }
        // ... detach / delete children / remove root; хуки before* остаются здесь
        $this->em->clear(); // DQL DELETE прошёл мимо UoW — identity map протухла

        return new DeletionReport(/* ... */);
    });
    $this->dispatchAfterCommit($report); // after*-хуки только здесь

    return $report;
}
```

`wrapInTransaction()` возвращает значение колбэка; `em->lock()` внутри неё легален.

#### 9. [MAJOR] `DETACH_RELATIONS` без `joinTable` — молчаливый no-op, роняющий транзакцию

`Deletion/DeletionService.php:286-287`, `Deletion/Service/DeletionOrchestrator.php:95`,
`Deletion/Enum/DeletionCascade.php:11`, `Deletion/Attribute/RelationTo.php:17-20`

**Проблема.** `findChildrenByAttributes()` (`:286-287`) кладёт в `childrenDetach` любую связь с
`cascade=DETACH_RELATIONS`, независимо от того, задан ли `joinTable`. `buildRecursive()` действует
только при `$joinTable && $cascade === 'detach'` (`:95`). `RelationTo` (`:17-20`) не валидирует, что
detach требует тройку join-параметров: `cascade` и три `?string` независимы и все имеют умолчания.

**Последствие.** Detach-связь на обычном FK попадает в `canDelete` как зависимость, а в план — уже
никогда, и FK ребёнка не зануляется. Так как detach по осознанному решению авторов
(`DeletionService.php:63-64`) не блокирует родителя, `canDelete` возвращает `true`,
`em->remove($root)` + `flush()` (`DeletionOrchestrator.php:55-56`) упирается в FK-констрейнт, и вся
транзакция откатывается. Разметка выглядит корректной, ошибка приходит из СУБД, а не из модуля.
Что запись не попадает в план — наблюдение, а не вывод: `$plan->detach === []` при непустом
`childrenDetach`.

**Решение.** Запретить нерабочую комбинацию на входе, в конструкторе атрибута:

```php
public function __construct(/* ... */)
{
    if ($cascade === DeletionCascade::DETACH_RELATIONS
        && ($joinTable === null || $joinColumn === null || $inverseJoinColumn === null)) {
        throw new InvalidArgumentException(sprintf(
            'RelationTo(%s): DETACH_RELATIONS requires joinTable, joinColumn and inverseJoinColumn.',
            $entity,
        ));
    }
}
```

Если detach по скалярному FK нужен как сценарий — это отдельная ветка в оркестраторе, зануляющая FK
через DQL `UPDATE ... SET e.<field> = NULL WHERE e.<id> IN (:ids)`, а не расширение текущей.

### Архитектура

#### 10. [MAJOR] `execute()` игнорирует собственный вердикт `canDelete`

`Deletion/Service/DeletionOrchestrator.php:28-33`

**Проблема.** `execute()` получает `RelationsDto` с полем `canDelete` (`RelationsDto.php:19`) и не
читает его — из `$relations` берутся только связи (`:31`). Задокументированный контракт при этом не
нарушен: README вообще не упоминает `DeletionOrchestrator`, так что ответственность не назначена
никому — отсюда MAJOR, а не BLOCKER. Дизайн-проблема в том, что сигнатура
`execute(object $root, bool $dryRun = false)` не различает «удали, если можно» и «удали, я знаю про
блокировки».

**Последствие.** Каждый вызывающий обязан помнить неписаное правило «сначала `canDelete()`» — оно
нарушится при первом рефакторинге или в фоновом обработчике; сама проверка при этом небезопасна,
между ней и `execute()` проходит время (находка 8). Админский «удалить принудительно» приходится
делать в обход модуля, и он не попадает в аудит.

**Решение.** Безопасное поведение — по умолчанию, обход — явный и видимый в логах.

```php
final readonly class DeletionOptions
{
    public function __construct(
        public bool $dryRun = false,
        public bool $ignoreBlockers = false, // осознанный обход, пишется с уровнем warning
        public ?string $reason = null        // обязателен при ignoreBlockers, идёт в аудит
    ) {}
}
```

`DeletionBlockedException` несёт `list<DependentGroupDto> $blockers`, так что HTTP-слой отдаёт 409
с внятным телом, не повторяя анализ. Применение — в примере находки 8.

#### 11. [MAJOR] `supports()` не вызывается; «middleware» не является middleware

`Deletion/Middleware/DeletionMiddlewareInterface.php:9`, `Deletion/Service/DeletionOrchestrator.php:156-166`

**Проблема.** `supports(string $entityClass): bool` (`:9`) — часть публичного контракта, но не
вызывается нигде: `notify()` получает только шесть имён хуков. Единственная реализация возвращает
`true` (`LoggingDeletionMiddleware.php:15-18`), поэтому дефект не проявляется, но middleware,
ограничивающее себя одним классом, будет вызвано для всех. Рядом — противоречие: интерфейс требует
все шесть методов, а `notify()` проверяет `method_exists()` (`:159`), что имеет смысл только для
необязательных. Название обещает конвейер, но `$next` нет, все методы возвращают `void`, а
единственный способ вмешаться — исключение — заблокирован пустым `catch` (находка 16).

**Последствие.** Реализатор пишет `supports()` с реальной логикой, тестирует его отдельно и молча
получает вызовы для всех сущностей. Задачи «не давать удалять сущность в определённом статусе» или
«снять снапшот и восстановить при ошибке» не решаются вовсе, и команда начнёт добавлять проверки
прямо в `DeletionOrchestrator` — точка расширения обеспечит ровно то, что должна была
предотвратить. Опечатка в имени хука при `method_exists()` делает его мёртвым молча и навсегда.

**Решение.** Минимум — соблюдать `supports()` и убрать `method_exists()`:

```php
/** @return list<DeletionMiddlewareInterface> */
private function middlewaresFor(string $entityClass): array
{
    return array_values(array_filter(
        [...$this->middlewares],
        static fn (DeletionMiddlewareInterface $mw): bool => $mw->supports($entityClass),
    ));
}
```

Если нужен настоящий конвейер — интерфейс сводится к
`process(object $root, OrderedPlanDto $plan, callable $next): DeletionReport`, цепочка строится
`array_reverse`-свёрткой, порядок закрепляется `!tagged_iterator { …, default_priority_method: … }`.
Если наблюдатели — осознанный выбор (4.2), интерфейс надо разбить на шесть маленьких и перейти на
`instanceof`, чтобы опечатка ловилась статическим анализом.

#### 12. [MAJOR] Зависимость от конкретного `GenericReadRepository` — модуль нетестируем без БД

`Deletion/DeletionService.php:13,23`

**Проблема.** `private readonly GenericReadRepository $finder` (`:23`) — конкретный класс из
другого модуля и единственный источник данных о детях (`:261,271,277`). Абстракции нет. Вторая
зависимость, `EntityManagerInterface`, формально интерфейс, но используется как источник метаданных
всего приложения (`:82`) и построитель запросов — замокать осмысленно почти невозможно.

**Последствие.** Юнит-тест на «`BLOCKING` + `NONE` даёт отказ» требует поднятой БД, схемы и фикстур.
А это сердце модуля: находки 1 и 2 ловятся первым же табличным тестом на десять строк. Отсутствие
шва объясняет, почему они дожили до ревью, и делает вторую часть задания интеграционной, медленной
и неспособной покрыть комбинаторику `RelationType` × `DeletionCascade`. Цена измерена: прежде чем
написать первое утверждение про логику удаления, пришлось восстановить по точкам вызова
107-строчный стаб класса, которого нет в репозитории, вручную сфабриковать `ClassMetadata` (потому
что `isJsonField()` лезет в `fieldMappings` как в массив вместо `getTypeOfField()`) и завести 19
классов-фикстур — непременно настоящих, так как атрибуты читаются рефлексией и дублем не
подменяются. Один вынесенный интерфейс на `:23` и один вызов `getTypeOfField()` на `:323` убрали бы
почти всё это.

**Решение.** Интерфейс ровно под требования точек вызова, живущий внутри `Deletion`:

```php
namespace Shared\Deletion\Finder;

interface DeletionFinderInterface // + services.yaml: alias на GenericReadRepository
{
    public function getId(object $entity): int|string;

    /** @return iterable<object> */
    public function findByAssociation(string $entityClass, string $field, object $parent): iterable;

    /** @return iterable<object> */
    public function findByJsonContains(string $entityClass, string $field, int|string $value): iterable;

    /** @return iterable<object> */
    public function findByJoinTable(string $entityClass, string $joinTable, string $joinColumn, string $inverseJoinColumn, object $parent): iterable;
}
```

Аналогично вынести чтение метаданных за `RelationMapProviderInterface` (находка 13) — после этого
`DeletionService` тестируется на двух in-memory заглушках, без Doctrine.

#### 13. [MINOR] Внутренние 7-кортежи в публичном API; `CanDeleteDto` смешивает роли

`Deletion/DeletionService.php:306-311,34`, `Deletion/Service/DeletionOrchestrator.php:94`

**Проблема.** `getChildRelationRules()` публично отдаёт сырые семиэлементные кортежи, а оркестратор
разбирает их позиционно (`:94`). Добавление параметра в `RelationTo` требует синхронной правки в
трёх местах, и пропуск не ловится ничем — типы всех элементов совпадают, а проявится ошибка как
перепутанные `joinColumn`/`inverseJoinColumn`. Отдельно `:34` склеивает три разнородных списка
`array_merge`'ем, причём для элементов из `$parents` в поле `childClass` лежит класс родителя.

**Последствие.** Потребитель `CanDeleteDto` не может отличить «это меня блокирует» от «это будет
удалено вместе со мной» от «это ссылка вверх», то есть UI не может показать пользователю, что
произойдёт. Пример из самого README (`:48-54`) печатает «Найдено 1 записей типа OrderEntity», хотя
речь про один внешний ключ, и `$group->hard` там всегда `false` (находка 2).

**Решение.** Value object вместо кортежа и раздельные поля вместо `array_merge`:

```php
final readonly class ChildRelationRule
{
    public function __construct(
        public string $childClass,
        public string $field,
        public RelationType $type,
        public DeletionCascade $cascade,
        // ... joinTable, joinColumn, inverseJoinColumn
    ) {}

    public function isManyToMany(): bool
    {
        return $this->joinTable !== null && $this->joinColumn !== null && $this->inverseJoinColumn !== null;
    }
}
```

`CanDeleteDto` получает `$blockers` / `$cascade` / `$detach` вместо одного `$dependents`.
`isManyToMany()` убирает трижды продублированную проверку (`:137`, `:269`, `DeletionOrchestrator.php:95`).

### Производительность

#### 14. [MAJOR] `O(D × R)` запросов и полная гидратация ради идентификаторов

`Deletion/Service/DeletionOrchestrator.php:124-139`, `Deletion/DeletionService.php:266-280,82-101`

**Проблема.** Оркестратор потребляет из `analyze()` только `childrenDelete` и `childrenDetach`
(`DeletionOrchestrator.php:111,96`), но `analyze()` безусловно запускает и
`findParentsByAttributes()` (`DeletionService.php:42`) — для каждого из `D` потомков весь
родительский анализ является чистой потерей, включая по запросу на каждую join-table-связь вверх
(`:138`). Сверх того цикл `:124-139` на каждого потомка делает `getRepository()` (внутри цикла,
`:125`), `find()` (`:128`), при неудаче `findOneBy()` (`:131`) и `analyze()` (`:137`) — итого
`O(D × R)` запросов и `O(D)` гидрированных сущностей в никогда не очищаемой identity map. Результаты
`findByAssociation()`/`findByJoinTable()` обходятся только ради `getId($child)`
(`DeletionService.php:266-280`): полные сущности строятся, чтобы взять одно число. `ensureMap()`
(`:82-101`) строит `ClassMetadata` для каждой сущности приложения, кеша между процессами нет, и
одна битая аннотация где угодно обрушивает любой `canDelete()`.

**Последствие.** Клиент с 50 000 заказов по 5 позиций даёт `D ≈ 300 000`: при `R = 3` это порядка
900 000 запросов и 300 000 объектов в памяти. Операция не завершается — упирается в
`max_execution_time` или лимит памяти, удерживая транзакцию открытой и блокируя строки; соседние
запросы получают лавину lock wait timeout. `IN` такого размера в `deleteByIds()` (`:180-188`) и
`detachJoinRow()` (`:171-173`) вдобавок превышает лимит плейсхолдеров PDO.

**Решение.** Скалярные выборки вместо гидратации плюс нарезка `IN`:

```php
/** @return list<int|string> */
private function childIds(string $childClass, string $field, object $parent): array
{
    $idField = $this->identifierFieldOf($childClass);
    $rows = $this->em->createQueryBuilder()
        ->select(sprintf('c.%s', $idField))->from($childClass, 'c')
        ->where(sprintf('c.%s = :parent', $field))->setParameter('parent', $parent)
        ->getQuery()->getScalarResult(); // не getSingleColumnResult(): он только с ORM 2.10

    return array_column($rows, $idField);
}
```

Плюс `foreach (array_chunk($ids, self::DELETE_BATCH) as $chunk)` вокруг существующего DQL `DELETE`.
Рекурсия должна спускаться уровнями: собрать все id уровня, затем одним запросом на
`(класс, правило)` получить следующий — это переводит планирование из `O(D × R)` в `O(глубина × R)`
при нулевой гидратации. Карту — строить на прогреве контейнера (4.1).

### Конвенции

#### 15. [MINOR] README описывает несуществующий атрибут; сравнение каскада по литералу

`Deletion/README.md:65-72`, `Deletion/Service/DeletionOrchestrator.php:95`, `Deletion/DeletionService.php:323-325`

**Проблема.** Раздел «Параметры атрибута **DependsOn**» описывает параметры, которых нет в
`RelationTo` (`Attribute/RelationTo.php:13-21`): `parent` вместо `entity`, `hard` вместо
`type: RelationType`, `cascade` не описан вообще; изменилось и умолчание — `hard = true` против
`type = RelationType::REFERENCE`. Отдельно `:95` сравнивает каскад с литералом `'detach'` вместо
`DeletionCascade::DETACH_RELATIONS->value`, а в `:323-325` ветка `options['json']` мертва
безусловно (это не опция Doctrine — у Postgres она называется `jsonb`), тогда как ветка
`json_array` мертва только на DBAL ≥ 3: на DBAL 2, с которым работает ORM 2.x, этот тип ещё есть.

**Последствие.** Расхождение в имени параметра ловится сразу (`Unknown named parameter`),
расхождение в значении по умолчанию — нет: связь молча создаётся неблокирующей вместо блокирующей.
Литерал `'detach'` превращает переименование значения енама в тихую поломку detach-логики, которую
не свяжет ни PHP, ни статический анализ.

**Решение.** README §3 переписать под фактическую сигнатуру `RelationTo` с указанием умолчаний.
В коде:

```php
// DeletionOrchestrator.php:95 — после введения ChildRelationRule (находка 13)
if ($rule->isManyToMany() && $rule->cascade === DeletionCascade::DETACH_RELATIONS) {
// DeletionService::isJsonField() — вместо :317-325
return $metadata->hasField($field) && $metadata->getTypeOfField($field) === Types::JSON;
```

`hasField()`/`getTypeOfField()` стабильны и в ORM 2.x, и в 3.x — это снимает зависимость от
допущения A в данной точке.

### Безопасность / эксплуатация

#### 16. [MAJOR] `notify()` молча проглатывает любое исключение из middleware

`Deletion/Service/DeletionOrchestrator.php:156-166`

**Проблема.** Пустой `catch (Throwable)` (`:162-163`) на все шесть хуков, внутри транзакции.
Проглатывается всё: `ArgumentCountError` от рассинхронизации сигнатур, `DBALException` из
middleware, пишущего аудит, таймаут HTTP-клиента, `Error` из бага в самом middleware — ни лога, ни
счётчика, ни `finally`.

**Последствие.** Аудит удаления беззвучно становится ненадёжным: таблица переполнена или права
отозваны, `execute()` завершается успешно, данные удалены, аудиторской записи нет, в логах пусто —
расследовать нечего. В связке с находкой 8 (`after*` до коммита) возможен и обратный случай: запись
о событии есть, самого события не было.

**Решение.** Ошибка внутри транзакции обязана её откатывать; проглатывание — никогда молча.
`DeletionOrchestrator` при этом получает `LoggerInterface` в конструктор — сейчас его там нет.

```php
private function notify(string $method, string $entityClass, mixed ...$args): void
{
    foreach ($this->middlewaresFor($entityClass) as $mw) { // находка 11; method_exists() убран
        try {
            $mw->{$method}(...$args);
        } catch (Throwable $e) {
            $this->logger->error('Deletion middleware failed', [
                'middleware' => $mw::class, 'hook' => $method, 'exception' => $e,
            ]);

            throw $e;
        }
    }
}
```

Если какие-то хуки должны быть необязательными — это разные интерфейсы и `instanceof`, а не
`method_exists()`.

#### 17. [MINOR] По логам нельзя разобрать инцидент: `dryRun` неотличим, нет идентификатора операции

`Deletion/Middleware/LoggingDeletionMiddleware.php:20-48`, `Deletion/Service/DeletionOrchestrator.php:36-58`

**Проблема.** `$dryRun` до middleware не доходит: в `notify()` он не передаётся, а сами вызовы стоят
вне проверок `if (!$dryRun)` (`:37,46,54`). Сквозного идентификатора операции нет — каскад из десяти
классов даёт двадцать несвязанных записей, перемешанных с записями параллельных удалений.
Идентификатор корня не логируется нигде (только `$root::class`, `:42,47`), а `compact(…, 'childIds',
'relation')` (`:22,27`) выгружает весь массив идентификаторов на уровне `info`.

**Последствие.** После инцидента «пропали данные» в логах есть `Deleted children` без указания
корня, без границ операции и без признака, был ли это сухой прогон — а он показывается в UI и
потому выполняется часто. Вывод «данные удалил этот модуль» из логов не следует, и обратный тоже.
Плюс агрегатор режет многомегабайтные записи, так что самые массовые удаления теряются первыми.

**Решение.** Контекст операции передавать явно, логировать объёмы, а не содержимое.

```php
// хуки принимают DeletionContext(operationId, rootClass, rootId, dryRun)
$this->logger->info('deletion.children.delete', [
    'operation_id' => $ctx->operationId,
    'dry_run' => $ctx->dryRun,
    'root' => sprintf('%s#%s', $ctx->rootClass, $ctx->rootId),
    'child_class' => $childClass,
    'count' => count($childIds),
    'ids_sample' => array_slice($childIds, 0, 20),
]);
```

`operationId` генерируется один раз в `execute()` и проходит через все хуки — по нему картина
каскада собирается одним запросом. Полный список идентификаторов — в аудиторскую таблицу, не в лог.

### Соответствие SOLID, PSR и практикам Symfony

**SOLID.** Отображение уже сделанных находок на принципы из задания; новых номеров здесь нет.

- **SRP** — нарушен: `DeletionService` строит карту (`ensureMap()`, `:74-102`), анализирует связи
  (`:111-297`) и сам ходит в хранилище (`getJoinTableParentIds()`, `:211-225`). Три причины
  меняться в одном классе — находки 4, 12, 14.
- **OCP** — соблюдён в главном, нарушен в частном: направление `RelationTo`
  (`Attribute/RelationTo.php:10-21`) позволяет добавить связь, не трогая ни родителя, ни сервис
  (§2), но новый вариант `DeletionCascade` требует правки `if/elseif` в `:283-293` и guard-а в
  `DeletionOrchestrator.php:95` (находки 1 и 9, §4.1).
- **LSP** — классического нарушения нет: иерархий в модуле нет. Симптом есть, но источник его не в
  наследнике, а в потребителе: `DeletionService` смотрит на конкретный класс (`$object::class`,
  `:113`, `:252`) вместо контракта, поэтому подстановка наследника или прокси молча обнуляет связи
  (находка 7). Это скорее дефект чтения атрибутов, чем нарушение LSP, но эффект для клиента тот же.
- **ISP** — нарушен: `DeletionMiddlewareInterface.php:7-46` требует шесть обязательных хуков,
  поэтому реализация ради одного пишет пять пустых тел — находка 11.
- **DIP** — нарушен: `:23` зависит от конкретного `GenericReadRepository`, а
  `EntityManagerInterface` (`:82`) используется как источник метаданных всего приложения. Отсюда
  нетестируемость без БД — находка 12.

**PSR.** Проверено, и здесь модуль чист. **PSR-1**: один класс на файл, StudlyCaps, имя класса
совпадает с именем файла во всех 11 файлах, побочных эффектов на уровне файла нет. **PSR-4**:
пространство имён совпадает с путём в 11 из 11 (`Shared\Deletion\` → `Deletion/`). **PSR-12**:
`declare(strict_types=1)` стоит в 11 из 11. **PSR-3**: `LoggingDeletionMiddleware.php:7,11` зависит
от `Psr\Log\LoggerInterface` и получает его через конструктор, а не создаёт логгер сам.

Три мелочи, не тянущие на отдельную находку: [NITPICK] PSR-12 §4.5 предписывает `) {` одной строкой
для многострочных сигнатур — в модуле скобки разнесены в 7 файлах (`DeletionService.php:24-25`);
[NITPICK] мягкий лимит в 120 символов превышен примерно в десятке строк `DeletionService.php`
(`:17` — 211 символов), в основном в докблоках; [NITPICK] `CanDeleteDto` и `DependentGroupDto`
объявлены как `final readonly class`, а `OrderedPlanDto` и `RelationsDto` — как `final class` с
`public readonly`-свойствами.

**Symfony.** Конфигурации DI модуль не несёт: ни `services.yaml`, ни бандла, ни compiler pass — вся
проводка остаётся на потребителе. Аргумент `iterable $middlewares` (`DeletionOrchestrator.php:23`) —
идиоматичная форма под `!tagged_iterator`, но тега, который её наполняет, в срезе нет, а с ним нет и
управления порядком (находка 11, §4.2). Возможно, DI-конфиг просто вне среза — это наблюдение, а не
обвинение; что дал бы compiler pass, разобрано в §4.1.

---

## 4. Что бы я сделал иначе

### 4.1. Карта связей — на этапе компиляции контейнера, а не в рантайме

Сейчас `ensureMap()` (`DeletionService.php:74-102`) при первом вызове тянет `getAllMetadata()` и
обходит рефлексией все сущности; кеш живёт один процесс. Валидации при этом нет вообще: `:91`
использует `$attribute->entity` как ключ карты, не проверяя ни что такой класс существует, ни что
`field` есть у ребёнка. Опечатка в FQCN создаёт ключ, который никто никогда не читает — связь тихо
становится инертной, и `canDelete()` вечно возвращает `true`, не сообщив об ошибке ни в один момент.

Альтернатива: `CompilerPass` сканирует сущности на сборке, валидирует атрибуты (существует ли класс
из `entity`, есть ли поле `field`, задана ли тройка join-параметров целиком) и запекает карту в
контейнер аргументом-массивом. `DeletionService` перестаёт зависеть от `MetadataFactory`.

Компромисс: динамическая регистрация связей в рантайме невозможна, изменение атрибута требует
прогрева кеша. Взамен — нулевая стоимость на запрос, ошибки разметки на деплое и
юнит-тестируемость (находка 12): карта передаётся литералом.

Текущий подход прав, если сущности приходят из рантайм-плагинов или карта зависит от данных
(мультитенантность); также в долгоживущем воркере, где `ensureMap()` отрабатывает раз на тысячи
запросов.

### 4.2. Наблюдатели вместо конвейера — но тогда назвать их наблюдателями

Сейчас точка расширения называется middleware, ведёт себя как список слушателей и не может повлиять
на ход операции (находка 11).

Альтернатива: развести две слитые потребности. Решение — `DeletionVoterInterface::vote(object $root,
RelationsDto $relations): Vote`, на этапе планирования, вне транзакции, может запретить. Реакция —
доменные события Symfony после коммита: ничего не решают, ошибка подписчика не рушит выполненное
удаление.

Компромисс: две точки расширения вместо одной. Взамен у каждой стороны однозначны транзакционный
контекст и последствия исключения, и пустой `catch` становится оправданным ровно там, где безвреден.

Текущий подход прав, если требование действительно «обернуть операцию» — замерить, добавить retry,
открыть свою транзакцию. Тогда нужен конвейер с `$next`, и его надо доделать, а не разбирать.

### 4.3. Отдать каскад базе данных, оставив приложению предпросмотр

Сейчас приложение само вычисляет граф, формирует порядок и выполняет удаление, дублируя механику,
которую СУБД реализует нативно.

Альтернатива: `ON DELETE CASCADE`/`RESTRICT` в схеме, генерируемые из тех же атрибутов миграцией.
`DELETE` корня становится одним запросом, порядок и целостность обеспечивает СУБД, гонок (находка 8)
нет по построению, находки 14 нет как темы. `canDelete()` остаётся для предпросмотра.

Компромисс: доменные события по удалённым детям не выпустить, soft-delete через каскад БД не
выразить, `dryRun` считать отдельно; схема становится частью бизнес-логики.

Текущий подход прав, если на удаление детей навешаны побочные эффекты — списание с баланса, очистка
файлового хранилища, инвалидация индекса; судя по наличию middleware, это ваш случай. Тогда стоит
рассмотреть гибрид: `RESTRICT` в схеме как страховочная сетка (находка 1 не смогла бы удалить
защищённые строки, даже пройдя все проверки приложения), каскад — в приложении.

### 4.4. Планирование как операция над идентификаторами, а не над сущностями

Сейчас `buildRecursive()` грузит каждого ребёнка (`DeletionOrchestrator.php:129`), чтобы передать в
`analyze()`, который снова идёт в БД.

Альтернатива: план строится на парах `(class, id)`, все запросы скалярные, обход идёт уровнями —
для всего уровня одним запросом на правило собираются id следующего. Гидратации нет нигде.

Компромисс: `canDelete(object $object)` — удобный публичный API, и переход на идентификаторы
требует обеих перегрузок; middleware, которым нужен объект, придётся догружать его самим.

Текущий подход прав, если каскады малы — единицы и десятки записей. Оптимизировать стоит после
того, как найдётся реальный корень с большим поддеревом; порядок величины — вопрос 4.

### 4.5. Авторизация — отдельным слоем над оркестратором

Сейчас проверок прав в модуле нет; формально это не дефект (модуль инфраструктурный), но вопрос
«где они» без ответа, и соблазн решить его через middleware велик.

Альтернатива: оркестратор намеренно ничего не знает о правах, авторизация — явный слой поверх.
Voter на `DELETE` для корня плюс, что важнее, проверка на весь каскад: удаление доступного корня
может утянуть недоступных детей, а план уже является данными (`OrderedPlanDto`) и передаётся воутеру
целиком до начала транзакции.

Компромисс: ещё один слой и обязанность вызывающего его не забыть; частично лечится тем, что
HTTP-слой ходит не в оркестратор, а в прикладной сервис, где проверка зашита.

Текущий подход прав, если модуль вызывается только из уже авторизованных прикладных сервисов —
тогда встроенная авторизация была бы дублированием. Но это стоит задокументировать как контракт;
сейчас в README про это нет ничего.

---

## 5. Открытые вопросы к авторам

1. **Какая из двух трактовок `BLOCKING` авторитетна?** Докблок `Enum/RelationType.php:9` и README
   говорят «блокируется удаление аннотированного ребёнка», код
   (`DeletionService.php:120-122,288-292`) реализует «блокируется удаление родителя». Правка нужна
   в любом случае, но противоположная. Мы склоняемся ко второму как к соответствующему
   `ON DELETE RESTRICT`, но README формулирует обратное дважды и независимо (`:25` и `:104`), то
   есть это выглядит намеренной спецификацией, а не устаревшей строкой. Что из этого писалось
   раньше и что считать источником истины?

2. **Doctrine ORM 2 или 3?** `isJsonField()` (`:317,321`) обращается к `fieldMappings` как к
   массиву — верно для 2.x, ломается на объектах `FieldMapping` в 3.x. От ответа зависит и то,
   приходят ли в `analyze()` прокси (находка 7) или lazy ghosts.

3. **Консольная команда `deletion:check` — вне среза или удалена?** `README.md:124-142` описывает её
   как существующую, включая формат вывода. Если она есть, её стоит приложить: она наверняка задаёт
   часть контракта `DependentGroupDto` — в частности, зачем поле `count` рядом с `ids`.

4. **Каков ожидаемый порядок величины каскада?** От этого зависит, блокирующая находка 14 или
   теоретическая: при десятках записей поштучный `find()` (`:129`) приемлем, при тысячах нужен
   переход на идентификаторы (4.4).

5. **Предполагается ли `RelationTo` на классах с наследованием?** `getAttributes()` (`:87,117`) не
   поднимается по цепочке предков, поэтому наследник размеченной сущности теряет все связи молча.
   Осознанное ограничение или таких иерархий в проекте нет?
