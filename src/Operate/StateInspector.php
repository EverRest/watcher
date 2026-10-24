<?php

declare(strict_types=1);

namespace Watcher\Operate;

use InvalidArgumentException;
use Watcher\Privacy\Redactor;
use Watcher\State\Keys;
use Watcher\State\StateStore;

/**
 * `watcher state:get`: a read-only look at one of the package's own state entries, for debugging.
 * Takes a logical key (never a store key, a prefix or a glob), reads it with `get` only, and returns the decoded
 * structure with every string passed through the Redactor and the configured secrets mask, cut to 200 characters.
 * Raw values are never returned.
 */
final class StateInspector
{
    public const MAX_STRING = 200;

    private const WINDOW_END = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D';

    /** @param list<string> $secrets WatchConfig::secrets() */
    public function __construct(
        private readonly StateStore $store,
        private readonly Redactor $redactor,
        private readonly string $service,
        private readonly array $secrets = [],
    ) {
    }

    /**
     * @throws InvalidArgumentException when $spec is not one of: heartbeat, last-snapshot, snapshot:<window end>, run:<window end>
     * @return string|null pretty JSON, or null when nothing is stored
     */
    public function render(string $spec): ?string
    {
        $value = $this->store->get($this->key($spec));
        if ($value === null) {
            return null;
        }

        return json_encode($this->mask($value), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    private function key(string $spec): string
    {
        [$kind, $end] = array_pad(explode(':', $spec, 2), 2, null);
        $windowed = in_array($kind, ['snapshot', 'run'], true) && $end !== null && preg_match(self::WINDOW_END, $end) === 1;
        if ($spec === 'heartbeat') {
            return Keys::heartbeat($this->service);
        }
        if ($spec === 'last-snapshot') {
            return Keys::lastSnapshot($this->service);
        }
        if ($windowed && $kind === 'snapshot') {
            return Keys::snapshot($this->service, (string) $end);
        }
        if ($windowed) {
            return Keys::run($this->service, (string) $end);
        }

        throw new InvalidArgumentException('state:get: key must be heartbeat, last-snapshot, snapshot:<window end> or run:<window end> (window end like 2026-10-24T06:00:00Z)');
    }

    private function mask(mixed $value): mixed
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                // Keys (fingerprints, field names) are cut and secret-masked, not redacted: a redacted fingerprint is useless.
                $out[is_string($k) ? $this->cut($this->secrets($k)) : $k] = $this->mask($v);
            }

            return $out;
        }
        if (is_string($value)) {
            return $this->cut($this->redactor->redactText($this->secrets($value)) ?? Redactor::WITHHELD);
        }

        return $value;
    }

    private function secrets(string $s): string
    {
        return $this->secrets === [] ? $s : str_replace($this->secrets, '[secret]', $s);
    }

    private function cut(string $s): string
    {
        $n = mb_strlen($s);

        return $n <= self::MAX_STRING ? $s : mb_substr($s, 0, self::MAX_STRING) . '...[truncated, ' . ($n - self::MAX_STRING) . ' more characters]';
    }
}
