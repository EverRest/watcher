<?php

declare(strict_types=1);

namespace Watcher\ApiContext;

use Watcher\Privacy\Redactor;
use Watcher\Source\SourceUnavailable;

/**
 * Decoded OpenAPI array -> ReducedSpec (research R3). Only six fields per operation are ever read out of the array; the
 * rest of the document (examples, schemas, descriptions, bodies, extensions, server variables) is never copied.
 */
final class OpenApiReducer
{
    public const MAX_ENTRY_BYTES = 100000;

    /** Free text is masked over at most this many characters, then cut; never cut first (a cut can halve a secret). */
    private const SCAN_CHARS = 1000;
    /** Run through the redactor to fingerprint its rules; the token-shaped part is assembled in fingerprint() so no secret-looking literal sits in src/. */
    private const CANARY = 'a.b@example.com %s 8812 10.1.2.3 3f2b8c1e-0a4d-4c7e-9b1a-2d5e6f708192 /v1/orders/8812/items';

    private const VERBS = ['get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace'];

    public function __construct(
        private readonly Redactor $redactor,
        private readonly int $summaryCap = 80,
        private readonly ?string $basePath = null,
        private readonly int $maxEntryBytes = self::MAX_ENTRY_BYTES,
    ) {
    }

    /**
     * @param array<mixed> $doc
     *
     * @throws SourceUnavailable `unsupported_version` or `bad_format`
     */
    public function reduce(array $doc): ReducedSpec
    {
        $swagger = ($doc['swagger'] ?? null) === '2.0';
        $openapi = is_string($doc['openapi'] ?? null) && preg_match('/^3\.[01]\.\d+(-.+)?$/', $doc['openapi']) === 1;
        if (!$swagger && !$openapi) {
            throw new SourceUnavailable('unsupported_version');
        }
        $paths = $doc['paths'] ?? null;
        if (!is_array($paths)) {
            throw new SourceUnavailable('bad_format');
        }

        $base = $this->base($doc, $swagger);
        $size = strlen((string) json_encode(['v' => 1, 'base' => $base, 'ops' => [], 'dropped' => 99999, 'readAt' => '2026-10-24T06:00:00+00:00']));
        $ops = [];
        $dropped = 0;
        $full = false;
        foreach ($paths as $path => $item) {
            if (!is_string($path)) {
                throw new SourceUnavailable('bad_format');
            }
            if (!str_starts_with($path, '/') || !is_array($item)) {
                continue;
            }
            // a template the redactor rewrites (3+ segments become <PATH>/…/tail) can never be matched: drop it, count it
            $template = $this->mask($path) === $path ? $path : null;
            foreach (self::VERBS as $verb) {
                if (!is_array($item[$verb] ?? null)) {
                    continue;
                }
                if ($template === null) {
                    ++$dropped;
                    continue;
                }
                $op = $this->operation(strtoupper($verb), $template, $item[$verb]);
                $size += strlen((string) json_encode([$op->method, $op->template, $op->summary, $op->tag, $op->deprecated, $op->operationId])) + 1;
                $full = $full || $size > $this->maxEntryBytes;
                if ($full) {
                    ++$dropped;
                } else {
                    $ops[] = $op;
                }
            }
        }

        return new ReducedSpec($base, $ops, $dropped);
    }

    /** @param array<mixed> $o */
    private function operation(string $method, string $template, array $o): OperationSummary
    {
        $summary = $this->text($o['summary'] ?? null);
        $tags = $o['tags'] ?? null;
        $tag = is_array($tags) ? $this->text($tags[0] ?? null) : '';
        $id = $summary === '' ? $this->text($o['operationId'] ?? null) : '';

        return new OperationSummary($method, $template, $summary, $tag, ($o['deprecated'] ?? null) === true, $id);
    }

    /**
     * Cache-key part: changes with every setting that shapes the stored entry and with what the redactor does to a canary
     * line, so an entry masked under older settings or older redaction rules is a miss, never served (G3).
     */
    public function fingerprint(): string
    {
        return substr(hash('sha256', (string) json_encode([
            ReducedSpec::VERSION, $this->summaryCap, $this->basePath, $this->maxEntryBytes, self::SCAN_CHARS, $this->mask(sprintf(self::CANARY, 'gh' . 'p_' . str_repeat('Ab1', 12))),
        ])), 0, 8);
    }

    /** Masked first (whole secrets), then cut to the cap; anything that cannot be scanned is dropped. */
    private function text(mixed $v): string
    {
        if (!is_string($v)) {
            return '';
        }
        $in = mb_substr($v, 0, self::SCAN_CHARS);
        $masked = $this->mask($in);
        // ponytail: input beyond SCAN_CHARS is cut before masking; if little of it survives masking the tail may hold half a secret, so drop
        if ($masked === null || ($in !== $v && mb_strlen($masked) < $this->summaryCap + 200)) {
            return '';
        }

        return mb_substr($masked, 0, $this->summaryCap);
    }

    private function mask(string $s): ?string
    {
        return $this->redactor->redactText($s);
    }

    /** @param array<mixed> $doc */
    private function base(array $doc, bool $swagger): string
    {
        $raw = $this->basePath;
        if ($raw === null) {
            $raw = '';
            if ($swagger) {
                $raw = is_string($doc['basePath'] ?? null) ? $doc['basePath'] : '';
            } else {
                $servers = $doc['servers'] ?? null;
                $url = is_array($servers) && is_array($servers[0] ?? null) ? ($servers[0]['url'] ?? null) : null;
                if (is_string($url) && !str_contains($url, '{')) {
                    $raw = (string) parse_url($url, PHP_URL_PATH);
                }
            }
        }
        $raw = rtrim($raw, '/');

        // kept raw: masking would turn a 3-segment base into <PATH>; only plain path characters are accepted
        return preg_match('~^/[A-Za-z0-9._\-/]*$~', $raw) === 1 ? $raw : '';
    }
}
