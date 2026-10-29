<?php

declare(strict_types=1);

namespace Watcher\Config;

use Watcher\Config\Catalog\CoreKeys;
use Watcher\Config\Catalog\IntegrationKeys;
use Watcher\Config\Catalog\ObserveKeys;

/**
 * The table of every configuration key with what a person needs to know about it (spec 015 FR-083, research R17): type, required,
 * default (rendered), the `WATCHER_*` variable the shipped configs read, whether it holds a credential, a one-line summary and the
 * documentation anchor. `watcher_explain_config` answers from it; tests tie it to `docs/configuration.md` and the shipped example
 * configs. The entries were generated from the reference table of `docs/configuration.md`; keep the two in step (the drift test fails otherwise).
 *
 * INTEGRATOR (T051): `WatchConfig`'s unknown-key rejection should read {@see self::paths()} so the validator accepts exactly these keys, and a
 * test should compare every default with the loaded `WatchConfig`. Neither is done here: both hunks live in `WatchConfig`/`Factory`.
 *
 * @phpstan-type Entry array{path: string, type: string, required: bool, default: string|null, env: string|null, secret: bool, summary: string, docs: array{file: string, anchor: string}}
 */
final class KeyCatalog
{
    /** @var array<string, Entry> */
    private const ENTRIES = [...CoreKeys::ENTRIES, ...ObserveKeys::ENTRIES, ...IntegrationKeys::ENTRIES];

    /** @return list<string> */
    public static function paths(): array
    {
        return array_keys(self::ENTRIES);
    }

    /**
     * Exact path; else a wildcard entry; else the nearest parent that is a free-form map.
     *
     * @return array{path: string, type: string, required: bool, default: string|null, env: string|null, secret: bool, summary: string, docs: array{file: string, anchor: string}, constraints: list<string>, related: list<string>, pitfalls: list<string>}|null
     */
    public static function find(string $path): ?array
    {
        $e = self::ENTRIES[$path] ?? null;
        if ($e === null && preg_match('/^(?:(.+)\.)?enabled$/D', $path, $m) === 1) {
            return self::flag($path, $m[1] ?? '');
        }
        if ($e === null) {
            $pattern = null;
            foreach (self::ENTRIES as $p => $_) {
                if (str_contains($p, '<') && preg_match('/^' . (string) preg_replace('/<[^>]+>/', '[^.]+', preg_quote($p, '/')) . '$/', $path) === 1) {
                    $pattern = $p;
                    break;
                }
            }
            if ($pattern === null) {
                for ($parent = $path; ($i = strrpos($parent, '.')) !== false;) {
                    $parent = substr($parent, 0, $i);
                    if ((self::ENTRIES[$parent]['type'] ?? null) === 'map') {
                        $pattern = $parent;
                        break;
                    }
                }
            }
            $e = $pattern === null ? null : self::ENTRIES[$pattern];
        }

        return $e === null ? null : $e + ['constraints' => [], 'related' => [], 'pitfalls' => []];
    }

    /**
     * `<section>.enabled` of the feature flags (docs/configuration.md, "Feature flags"): a boolean, off unless the section says otherwise.
     *
     * @return array{path: string, type: string, required: bool, default: string|null, env: string|null, secret: bool, summary: string, docs: array{file: string, anchor: string}, constraints: list<string>, related: list<string>, pitfalls: list<string>}
     */
    private static function flag(string $path, string $section): array
    {
        $what = $section === '' ? 'The master switch: with it false `run` does nothing and `status` reports `disabled`.' : "Feature flag of the `$section` section.";

        return ['path' => $path, 'type' => 'bool', 'required' => false, 'default' => $section === '' ? 'false' : null, 'env' => $section === '' ? 'WATCHER_ENABLED' : null, 'secret' => false, 'summary' => $what, 'docs' => ['file' => 'docs/configuration.md', 'anchor' => 'feature-flags-spec-012'], 'constraints' => [], 'related' => [], 'pitfalls' => []];
    }

    /** @return list<string> at most three known paths close to $path, never $path itself */
    public static function suggest(string $path): array
    {
        $scored = [];
        foreach (self::ENTRIES as $p => $_) {
            if ($p !== $path && !str_contains($p, '<')) {
                $d = levenshtein(substr($path, 0, 60), substr($p, 0, 60));
                if ($d <= max(3, intdiv(strlen($path), 3))) {
                    $scored[$p] = $d;
                }
            }
        }
        asort($scored);

        return array_slice(array_keys($scored), 0, 3);
    }
}
