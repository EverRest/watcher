<?php

declare(strict_types=1);

namespace Watcher\Tests\Contract;

use PDO;
use Psr\Clock\ClockInterface;
use Watcher\State\PdoStateStore;
use Watcher\State\Purgeable;
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

    protected function longKeyLength(): int
    {
        return PdoStateStore::MAX_KEY;
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

    public function testImplementsPurgeableAndRemovesExpiredRows(): void
    {
        $this->store->put('old', ['v' => 1], 10);
        $this->store->put('live', ['v' => 2], 1000);
        $this->advance(11);

        $removed = $this->purgeable()->purge();

        self::assertSame(1, $removed);
        self::assertSame(1, $this->rowCount());
        self::assertNull($this->store->get('old'));
        self::assertSame(['v' => 2], $this->store->get('live'));
    }

    public function testPurgeReturnsZeroWhenNothingExpired(): void
    {
        $this->store->put('live', ['v' => 1], 1000);

        self::assertSame(0, $this->purgeable()->purge());
        self::assertSame(1, $this->rowCount());
    }

    public function testPutWithSamePayloadRefreshesTtlWithoutDuplicateRow(): void
    {
        $this->store->put('k', ['v' => 1], 60);
        $this->store->put('k', ['v' => 1], 120);

        self::assertSame(1, $this->rowCount());
        self::assertSame(['v' => 1], $this->store->get('k'));
        $this->advance(70);
        self::assertSame(['v' => 1], $this->store->get('k'), 'TTL was extended on the second put');
    }

    public function testPurgesExpiredRowsOnEveryNthWrite(): void
    {
        $store = new PdoStateStore($this->pdo, $this->clock, 'watcher_state', 2);
        $store->put('old', ['v' => 1], 10);
        $this->advance(10);
        $rows = [$this->rowCount()];
        $store->put('new', ['v' => 2], 60); // 2nd write triggers purge
        $rows[] = $this->rowCount();

        self::assertSame([1, 1], $rows, 'the expired row was still there before the purge; only the new one after');
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

    private function purgeable(): Purgeable
    {
        self::assertInstanceOf(Purgeable::class, $this->store);

        return $this->store;
    }

    private function rowCount(): int
    {
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM watcher_state');
        self::assertNotFalse($stmt);

        return (int) $stmt->fetchColumn();
    }

    public function testAKeyLongerThanTheColumnIsRefusedAtWrite(): void
    {
        $key = str_repeat('k', 192);
        foreach ([
            fn () => $this->store->put($key, ['v' => 1], 60),
            fn () => $this->store->acquire($key, 'o', 60),
            fn () => $this->store->increment($key, 1.0, 60),
        ] as $write) {
            try {
                $write();
                self::fail('expected a refusal');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('191', $e->getMessage());
            }
        }
        $this->store->put(str_repeat('k', 191), ['v' => 1], 60);
        self::assertSame(['v' => 1], $this->store->get(str_repeat('k', 191)));
    }

    public function testAFirstWriteThatLosesTheInsertRaceIsRetriedOnce(): void
    {
        $pdo = new class ('sqlite::memory:') extends PDO {
            public bool $raced = false;

            /** @param array<mixed> $options */
            public function prepare(string $query, array $options = []): \PDOStatement|false
            {
                if (!$this->raced && str_starts_with($query, 'INSERT')) {
                    $this->raced = true;
                    $this->commit(); // end the store's transaction, let the other runner commit its row, then go on
                    $this->exec("INSERT INTO watcher_state (k, value, expires_at) VALUES ('race', '{\"total\":5}', 9999999999)"); // the competing runner wins
                    $this->beginTransaction();
                }

                return parent::prepare($query, $options);
            }
        };
        $pdo->exec(PdoStateStore::createTableSql('sqlite'));
        $store = new PdoStateStore($pdo, $this->clock);

        self::assertSame(6.0, $store->increment('race', 1.0, 60));

        $pdo->raced = false;
        $pdo->exec("DELETE FROM watcher_state");
        $store->put('race', ['v' => 2], 60);
        self::assertSame(['v' => 2], $store->get('race'));
    }
}
