<?php

declare(strict_types=1);

namespace Watcher\Privacy;

use Throwable;
use Watcher\Analyze\Finding;
use Watcher\Reduce\NormalizationException;
use Watcher\Reduce\Normalizer;
use Watcher\Reduce\ProblemGroup;

/**
 * Principle I, layer 2: allowlist projection, then a second pattern scan (Normalizer table plus the
 * configured extra patterns) over every free-text field. A field that cannot be scanned is dropped,
 * never passed through raw (FR-011).
 */
final class Redactor
{
    public const WITHHELD = '[withheld]';

    /** A frame is a bare file path and line; anything else (call arguments, spaces, brackets) is not one. */
    private const FRAME = '/^[^\s:()\'"@<>]+:\d+$/u';

    /** Any placeholder except `<N>` (digits alone are not personal data in a path or class name). */
    private const MASKED = '/<(?!N>)/';

    /** @param array<string, string> $extraPatterns name => PCRE with delimiters */
    public function __construct(
        private readonly Normalizer $normalizer,
        private readonly array $extraPatterns = [],
    ) {
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

    /** Scrubs the four model-written fields; a field that cannot be scanned becomes "[withheld]". */
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
        );
    }

    /** @throws NormalizationException */
    private function scan(string $text, bool $keepNumbers): string
    {
        if (preg_match('//u', $text) !== 1) {
            throw new NormalizationException('Input is not valid UTF-8.');
        }
        // Extra patterns first, on the raw text: the Normalizer would turn digits in their match into <N>.
        foreach ($this->extraPatterns as $name => $pattern) {
            $out = @preg_replace($pattern, '<' . strtoupper((string) $name) . '>', $text);
            if ($out === null) {
                throw new NormalizationException('Extra pattern failed: ' . $name);
            }
            $text = $out;
        }

        return ($keepNumbers ? $this->normalizer->keepingNumbers() : $this->normalizer)->scan($text)['text'];
    }

    /** Class names keep their digits (Http2Exception); only a masked result replaces the original. */
    private function identifier(string $value): ?string
    {
        $scanned = $this->redactText($value, false);

        return $scanned !== null && preg_match(self::MASKED, $scanned) === 1 ? $scanned : ($scanned === null ? null : $value);
    }

    private function frame(string $frame): ?string
    {
        if (preg_match(self::FRAME, $frame) !== 1) {
            return null;
        }
        $scanned = $this->redactText($frame, false);

        return $scanned === null || preg_match(self::MASKED, $scanned) === 1 ? null : $frame;
    }
}
