<?php

declare(strict_types=1);

namespace Watcher\Tests\Contract;

use PDO;
use Psr\Clock\ClockInterface;
use Watcher\State\PdoStateStore;
use Watcher\State\StateStore;

final class PdoStateStoreTest extends StateStoreContractTest
{
    private PDO $pdo;

    protected function makeStore(ClockInterface $clock): StateStore
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->exec(PdoStateStore::createTableSql('sqlite'));

        return new PdoStateStore($this->pdo, $clock);
    }

    public function testCreateTableSqlPerDriver(): void
    {
        foreach (['sqlite', 'mysql', 'pgsql'] as $driver) {
            $sql = PdoStateStore::createTableSql($driver, 'my_state');
            self::assertStringContainsString('my_state', $sql);
            self::assertStringContainsString('k VARCHAR(191)', $sql);
            self::assertStringContainsString('expires_at BIGINT', $sql);
            self::assertStringContainsString('PRIMARY KEY', $sql);
        }
        self::assertStringContainsString('utf8mb4', PdoStateStore::createTableSql('mysql'));
        self::assertStringContainsString('value MEDIUMTEXT', PdoStateStore::createTableSql('mysql'));
        self::assertStringContainsString('value TEXT', PdoStateStore::createTableSql('pgsql'));
        self::assertStringContainsString('value TEXT', PdoStateStore::createTableSql('sqlite'));
    }

    public function testCreateTableSqlRejectsUnknownDriverAndBadTable(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PdoStateStore::createTableSql('oracle');
    }

    public function testBadTableNameRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PdoStateStore::createTableSql('sqlite', 'x; DROP TABLE y');
    }

    public function testPurgesExpiredRowsOnEveryNthWrite(): void
    {
        $store = new PdoStateStore($this->pdo, $this->clock, 'watcher_state', 2);
        $store->put('old', ['v' => 1], 10);
        $this->advance(10);
        self::assertSame(1, $this->rowCount());
        $store->put('new', ['v' => 2], 60); // 2nd write triggers purge

        self::assertSame(1, $this->rowCount());
        self::assertSame(['v' => 2], $store->get('new'));
    }

    public function testPurgeCanBeDisabled(): void
    {
        $store = new PdoStateStore($this->pdo, $this->clock, 'watcher_state', 0);
        $store->put('old', ['v' => 1], 10);
        $this->advance(10);
        $store->put('new', ['v' => 2], 60);

        self::assertSame(2, $this->rowCount());
    }

    public function testKeyWithSqlMetacharactersIsParameterised(): void
    {
        $this->store->put("x'; DROP TABLE watcher_state; --", ['ok' => 1], 60);

        self::assertSame(['ok' => 1], $this->store->get("x'; DROP TABLE watcher_state; --"));
    }

    public function testPutOnLockKeyOverwritesIt(): void
    {
        $this->store->acquire('lock', 'a', 30);
        $this->store->put('lock', ['v' => 1], 30);

        self::assertSame(['v' => 1], $this->store->get('lock'));
        $this->store->release('lock', 'a');
        self::assertSame(['v' => 1], $this->store->get('lock'));
    }

    public function testIncrementTtlIsFixedAtFirstIncrement(): void
    {
        $this->store->increment('spend', 1.0, 10);
        $this->advance(6);
        $this->store->increment('spend', 1.0, 10);
        $this->advance(4); // 10s after the first increment

        self::assertSame(1.0, $this->store->increment('spend', 1.0, 10));
    }

    private function rowCount(): int
    {
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM watcher_state');
        self::assertNotFalse($stmt);

        return (int) $stmt->fetchColumn();
    }
}
