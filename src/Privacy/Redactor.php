<?php

declare(strict_types=1);

namespace Watcher\Privacy;

use Throwable;
use Watcher\Analyze\Finding;
use Watcher\Reduce\NormalizationException;
use Watcher\Reduce\Normalizer;
use Watcher\Reduce\ProblemGroup;
use Watcher\Source\LineParser;

/**
 * Principle I, layer 2: allowlist projection, then a second pattern scan (Normalizer table plus the
 * configured extra patterns) over every free-text field. A field that cannot be scanned is dropped,
 * never passed through raw (FR-011).
 */
final class Redactor
{
    public const WITHHELD = '[withheld]';

    /** A frame is a bare file path (optionally with a drive letter) and line; anything else (call arguments, spaces, brackets) is not one. */
    private const FRAME = '/^(?:[A-Za-z]:)?[^\s:()\'"@<>]+:\d+$/u';

    /** Directories of frameworks and vendor code: a path without a configured root is kept from the first one of these. */
    private const KNOWN_DIRS = ['vendor', 'app', 'src'];

    /** Without a configured root, at most this many trailing segments (directories plus the file name) are sent. */
    private const KEEP_SEGMENTS = 3;

    /** Tokens whose digits are part of a public name, not of an id (matched as a whole word, any case). */
    private const WELL_KNOWN = 'utf8|md5|sha256|base64|http2|ipv4|ipv6|x86_64|oauth2|s3|ec2|h264';

    /** Names of the placeholders the Normalizer itself writes; an extra pattern may not impersonate one. */
    public const RESERVED = ['N', 'EMAIL', 'URL', 'HOST', 'IP', 'ID', 'TOKEN', 'PAN', 'STR', 'PATH', 'USER'];

    private readonly Normalizer $normalizer;

    /** @var array<string, string> container root (no trailing slash, longest first) => repository path with its trailing slash, or '' */
    private readonly array $pathRoots;

    /**
     * @param array<string, string> $extraPatterns name => PCRE with delimiters
     * @param array<string, string> $pathRoots     `repo.path_map` (container root => repository path); a frame is sent only below a root,
     *                                             as the repository path (`/var/www/html/app/A.php` -> `app/A.php`)
     */
    public function __construct(Normalizer $normalizer, array $extraPatterns = [], array $pathRoots = [])
    {
        $this->normalizer = $normalizer->withExtraPatterns($extraPatterns);
        $roots = [];
        foreach ($pathRoots as $root => $repoPath) {
            $root = rtrim(str_replace('\\', '/', (string) $root), '/');
            if ($root !== '') {
                $roots[$root] = $repoPath === '' ? '' : rtrim($repoPath, '/') . '/';
            }
        }
        uksort($roots, static fn (string $x, string $y): int => strlen($y) <=> strlen($x));
        $this->pathRoots = $roots;
        foreach (array_keys($extraPatterns) as $name) {
            if (in_array(strtoupper((string) $name), self::RESERVED, true)) {
                throw new \InvalidArgumentException("redaction.extra_patterns: name '$name' is reserved for a built-in placeholder.");
            }
        }
    }

    public function redact(ProblemGroup $g): RedactedGroup
    {
        $p = RedactedGroup::fromProblemGroup($g);
        $frames = [];
        foreach ($p->frames as $frame) {
            $clean = $this->frame($frame);
            if ($clean !== null) {
                $frames[] = $clean;
            }
        }

        return new RedactedGroup(
            $p->fingerprint,
            $p->template === null ? null : $this->redactText($p->template, false),
            $p->level,
            $p->errorType === null ? null : $this->identifier($p->errorType),
            $frames,
            $p->count,
            $p->previousCount,
            $p->status,
            $p->watchMatches,
            $p->firstSeen,
            $p->lastSeen,
        );
    }

    /**
     * Scanned text, or null when it cannot be scanned safely. Free text (model output, later the service context)
     * keeps plain numbers; group fields (template, error type, frames) mask them.
     */
    public function redactText(string $text, bool $keepNumbers = true): ?string
    {
        try {
            return $this->scan($text, $keepNumbers);
        } catch (Throwable) {
            return null;
        }
    }

    /** Scrubs the model-written fields (title, evidence, cause, action, how_to_check, related_checks); a field that cannot be scanned becomes "[withheld]". */
    public function redactFinding(Finding $f): Finding
    {
        return new Finding(
            $f->fingerprints,
            $f->kind,
            $f->severity,
            $this->redactText($f->title) ?? self::WITHHELD,
            $this->redactText($f->evidence) ?? self::WITHHELD,
            $this->redactText($f->suspectedCause) ?? self::WITHHELD,
            $this->redactText($f->suggestedAction) ?? self::WITHHELD,
            $f->confidence,
            $f->escalated,
            $f->howToCheck === '' ? '' : ($this->redactText($f->howToCheck) ?? self::WITHHELD),
            // an exact 12-hex entry is a group fingerprint the validator took from the request: a hash prefix, not data, and not to be masked as an id
            array_map(fn (string $r): string => preg_match('/^[0-9a-f]{12}$/D', $r) === 1 ? $r : ($this->redactText($r) ?? self::WITHHELD), $f->relatedChecks),
        );
    }

    /** @throws NormalizationException */
    private function scan(string $text, bool $keepNumbers): string
    {
        if (preg_match('//u', $text) !== 1) {
            throw new NormalizationException('Input is not valid UTF-8.');
        }
        return ($keepNumbers ? $this->normalizer->keepingNumbers() : $this->normalizer)->scan($text)['text'];
    }

    /**
     * A class name: digit runs are masked (`Tenant_8812Exception` -> `Tenant_<N>Exception`) unless they belong to a
     * well-known token (`Http2`). Any other change the scan makes — a masked host or e-mail, an extra pattern — replaces the original.
     */
    private function identifier(string $value): ?string
    {
        if (LineParser::classNameOrNull($value) === null) {
            return null;
        }
        foreach (explode('\\', $value) as $part) {
            if (strlen($part) >= 20 && Normalizer::looksRandom((string) preg_replace('/\d+/', '', $part))) {
                return null;
            }
        }

        return $this->digitMasked($value);
    }

    /** The value with its digit runs masked (well-known tokens kept), or the scan's own text if it had to change more, or null. */
    private function digitMasked(string $value): ?string
    {
        $full = (string) preg_replace('/\d+/', '<N>', $value);
        $scanned = $this->redactText($full, false);

        return $scanned === null ? null : ($scanned === $full ? $this->maskDigits($value) : $scanned);
    }

    private function maskDigits(string $value): string
    {
        return (string) preg_replace('/(?<![a-z])(?i:' . self::WELL_KNOWN . ')(?![a-z\d])(*SKIP)(*F)|\d+/', '<N>', $value);
    }

    /** A frame is sent only after its path was rewritten (see safePath()) and scanned; the line number is kept. */
    private function frame(string $frame): ?string
    {
        if (preg_match(self::FRAME, $frame) !== 1) {
            return null;
        }
        $at = (int) strrpos($frame, ':');
        $path = $this->safePath(str_replace('\\', '/', substr($frame, 0, $at)));
        if ($path === null) {
            return null;
        }
        [$prefix, $rest] = $path;

        return $this->digitMasked($rest) === $this->maskDigits($rest) ? $prefix . $this->maskDigits($rest) . substr($frame, $at) : null;
    }

    /**
     * @return ?array{string, string} [`…/` or empty, the kept path]; null when no safe part can be named.
     *                                With roots configured only the part below a root is kept (no root: no frame).
     *                                Without: from the first known directory on, at most KEEP_SEGMENTS segments.
     *                                Every kept segment is plain characters and not random-looking, else the frame goes.
     */
    private function safePath(string $path): ?array
    {
        $prefix = '';
        if ($this->pathRoots !== []) {
            $rest = null;
            foreach ($this->pathRoots as $root => $repoPath) {
                if (str_starts_with($path, $root . '/')) {
                    $rest = substr($path, strlen($root) + 1);
                    $prefix = $repoPath;
                    break;
                }
            }
            $segments = $rest === null ? [] : array_values(array_filter(explode('/', $rest), static fn (string $x): bool => $x !== ''));
        } else {
            $all = array_values(array_filter(explode('/', $path), static fn (string $x): bool => $x !== ''));
            $from = null;
            foreach (array_slice($all, 0, -1) as $i => $seg) {
                if (in_array($seg, self::KNOWN_DIRS, true)) {
                    $from = $i;
                    break;
                }
            }
            if ($from === null) {
                return null;
            }
            $segments = array_slice($all, $from);
            if (count($segments) > self::KEEP_SEGMENTS) {
                $segments = array_slice($segments, -self::KEEP_SEGMENTS);
            }
            $prefix = $from > 0 || count($segments) < count($all) - $from ? '…/' : '';
        }
        if ($segments === []) {
            return null;
        }
        foreach ($segments as $seg) {
            if (!Normalizer::plainSegment($seg)) {
                return null;
            }
        }

        return [$prefix, implode('/', $segments)];
    }
}
