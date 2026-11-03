# Doctrine deletion module — code review and executable defect analysis

A PHP/Symfony/Doctrine review exercise focused on cascade deletion, dependency
ordering and the gap between documented rules and actual behavior.

The contribution is the **review and PHPUnit test suite**. The supplied module in
`Deletion/` is unchanged; it is not presented as an original production system.
[REVIEW.md](REVIEW.md) explains the findings and architectural alternatives.
[The test guide](tests/README.md) distinguishes characterization tests from
deliberately failing defect reproductions.

```bash
composer install
composer test          # characterization suite
composer test:defects  # intentionally failing demonstrations of reviewed defects
```

The detailed review and original assignment notes below are in Russian.

## Original assignment notes

## Часть 1 — ревью

[REVIEW.md](REVIEW.md) — 17 находок (4 BLOCKER / 10 MAJOR / 3 MINOR), раздел «Что бы я сделал
иначе» и открытые вопросы к авторам.

## Часть 2 — тесты (вариант A)

[tests/](tests/) — PHPUnit-тесты `DeletionService` и планировщика
`DeletionOrchestrator::getOrderedPlan()`. Подробности в [tests/README.md](tests/README.md).

Код модуля лежит в [Deletion/](Deletion/) — из присланного архива, не изменён ни на строку.

Замечания удобнее оставлять построчно в [PR #1](https://github.com/Gegirhasut/deletion-module-review/pull/1) — там код и ревью идут диффом.

## Запуск

```bash
composer install
composer test           # 50 тестов, 90 утверждений — зелёный
composer test:defects   # 6 падающих
```

Первый набор фиксирует поведение модуля таким, какое оно сегодня. Второй утверждает корректное
поведение и падает намеренно — это исполняемое доказательство находок 1, 3, 7 и 9, и каждый тест
называет свой номер в докблоке.

Что подтверждено тестами, а что выведено из чтения кода, перечислено в [REVIEW.md](REVIEW.md),
раздел 1.2.
