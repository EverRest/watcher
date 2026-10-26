<?php

declare(strict_types=1);

namespace Watcher\Mcp;

use Throwable;
use Watcher\Config\InvalidConfig;
use Watcher\Config\KeyCatalog;
use Watcher\Config\WatchConfig;

/**
 * FR-071/066: the configuration stage of `watcher check`, for a file that passed {@see PathPolicy}. The file is included (A4) exactly as
 * `watcher check --config` does, right after the pinned inode is re-verified; `WatchConfig::fromArray` is the validator, so this can never
 * disagree with `check`. The result holds key paths, codes and catalog hints; no value, no exception message, no string of the file.
 */
final class ConfigFileValidator
{
    private const KEY = '/^[A-Za-z0-9_.<>-]{1,120}$/D';

    /**
     * @return array{valid: bool, stage: string, problems: list<array{key: string|null, code: string, fix: string, docs: array{file: string, anchor: string|null}, did_you_mean: list<string>}>, secret_scan: string, flags_off: list<string>, file: array{bytes: int}, config: WatchConfig|null}
     */
    public function validate(PinnedFile $file): array
    {
        $problems = [];
        $scan = SecretLiteralScan::available() ? 'done' : 'skipped';
        foreach (SecretLiteralScan::scan($file->bytes) as $path) {
            $problems[] = self::problem($path, 'plain_text_secret', 'Read this credential from an environment variable (Env::string) instead of writing it in the file, and rotate the exposed value.');
        }
        $config = null;
        $values = $this->load($file);
        if ($values === null) {
            $problems[] = self::problem(null, 'config_file_error', 'The file must start with <?php and return the configuration array without error.');
        } else {
            try {
                $config = WatchConfig::fromArray($values);
            } catch (InvalidConfig $e) {
                $problems[] = self::fromMessage($e->getMessage());
            } catch (Throwable) {
                $problems[] = self::problem(null, 'config_file_error', 'The configuration could not be validated.');
            }
        }
        $off = [];
        if ($config !== null) {
            if (!$config->enabled) {
                $off[] = 'enabled';
            }
            foreach (WatchConfig::FLAGS as $f) {
                if (!$config->on($f)) {
                    $off[] = $f;
                }
            }
        }

        return ['valid' => $problems === [] && $config !== null, 'stage' => 'config', 'problems' => $problems, 'secret_scan' => $scan, 'flags_off' => $off, 'file' => ['bytes' => strlen($file->bytes)], 'config' => $config];
    }

    /** @return array<string, mixed>|null */
    private function load(PinnedFile $file): ?array
    {
        if (!str_starts_with($file->bytes, '<?php') || !$file->stillTheSame()) {
            return null;
        }
        ob_start();
        try {
            $v = (static fn (string $__path): mixed => require $__path)($file->approved->real);

            return is_array($v) ? $v : null;
        } catch (Throwable) {
            return null; // ParseError, a thrown exception, a missing function: the message may hold a value, so it is dropped
        } finally {
            ob_end_clean();
        }
    }

    /** @return array{key: string|null, code: string, fix: string, docs: array{file: string, anchor: string|null}, did_you_mean: list<string>} */
    private static function fromMessage(string $message): array
    {
        if (preg_match('/^Unknown configuration key: (.+)$/Di', $message, $m) === 1) {
            $key = self::key($m[1]);

            return self::problem($key, 'unknown_key', 'This key is not a configuration key; check the spelling.', $key === null ? [] : KeyCatalog::suggest($key));
        }
        $key = preg_match('/\b([a-z][a-z0-9_]*(?:\.[a-z0-9_<>-]+)+|service|enabled|environment)\b/', $message, $m) === 1 ? self::key($m[1]) : null;
        if (preg_match('/\b(?:required|missing|must be set|must not be empty)\b/i', $message) === 1) {
            return self::problem($key, 'missing_key', 'This key is required; set it (usually from an environment variable).');
        }

        return self::problem($key, 'invalid_value', 'The value of this key is not valid; watcher_explain_config describes it.');
    }

    private static function key(string $k): ?string
    {
        return preg_match(self::KEY, $k) === 1 ? $k : null;
    }

    /**
     * @param list<string> $didYouMean
     * @return array{key: string|null, code: string, fix: string, docs: array{file: string, anchor: string|null}, did_you_mean: list<string>}
     */
    private static function problem(?string $key, string $code, string $fix, array $didYouMean = []): array
    {
        $entry = $key === null ? null : KeyCatalog::find($key);

        return ['key' => $key, 'code' => $code, 'fix' => $fix, 'docs' => $entry === null ? ['file' => 'docs/configuration.md', 'anchor' => null] : $entry['docs'], 'did_you_mean' => $didYouMean];
    }
}
