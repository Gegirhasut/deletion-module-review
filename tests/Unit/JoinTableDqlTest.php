<?php

declare(strict_types=1);

namespace Tests\Unit;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Doctrine\ORM\Query\QueryException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Находка 4 — исполняемое доказательство, а не вывод по аналогии.
 *
 * ЕДИНСТВЕННЫЙ класс в наборе, который поднимает НАСТОЯЩИЙ EntityManager. Всё остальное
 * работает на дублях. Здесь это оправдано и дёшево: разбор DQL происходит до обращения к
 * базе, поэтому хватает sqlite :memory: без схемы, без фикстур-сущностей и без сервера.
 *
 * Конфигурация собирается вручную, а не через ORMSetup: тот требует symfony/cache, а
 * тащить лишнюю зависимость ради одного теста незачем.
 */
final class JoinTableDqlTest extends TestCase
{
    #[Test]
    public function dql_over_a_join_table_name_fails_before_reaching_the_database(): void
    {
        $connection = $this->connection();
        $em = new EntityManager($connection, $connection->getConfiguration());

        // Ровно тот запрос, что строит DeletionService::getJoinTableParentIds() (:215-222)
        // для разметки из README.md:85-92.
        $qb = $em->createQueryBuilder();
        $qb->select('jt.advert_id')
            ->from('advert_tag_relation', 'jt')
            ->where('jt.advert_tag_id = :objectId')
            ->setParameter('objectId', 3)
        ;

        self::assertSame(
            'SELECT jt.advert_id FROM advert_tag_relation jt WHERE jt.advert_tag_id = :objectId',
            $qb->getDQL(),
            'Имя таблицы уехало во FROM, имена столбцов — в SELECT/WHERE.',
        );

        try {
            $qb->getQuery()->getArrayResult();
            self::fail('Ожидалось исключение: advert_tag_relation не является сущностью Doctrine.');
        } catch (QueryException $e) {
            self::assertStringContainsString(
                "Class 'advert_tag_relation' is not defined",
                $e->getMessage(),
            );
        }

        self::assertFalse(
            $connection->isConnected(),
            'Отказ произошёл на семантическом анализе — до единого запроса в БД.',
        );
    }

    private function connection(): Connection
    {
        $config = new Configuration();
        $config->setMetadataDriverImpl(new AttributeDriver([__DIR__ . '/../Fixture']));
        $config->setProxyDir(sys_get_temp_dir() . '/deletion-test-proxies');
        $config->setProxyNamespace('DeletionTestProxies');
        $config->setAutoGenerateProxyClasses(true);

        return DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);
    }
}
