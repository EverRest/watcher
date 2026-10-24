<?php

declare(strict_types=1);

namespace Watcher\Tests\Fakes;

use Throwable;

/**
 * Shaped like Predis\Client (magic command methods, `eval($script, $numkeys, ...$args)`, `set($k, $v, 'PX', $ms, 'NX')`
 * returning a status object or null) over a FakeRedisServer. predis/predis itself is not installed.
 */
final class FakePredis
{
    public function __construct(private readonly FakeRedisServer $server, private readonly ?Throwable $failWith = null)
    {
    }

    public function get(string $key): ?string
    {
        $this->maybeFail();

        return $this->server->get($key);
    }

    public function set(string $key, string $value, mixed ...$options): ?object
    {
        $this->maybeFail();
        $ttl = null;
        $nx = false;
        for ($i = 0; $i < count($options); $i++) {
            if ($options[$i] === 'PX') {
                $ttl = (int) $options[++$i];
            } elseif ($options[$i] === 'NX') {
                $nx = true;
            }
        }
        if ($ttl === null) {
            throw new \LogicException('SET without PX');
        }

        return $this->server->set($key, $value, $ttl, $nx) ? new class () implements \Stringable {
            public function __toString(): string
            {
                return 'OK';
            }
        } : null;
    }

    public function eval(string $script, int $numKeys, string ...$args): mixed
    {
        $this->maybeFail();

        return $this->server->eval($script, array_values(array_slice($args, 0, $numKeys)), array_values(array_slice($args, $numKeys)));
    }

    public function pttl(string $key): int
    {
        $this->maybeFail();

        return $this->server->pttl($key);
    }

    private function maybeFail(): void
    {
        if ($this->failWith !== null) {
            throw $this->failWith;
        }
    }
}
