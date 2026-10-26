<?php

declare(strict_types=1);

namespace Watcher\Analyze;

/**
 * Plain-PHP validation of decoded model JSON against analyzer-output.schema.json, plus the length
 * limits the provider cannot enforce. Over-long free text is truncated, anything else is rejected.
 */
final class OutputValidator
{
    private const FINDING_KEYS = ['fingerprints', 'kind', 'severity', 'title', 'evidence', 'suspected_cause', 'suggested_action', 'confidence'];

    private const ADVICE_KEYS = ['how_to_check', 'related_checks'];

    private const LIMITS = ['title' => 80, 'evidence' => 240, 'suspected_cause' => 240, 'suggested_action' => 200, 'how_to_check' => 200];

    private const MAX_RELATED = 3;

    public const WITHHELD = CommandFilter::WITHHELD;

    /** @param bool $advice analysis.advice.enabled: false = how_to_check / related_checks are unexpected fields, as before the feature */
    public function __construct(private readonly bool $advice = true)
    {
    }

    /**
     * @param array<mixed>      $data
     * @param list<string>|array<string> $allowed fingerprints present in the request
     *
     * @return array{verdicts: array<string,string>}
     *
     * @throws InvalidModelOutput
     */
    public function triage(array $data, array $allowed): array
    {
        $list = $this->list($data, 'verdicts', 'verdicts');
        $verdicts = [];
        foreach ($list as $i => $row) {
            $p = "verdicts[$i]";
            $row = $this->object($row, $p, ['g', 'v'], []);
            $g = $this->fingerprint($row['g'], $allowed, "$p.g");
            if (!is_string($row['v']) || !in_array($row['v'], TriageResult::VERDICTS, true)) {
                throw InvalidModelOutput::at("$p.v", 'not an allowed verdict', $row['v']);
            }
            if (isset($verdicts[$g])) {
                throw InvalidModelOutput::at($p, 'duplicate verdict for one group');
            }
            $verdicts[$g] = $row['v'];
        }
        // A group with no verdict is not invalid output: the router treats it as `interesting` (fail closed) and counts it.
        // Duplicates and unknown fingerprints stay invalid.

        return ['verdicts' => $verdicts];
    }

    /**
     * @param array<mixed>      $data
     * @param list<string>|array<string> $allowed
     * @param list<string> $dependencyNames declared dependencies present in the request (the only names related_checks may cite)
     *
     * @return list<Finding>
     *
     * @throws InvalidModelOutput
     */
    public function analysis(array $data, array $allowed, array $dependencyNames = []): array
    {
        $out = [];
        foreach ($this->list($data, 'findings', 'findings') as $i => $row) {
            $p = "findings[$i]";
            $row = $this->object($row, $p, self::FINDING_KEYS, $this->advice ? self::ADVICE_KEYS : []);
            if (!is_array($row['fingerprints']) || !array_is_list($row['fingerprints']) || $row['fingerprints'] === []) {
                throw InvalidModelOutput::at("$p.fingerprints", 'must be a non-empty list');
            }
            $fps = [];
            foreach ($row['fingerprints'] as $j => $fp) {
                $fps[] = $this->fingerprint($fp, $allowed, "$p.fingerprints[$j]");
            }
            $kind = is_string($row['kind']) ? FindingKind::tryFrom($row['kind']) : null;
            $sev = is_string($row['severity']) ? Severity::tryFrom($row['severity']) : null;
            if ($kind === null) {
                throw InvalidModelOutput::at("$p.kind", 'not an allowed kind', $row['kind']);
            }
            if ($sev === null) {
                throw InvalidModelOutput::at("$p.severity", 'not an allowed severity', $row['severity']);
            }
            $c = $row['confidence'];
            if ((!is_int($c) && !is_float($c)) || is_nan((float) $c) || $c < 0 || $c > 1) {
                throw InvalidModelOutput::at("$p.confidence", 'must be a number within 0..1', is_scalar($c) ? $c : null);
            }
            $out[] = new Finding(
                $fps,
                $kind,
                $sev,
                $this->text($row['title'], "$p.title", self::LIMITS['title']),
                $this->text($row['evidence'], "$p.evidence", self::LIMITS['evidence']),
                $this->text($row['suspected_cause'], "$p.suspected_cause", self::LIMITS['suspected_cause'], true),
                $this->text($row['suggested_action'], "$p.suggested_action", self::LIMITS['suggested_action'], true),
                (float) $c,
                false,
                isset($row['how_to_check']) ? $this->text($row['how_to_check'], "$p.how_to_check", self::LIMITS['how_to_check'], true) : '',
                $this->related($row['related_checks'] ?? [], "$p.related_checks", $allowed, $dependencyNames),
            );
        }

        return $out;
    }

    /**
     * @param array<mixed> $data
     *
     * @return list<mixed>
     */
    private function list(array $data, string $key, string $path): array
    {
        $data = $this->object($data, '$', [$key], []);
        $list = $data[$key];
        if (!is_array($list) || !array_is_list($list)) {
            throw InvalidModelOutput::at($path, 'must be a list');
        }

        return $list;
    }

    /**
     * @param list<string> $required
     * @param list<string> $optional
     *
     * @return array<string, mixed>
     */
    private function object(mixed $v, string $path, array $required, array $optional): array
    {
        if (!is_array($v) || ($v !== [] && array_is_list($v))) {
            throw InvalidModelOutput::at($path, 'must be an object');
        }
        foreach ($required as $k) {
            if (!array_key_exists($k, $v)) {
                throw InvalidModelOutput::at($path, "missing field $k");
            }
        }
        foreach (array_keys($v) as $k) {
            if (!in_array($k, $required, true) && !in_array($k, $optional, true)) {
                throw InvalidModelOutput::at($path, 'unexpected field ' . mb_substr((string) $k, 0, 40));
            }
        }

        return $v;
    }

    /** @param array<string> $allowed */
    private function fingerprint(mixed $v, array $allowed, string $path): string
    {
        if (!is_string($v) || !in_array($v, $allowed, true)) {
            throw InvalidModelOutput::at($path, 'unknown fingerprint', $v);
        }

        return $v;
    }

    /**
     * Each entry is EXACTLY a fingerprint of the request in the 12-character form the digest prints, or a declared dependency
     * name; anything else (advice around a reference, an invented reference, a longer text) is dropped. The text shown is
     * therefore only ever a reference taken from the request. A wrong type or too many entries is invalid output.
     *
     * @param array<string> $allowed
     * @param list<string>  $dependencyNames
     *
     * @return list<string>
     */
    private function related(mixed $v, string $path, array $allowed, array $dependencyNames): array
    {
        if (!is_array($v) || !array_is_list($v) || count($v) > self::MAX_RELATED) {
            throw InvalidModelOutput::at($path, 'must be a list of at most ' . self::MAX_RELATED);
        }
        $known = [...array_map(static fn (string $fp): string => substr($fp, 0, 12), $allowed), ...$dependencyNames];
        $out = [];
        foreach ($v as $i => $entry) {
            if (!is_string($entry)) {
                throw InvalidModelOutput::at("$path[$i]", 'must be a string');
            }
            if ($entry !== '' && in_array($entry, $known, true) && !in_array($entry, $out, true)) {
                $out[] = $entry;
            }
        }

        return $out;
    }

    private function text(mixed $v, string $path, int $max, bool $advice = false): string
    {
        if (!is_string($v)) {
            throw InvalidModelOutput::at($path, 'must be a string');
        }
        $v = mb_strlen($v) > $max ? mb_substr($v, 0, $max - 1) . '…' : $v;

        return CommandFilter::withhold($v, $advice);
    }
}
