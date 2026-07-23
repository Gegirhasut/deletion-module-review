<?php

declare(strict_types=1);

namespace Tests\Unit;

use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Shared\Deletion\Dto\OrderedPlanDto;
use Shared\Deletion\Service\DeletionOrchestrator;
use Tests\Fixture\{
    BlockingDetachChild,
    CascadeGrandchild,
    ChainChild,
    ProtectedGrandchild,
    RootEntity
};
use Tests\Support\DeletionServiceTestCase;

/**
 * Планирование в DeletionOrchestrator — через публичный getOrderedPlan() (:190-195).
 *
 * Метод зовёт только plan() + buildOrderedPlan(), не открывает транзакцию, ничего не мутирует
 * и возвращает OrderedPlanDto данными, поэтому проверяется теми же дублями, что и анализатор.
 *
 * ВНЕ области: execute(), wrapInTransaction, middleware/notify, реальное выполнение
 * deleteByIds() и detachJoinRow(). Это требует транзакции и БД.
 */
final class DeletionOrchestratorPlanTest extends DeletionServiceTestCase
{
    /**
     * Фиксирует текущее поведение. Находка 1, полная цепочка: так быть не должно.
     *
     * R → C (REFERENCE + DELETE_CHILD, hard=false) → G (BLOCKING + NONE).
     * Вердикт canDelete считается ТОЛЬКО для корня, поэтому вызывающий получает true
     * и имеет полное право звать execute(); при этом защищённый внук уже лежит в плане удаления.
     */
    #[Test]
    public function protected_grandchild_reaches_the_plan_although_root_reports_deletable(): void
    {
        $plan = $this->planForProtectedChain($analyzerSaysDeletable);

        self::assertTrue($analyzerSaysDeletable, 'canDelete(R) отвечает true — вызывающий действует корректно.');
        self::assertContains(ProtectedGrandchild::class, array_column($plan->delete, 'class'));
    }

    /**
     * Находка 1: так быть не должно. Связь BLOCKING + NONE объявлена как «удалять нельзя»,
     * и никакой обход дерева не должен превращать её в цель удаления.
     */
    #[Test]
    #[Group('defects')]
    public function protected_grandchild_must_not_reach_the_deletion_plan(): void
    {
        $plan = $this->planForProtectedChain($analyzerSaysDeletable);

        self::assertNotContains(
            ProtectedGrandchild::class,
            array_column($plan->delete, 'class'),
            'Защищённая связь не может оказаться в плане удаления ни при какой трактовке BLOCKING.',
        );
    }

    /**
     * Фиксирует текущее поведение. Находка 5: план выдаётся в порядке обнаружения
     * сверху вниз — сначала прямые дети корня, потом внуки, — тогда как внешние ключи
     * требуют обратного (сначала самые глубокие потомки).
     *
     * Само нарушение FK-констрейнта здесь НЕ проверяется: для этого нужна БД со схемой.
     * Тест фиксирует ровно порядок, который производит алгоритм.
     *
     * Половина находки 5 про циклы намеренно не покрыта: buildRecursive не имеет множества
     * посещённых узлов, поэтому фикстура с самоссылкой уронила бы прогон в бесконечную
     * рекурсию, а не дала бы читаемое падение.
     */
    #[Test]
    public function plan_lists_parents_before_their_children(): void
    {
        $this->mapEntities(ChainChild::class, CascadeGrandchild::class);
        $this->withIdentifier(RootEntity::class);
        $this->withIdentifier(ChainChild::class);
        $this->withIdentifier(CascadeGrandchild::class);

        $this->finderReturns(
            [ChainChild::class => [new ChainChild()], CascadeGrandchild::class => [new CascadeGrandchild()]],
            [RootEntity::class => 1, ChainChild::class => 10, CascadeGrandchild::class => 200],
        );
        $this->repositoriesReturn([
            ChainChild::class => new ChainChild(),
            CascadeGrandchild::class => new CascadeGrandchild(),
        ]);

        $plan = $this->orchestrator()->getOrderedPlan(new RootEntity());

        self::assertSame(
            [ChainChild::class, CascadeGrandchild::class],
            array_column($plan->delete, 'class'),
            'Родитель раньше ребёнка — обратно тому, что требуют внешние ключи.',
        );
    }

    /**
     * Фиксирует текущее поведение. Находка 9: связь видна в анализе как childrenDetach,
     * но guard на :95 требует `$joinTable && $cascade === 'detach'`, а joinTable здесь null,
     * поэтому в план она не попадает никогда — план detach остаётся пустым.
     */
    #[Test]
    public function detach_without_join_table_never_reaches_the_plan(): void
    {
        $this->mapEntities(BlockingDetachChild::class);
        $this->withIdentifier(RootEntity::class);
        $this->finderReturns(
            [BlockingDetachChild::class => [new BlockingDetachChild()]],
            [RootEntity::class => 1, BlockingDetachChild::class => 100],
        );
        $this->repositoriesReturn([]);

        $analyzer = $this->service();
        $orchestrator = new DeletionOrchestrator($this->em, $analyzer);

        self::assertCount(1, $analyzer->analyze(new RootEntity())->childrenDetach, 'Связь в анализе есть.');
        self::assertSame([], $orchestrator->getOrderedPlan(new RootEntity())->detach, 'А в плане её нет.');
    }

    /**
     * Фиксирует текущее поведение. Находка 6: когда идентификатор назван не `id`,
     * :112-116 подменяет поле для DELETE на $group->field — имя FK НА РОДИТЕЛЯ, — тогда как
     * $group->ids содержит идентификаторы ДЕТЕЙ. В плане оказывается пара из разных доменов.
     */
    #[Test]
    public function non_id_identifier_puts_the_foreign_key_field_into_the_plan(): void
    {
        $this->mapEntities(ChainChild::class);
        $this->withIdentifier(RootEntity::class);
        $this->withIdentifier(ChainChild::class, 'uuid'); // PK назван не id
        $this->finderReturns(
            [ChainChild::class => [new ChainChild()]],
            [RootEntity::class => 1, ChainChild::class => 10],
        );
        $this->repositoriesReturn([]); // findOneBy вернёт null — рекурсия дальше не пойдёт

        [$entry] = $this->orchestrator()->getOrderedPlan(new RootEntity())->delete;

        self::assertSame('root', $entry['field'], 'В план уехало имя FK на родителя…');
        self::assertSame([10], $entry['ids'], '…рядом с идентификаторами детей.');
    }

    private function planForProtectedChain(?bool &$analyzerSaysDeletable): OrderedPlanDto
    {
        $this->mapEntities(ChainChild::class, ProtectedGrandchild::class);
        $this->withIdentifier(RootEntity::class);
        $this->withIdentifier(ChainChild::class);
        $this->withIdentifier(ProtectedGrandchild::class);

        $this->finderReturns(
            [ChainChild::class => [new ChainChild()], ProtectedGrandchild::class => [new ProtectedGrandchild()]],
            [RootEntity::class => 1, ChainChild::class => 10, ProtectedGrandchild::class => 100],
        );
        $this->repositoriesReturn([
            ChainChild::class => new ChainChild(),
            ProtectedGrandchild::class => new ProtectedGrandchild(),
        ]);

        $analyzer = $this->service();
        $analyzerSaysDeletable = $analyzer->canDelete(new RootEntity())->canDelete;

        return (new DeletionOrchestrator($this->em, $analyzer))->getOrderedPlan(new RootEntity());
    }

    private function orchestrator(): DeletionOrchestrator
    {
        return new DeletionOrchestrator($this->em, $this->service());
    }

    /**
     * @param array<class-string, list<object>> $childrenByClass
     * @param array<class-string, int>          $idByClass
     */
    private function finderReturns(array $childrenByClass, array $idByClass): void
    {
        $this->finder->method('getId')->willReturnCallback(
            static fn (object $entity): int => $idByClass[$entity::class] ?? 0
        );
        $this->finder->method('findByAssociation')->willReturnCallback(
            static fn (string $childClass): array => $childrenByClass[$childClass] ?? []
        );
    }

    /** @param array<class-string, object> $found */
    private function repositoriesReturn(array $found): void
    {
        $this->em->method('getRepository')->willReturnCallback(function (string $class) use ($found): EntityRepository {
            $repository = $this->createMock(EntityRepository::class);
            $repository->method('find')->willReturn($found[$class] ?? null);
            $repository->method('findOneBy')->willReturn($found[$class] ?? null);

            return $repository;
        });
    }
}
