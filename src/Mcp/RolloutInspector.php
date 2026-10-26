<?php

declare(strict_types=1);

namespace Watcher\Mcp;

use Closure;
use Watcher\Config\WatchConfig;
use Watcher\State\StateStore;

/**
 * FR-074: the local installation only: PHP version against the package floor, extensions for the chosen state driver, the project's
 * Composer constraint, configuration presence and validity, the master switch and, only with `connect`, one read-only `get` on the state store.
 * Reasons come from a fixed vocabulary; versions are typed.
 */
final class RolloutInspector
{
    public const PHP_FLOOR = 80200;

    /**
     * @param Closure(string): bool $extensionLoaded
     * @param Closure(string): bool $classExists
     * @param Closure(WatchConfig): StateStore $stateFactory builds the (read-only wrapped) store; called only with connect
     */
    public function __construct(
        private readonly PathPolicy $policy,
        private readonly ConfigFileReader $reader,
        private readonly ConfigFileValidator $validator,
        private readonly Closure $extensionLoaded,
        private readonly Closure $classExists,
        private readonly Closure $stateFactory,
        private readonly string $installedVersion,
        private readonly int $phpVersionId,
        private readonly string $composerDir,
    ) {
    }

    /**
     * @return array{ok: bool, checks: list<array{name: string, status: string, reason: string|null}>, installed_version: string|null}
     * @throws ToolError
     */
    public function inspect(?string $path, bool $connect): array
    {
        $config = null;
        $configCheck = ['name' => 'config', 'status' => 'warn', 'reason' => 'no config in allowed roots'];
        if ($path !== null) {
            $file = $this->reader->read($this->policy->approve($path)); // path_not_allowed before any connection
            try {
                $v = $this->validator->validate($file);
            } finally {
                $file->close();
            }
            $config = $v['config'];
            $first = $v['problems'][0]['key'] ?? null;
            $configCheck = $v['valid'] ? ['name' => 'config', 'status' => 'pass', 'reason' => null] : ['name' => 'config', 'status' => 'fail', 'reason' => $first !== null ? 'invalid: ' . $first : 'invalid: config'];
        }
        $checks = [
            ['name' => 'php_version', 'status' => $this->phpVersionId >= self::PHP_FLOOR ? 'pass' : 'fail', 'reason' => $this->phpVersionId >= self::PHP_FLOOR ? null : 'php 8.2 required'],
            $this->extensions($config),
            $this->composer(),
            $configCheck,
            $config === null ? ['name' => 'master_switch', 'status' => 'skip', 'reason' => 'no config in allowed roots'] : ['name' => 'master_switch', 'status' => 'pass', 'reason' => $config->enabled ? 'enabled=true' : 'enabled=false'],
            $this->store($config, $connect),
        ];

        return ['ok' => array_filter($checks, static fn (array $c): bool => $c['status'] === 'fail') === [], 'checks' => $checks, 'installed_version' => preg_match('/^\d+\.\d+\.\d+/', $this->installedVersion, $m) === 1 ? $m[0] : null];
    }

    /** @return array{name: string, status: string, reason: string|null} */
    private function extensions(?WatchConfig $c): array
    {
        $ext = $this->extensionLoaded;
        $cls = $this->classExists;
        if ($c === null) {
            return ['name' => 'extensions', 'status' => 'skip', 'reason' => 'no config in allowed roots'];
        }
        $driver = $c->state['driver'] ?? 'file';
        if ($driver === 'redis') {
            $client = is_string($c->state['redis']['client'] ?? null) ? $c->state['redis']['client'] : 'auto';
            $predis = $cls('Predis\Client');
            if ($client === 'predis' && !$predis) {
                return ['name' => 'extensions', 'status' => 'fail', 'reason' => 'predis missing'];
            }
            if ($client !== 'predis' && !$ext('redis') && !($client === 'auto' && $predis)) {
                return ['name' => 'extensions', 'status' => 'fail', 'reason' => 'ext-redis missing'];
            }
            if (!$ext('zlib')) {
                return ['name' => 'extensions', 'status' => 'warn', 'reason' => 'ext-zlib missing (compression off)'];
            }
        } elseif ($driver === 'pdo') {
            if (!$ext('pdo')) {
                return ['name' => 'extensions', 'status' => 'fail', 'reason' => 'ext-pdo missing'];
            }
            $dsn = is_string($c->state['pdo']['dsn'] ?? null) ? $c->state['pdo']['dsn'] : '';
            $drv = explode(':', $dsn, 2)[0];
            if (preg_match('/^[a-z]+$/D', $drv) === 1 && !$ext('pdo_' . $drv)) {
                return ['name' => 'extensions', 'status' => 'fail', 'reason' => 'pdo driver missing'];
            }
        }

        return ['name' => 'extensions', 'status' => 'pass', 'reason' => null];
    }

    /** @return array{name: string, status: string, reason: string|null} */
    private function composer(): array
    {
        $dir = $this->composerDir;
        for ($i = 0; $i < 3; ++$i, $dir = dirname($dir)) {
            $f = $dir . '/composer.json';
            if (is_file($f) && !is_link($f) && (int) filesize($f) < 262144) {
                $j = json_decode((string) file_get_contents($f), true);
                $c = is_array($j) ? ($j['require']['everrest/watcher'] ?? $j['require-dev']['everrest/watcher'] ?? null) : null;
                if (!is_string($c)) {
                    return ['name' => 'composer', 'status' => 'fail', 'reason' => 'constraint missing'];
                }

                return self::allows($c, $this->installedVersion) ? ['name' => 'composer', 'status' => 'pass', 'reason' => null] : ['name' => 'composer', 'status' => 'fail', 'reason' => 'constraint does not allow ' . $this->installedVersion];
            }
        }

        return ['name' => 'composer', 'status' => 'skip', 'reason' => 'constraint missing'];
    }

    /** Caret, tilde, wildcard, exact and `>=` constraints joined by `||` or space; anything else is treated as allowing (not judged). */
    public static function allows(string $constraint, string $version): bool
    {
        if (preg_match('/^(\d+)\.(\d+)\.(\d+)/', $version, $v) !== 1) {
            return true;
        }
        $cmp = static fn (array $lo, array $hi): bool => version_compare($v[0], implode('.', $lo), '>=') && version_compare($v[0], implode('.', $hi), '<');
        foreach (preg_split('/\s*\|\|?\s*/', trim($constraint)) ?: [] as $alt) {
            if ($alt === '*' || $alt === '') {
                return true;
            }
            if (preg_match('/^\^(\d+)(?:\.(\d+))?(?:\.(\d+))?/', $alt, $m) === 1) {
                $maj = (int) $m[1];
                $min = (int) ($m[2] ?? 0);
                $pat = (int) ($m[3] ?? 0);
                $hi = $maj > 0 ? [$maj + 1, 0, 0] : ($min > 0 || isset($m[2]) ? [0, $min + 1, 0] : [1, 0, 0]);
                if ($cmp([$maj, $min, $pat], $hi)) {
                    return true;
                }
            } elseif (preg_match('/^~(\d+)\.(\d+)(?:\.(\d+))?/', $alt, $m) === 1) {
                $hi = isset($m[3]) ? [(int) $m[1], (int) $m[2] + 1, 0] : [(int) $m[1] + 1, 0, 0];
                if ($cmp([(int) $m[1], (int) $m[2], (int) ($m[3] ?? 0)], $hi)) {
                    return true;
                }
            } elseif (preg_match('/^(\d+)\.(\d+)\.\*$/D', $alt, $m) === 1) {
                if ($cmp([(int) $m[1], (int) $m[2], 0], [(int) $m[1], (int) $m[2] + 1, 0])) {
                    return true;
                }
            } elseif (preg_match('/^(\d+)\.\*$/D', $alt, $m) === 1) {
                if ($cmp([(int) $m[1], 0, 0], [(int) $m[1] + 1, 0, 0])) {
                    return true;
                }
            } elseif (preg_match('/^>=\s*(\d+\.\d+(?:\.\d+)?)/', $alt, $m) === 1) {
                if (version_compare($v[0], $m[1], '>=')) {
                    return true;
                }
            } elseif (preg_match('/^v?(\d+\.\d+\.\d+)$/', $alt, $m) === 1) {
                if ($m[1] === $v[0]) {
                    return true;
                }
            } else {
                return true;
            }
        }

        return false;
    }

    /** @return array{name: string, status: string, reason: string|null} */
    private function store(?WatchConfig $c, bool $connect): array
    {
        if (!$connect) {
            return ['name' => 'state_store', 'status' => 'skip', 'reason' => 'not requested'];
        }
        if ($c === null) {
            return ['name' => 'state_store', 'status' => 'skip', 'reason' => 'no config in allowed roots'];
        }
        try {
            $store = ($this->stateFactory)($c);
            $store->get('hb:' . rawurlencode($c->stateKey));

            return ['name' => 'state_store', 'status' => 'pass', 'reason' => 'read-only probe'];
        } catch (\Throwable) {
            return ['name' => 'state_store', 'status' => 'fail', 'reason' => 'unreadable'];
        }
    }
}
