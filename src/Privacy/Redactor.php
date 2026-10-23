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

    /** A frame is a bare file path and line; anything else (call arguments, spaces, brackets) is not one. */
    private const FRAME = '/^[^\s:()\'"@<>]+:\d+$/u';

    /** Names of the placeholders the Normalizer itself writes; an extra pattern may not impersonate one. */
    public const RESERVED = ['N', 'EMAIL', 'URL', 'HOST', 'IP', 'ID', 'TOKEN', 'PAN', 'STR'];

    /** @param array<string, string> $extraPatterns name => PCRE with delimiters */
    public function __construct(
        private readonly Normalizer $normalizer,
        private readonly array $extraPatterns = [],
    ) {
        foreach (array_keys($this->extraPatterns) as $name) {
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

    /**
     * Digits are acceptable only INSIDE an identifier (`Http2Exception`, `v2`): attached to a letter or underscore.
     * Any other change the scan makes — a masked host or e-mail, an extra pattern, a lone number — replaces the original.
     */
    private function identifier(string $value): ?string
    {
        if (LineParser::classNameOrNull($value) === null) {
            return null;
        }
        $scanned = $this->redactText($value, false);
        if ($scanned === null) {
            return null;
        }

        return $this->onlyIdentifierDigitsChanged($value, $scanned) ? $value : $scanned;
    }

    /** True when the scan changed nothing except digit runs that sit inside identifiers. */
    private function onlyIdentifierDigitsChanged(string $value, string $scanned): bool
    {
        return $scanned === preg_replace('/\d+/', '<N>', $value)
            && preg_match('/(?<![\p{L}_])\d+(?![\p{L}_])/u', $value) !== 1;
    }

    /** The path must survive the scan with at most identifier-attached digits; the line number is always digits. */
    private function frame(string $frame): ?string
    {
        if (preg_match(self::FRAME, $frame) !== 1) {
            return null;
        }
        $at = strrpos($frame, ':');
        $path = substr($frame, 0, (int) $at);
        $scanned = $this->redactText($path, false);

        return $scanned !== null && $this->onlyIdentifierDigitsChanged($path, $scanned) ? $frame : null;
    }
}
