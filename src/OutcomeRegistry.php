<?php

declare(strict_types=1);

namespace Watcher;

/**
 * Every outcome, reason, notice and code the package can emit, with an explanation (spec 015 FR-084, research R18).
 * The constants are the codes; `explain()` answers `watcher_explain_outcome`. Families (`truncated:<reason>`) are registered once.
 *
 * @phpstan-type Entry array{code: string, kind: string, severity: string, exit_code: int|null, meaning: string, likely_cause: string, check: list<string>, fix: list<string>, related: list<string>, docs: array{file: string, anchor: string|null}, reserved_by: string|null, family: bool}
 *
 * INTEGRATOR (T052): emit sites (Watcher::fail/failWithNotice/setCode, Heartbeat, Doctor, EventSchema, ServiceResult) should pass these
 * constants instead of string literals; tests/Unit/OutcomeRegistryDriftTest.php then gates it. Until then a test here pins that every
 * `RunOutcome` case and every literal code found in `src/` today is registered.
 */
final class OutcomeRegistry
{
    // RunOutcome cases
    public const SKIPPED_NOT_DUE = 'skipped_not_due';
    public const SKIPPED_LOCKED = 'skipped_locked';
    public const SKIPPED_DONE = 'skipped_done';
    public const BASELINED = 'baselined';
    public const QUIET = 'quiet';
    public const REPORTED = 'reported';
    public const FAILED = 'failed';
    public const INVALID_CONFIG = 'invalid_config';
    public const DISABLED = 'disabled';
    // run reasons
    public const SOURCE_UNAVAILABLE = 'source_unavailable';
    public const ANALYSIS_REFUSED = 'analysis_refused';
    public const ANALYSIS_FAILED = 'analysis_failed';
    public const LOCK_LOST = 'lock_lost';
    public const DELIVERY_FAILED = 'delivery_failed';
    public const INTERNAL_ERROR = 'internal_error';
    public const DIGEST_DISABLED = 'digest_disabled';
    public const TRUNCATED = 'truncated';
    public const OVERFLOW = 'overflow';
    public const OVERSIZED_LINES = 'oversized_lines';
    public const UNSCANNABLE_EVENTS = 'unscannable_events';
    public const ZERO_EVENTS = 'zero_events';
    // heartbeat and warnings
    public const SINK_FAILED = 'sink_failed';
    // event stream (config_warning codes)
    public const INCLUDE_TEXT_EXTERNAL = 'include_text_external';
    public const HTTP_INSECURE_ALLOWED = 'http_insecure_allowed';
    public const EVENT_CAP_HIT = 'event_cap_hit';
    public const GROUP_CAP_HIT = 'group_cap_hit';
    public const COMPARISON_GAP = 'comparison_gap';
    public const ANALYSIS_PARTIAL = 'analysis_partial';
    public const CHECK_TEST = 'check_test';
    // counters of the JSON run summary
    public const STATE_UNREADABLE = 'state_unreadable';
    // MCP errors
    public const STATE_UNAVAILABLE = 'state_unavailable';
    public const BUSY = 'busy';
    public const SECRET_REFUSED = 'secret_refused';
    public const PATH_NOT_ALLOWED = 'path_not_allowed';
    public const FILE_UNREADABLE = 'file_unreadable';
    public const FILE_TOO_LARGE = 'file_too_large';

    /** @var array<string, Entry>|null */
    private static ?array $entries = null;

    /** @return array<string, Entry> */
    public static function all(): array
    {
        if (self::$entries !== null) {
            return self::$entries;
        }
        $e = [];
        $o = static fn (string $a): array => self::doc('docs/operations.md', $a);
        $c = static fn (string $a): array => self::doc('docs/configuration.md', $a);
        $outcomes = $o('outcomes-and-exit-codes');
        self::add($e, self::REPORTED, 'outcome', 'info', 0, 'A digest was sent for the window.', 'New, grown or severe problem groups were found.', ['Read the digest in the channel.', 'Run `watcher status` for the last run.'], [], [self::QUIET], $outcomes);
        self::add($e, self::QUIET, 'outcome', 'info', 0, 'The window ran and nothing new, grown or severe qualified (or everything was noise).', 'A healthy day, or the selection thresholds are high.', ['`watcher_latest_report` shows the counts.', 'Compare `selection.min_level` and `selection.growth_factor` with what you expect.'], ['Lower `selection.min_level` or `selection.growth_factor` only if real problems were missed.'], [self::ZERO_EVENTS, self::BASELINED], $outcomes);
        self::add($e, self::BASELINED, 'outcome', 'info', 0, 'The first window was recorded as the baseline; no digest is expected.', 'First run of this service key, or the service name changed.', ['The next run compares against this baseline.'], ['None. Changing `service` starts a new baseline.'], [self::SKIPPED_DONE], $o('baseline-modes'));
        self::add($e, self::SKIPPED_NOT_DUE, 'outcome', 'info', 0, 'The trigger came outside the due hour of `schedule.run_at`.', 'The scheduler fires at a different time than `run_at` or the timezone differs.', ['Compare the scheduler time with `schedule.run_at` and `schedule.timezone`.'], ['Move the schedule to `run_at`, or `run_at` to the schedule.'], [self::SKIPPED_DONE], $outcomes);
        self::add($e, self::SKIPPED_DONE, 'outcome', 'info', 0, 'This window already ran; nothing was repeated and no model call was made.', 'A second trigger in the same window (idempotency working as intended).', ['`watcher status` shows the last run.'], ['None.'], [self::SKIPPED_LOCKED, self::BASELINED], $outcomes);
        self::add($e, self::SKIPPED_LOCKED, 'outcome', 'warn', 0, 'Another runner holds the lock for this window.', 'Two schedulers run the same service, or a crashed run left a lock (it expires after `schedule.lock_ttl`).', ['Count the schedulers that run this service.', 'Wait for `schedule.lock_ttl` and retry.'], ['Run one scheduler per service key.'], [self::LOCK_LOST], $o('troubleshooting'));
        self::add($e, self::FAILED, 'outcome', 'error', 1, 'The run failed: source, analysis, delivery or state.', 'See the reason code on the same line and the notice in the channel.', ['Read `reason=` on the outcome line.', '`watcher check` isolates the failing part.'], ['Fix the failing part named by the reason code.'], [self::SOURCE_UNAVAILABLE, self::ANALYSIS_FAILED, self::DELIVERY_FAILED, self::INTERNAL_ERROR], $outcomes);
        self::add($e, self::INVALID_CONFIG, 'outcome', 'error', 2, 'The configuration was rejected before any external call.', 'A key is missing, mistyped or has a wrong value; the message names the key, never the value.', ['Read the key path in the message.', '`watcher_validate_config` checks a file without any network.'], ['Fix the named key; `watcher_explain_config` describes it.'], [self::FAILED], $c('configuration-reference'));
        self::add($e, self::DISABLED, 'outcome', 'info', 0, 'The master switch `enabled` is false: nothing was read, called or written.', '`enabled` defaults to false; it is set per environment.', ['Look at `WATCHER_ENABLED` in the environment that runs the schedule.'], ['Set `enabled` / `WATCHER_ENABLED=1` where the watcher should run.'], [self::INVALID_CONFIG], $o('enabling-and-disabling-per-environment'));
        self::add($e, self::SOURCE_UNAVAILABLE, 'reason', 'error', 1, 'The log source could not be read.', 'Backend down, credential rejected, proxy URL wrong, or the response was too large.', ['`watcher check` source line.', 'The Loki URL form and the Viewer role (smoke-test.md section 1).'], ['Fix the URL or credential; allow `source.max_response_mb` if replies are large.'], [self::FAILED], $o('troubleshooting'));
        self::add($e, self::ANALYSIS_FAILED, 'reason', 'error', 1, 'The model analysis failed; the window is retried by the next trigger in the due hour.', 'Provider outage, timeout or an invalid reply.', ['`watcher check` provider lines.'], ['Wait for the retry; a second failure of the same window is recorded as final.'], [self::ANALYSIS_REFUSED], $outcomes);
        self::add($e, self::ANALYSIS_REFUSED, 'reason', 'error', 1, 'The model refused the request; the window is recorded as failed and not retried.', 'The provider declined the content of the request.', ['The notice in the channel names the window.'], ['Nothing to retry; the next window starts clean.'], [self::ANALYSIS_FAILED], $outcomes);
        self::add($e, self::LOCK_LOST, 'reason', 'warn', 1, 'The run lost its lock during the pipeline; another runner owns the window now.', 'The run outlived `schedule.lock_ttl`, or two schedulers overlap.', ['Compare the run duration with `schedule.lock_ttl`.'], ['Raise `schedule.lock_ttl` or run one scheduler per service.'], [self::SKIPPED_LOCKED], $o('troubleshooting'));
        self::add($e, self::DELIVERY_FAILED, 'reason', 'error', 1, 'The digest could not be delivered to the channel.', 'The webhook or bot credential is wrong or revoked, or the channel is down.', ['`watcher check` notifier line.'], ['Rotate or repair the destination credential (smoke-test.md section 1).'], [self::FAILED], $o('notification-channels'));
        self::add($e, self::INTERNAL_ERROR, 'reason', 'error', 1, 'An unexpected error; only the class name is reported.', 'A bug or an unusual environment.', ['The class name on the outcome line.'], ['Report it with the class name and the package version.'], [self::FAILED], $o('troubleshooting'));
        self::add($e, self::DIGEST_DISABLED, 'reason', 'info', 0, 'Findings were produced but no digest was posted because the digest destination is off.', '`notify.digest` is `none` (dashboard-only mode).', ['The configured notify driver.'], ['Set a digest destination if a channel message is wanted.'], [self::REPORTED], $o('dashboard-only-mode-and-event-sinks'));
        self::add($e, self::TRUNCATED, 'reason', 'warn', 0, 'The read of the window was cut short; the suffix names why (deadline, memory, cursor, cap).', 'The run deadline, 80% of `memory_limit`, a stalled cursor or `source.event_cap` was reached.', ['The suffix: `truncated:deadline`, `truncated:memory`, `truncated:cursor`, `truncated:cap`.'], ['Raise the limit named by the suffix, or narrow `source.stream_tags`.'], [self::QUIET], $c('digest-notices-counts-only-never-raw-content'), null, true);
        self::add($e, self::OVERFLOW, 'notice', 'warn', 0, 'Events were folded into overflow groups because `source.group_cap` was hit.', 'An unmasked id in a message makes every line distinct.', ['The digest notice "cluster cap hit".'], ['Fix the message or raise `source.group_cap`.'], [self::TRUNCATED], $o('troubleshooting'));
        self::add($e, self::OVERSIZED_LINES, 'notice', 'warn', 0, 'Lines over 64 KB were dropped before parsing.', 'A log line holds a very large payload.', ['The read-quality line of the digest.'], ['Shorten the logged payload.'], [self::UNSCANNABLE_EVENTS], $o('troubleshooting'));
        self::add($e, self::UNSCANNABLE_EVENTS, 'notice', 'warn', 0, 'Some events could not be scanned for personal data and were left out (fail closed).', 'Invalid UTF-8 or a pattern failure.', ['The count in the digest notice.'], ['Fix the encoding of the log source.'], [self::OVERSIZED_LINES], $c('digest-notices-counts-only-never-raw-content'));
        self::add($e, self::ZERO_EVENTS, 'notice', 'warn', 0, 'The source returned no event for several windows in a row.', 'Wrong `source.stream_tags`, a silent service, or a broken log pipeline.', ['Run the selector in the log UI for the same window.'], ['Correct `source.stream_tags`.'], [self::QUIET, self::SOURCE_UNAVAILABLE], $o('availability-guards'));
        self::add($e, self::SINK_FAILED, 'notice', 'warn', 0, 'An event sink failed since the last clean flush (`sink_failed:<sink>`); the run itself was not affected.', 'The sink target is unreachable or not writable.', ['`watcher check` observe line.'], ['Repair the sink target.'], [], self::doc('docs/observability.md', '6-sinks-that-never-break-the-run'), null, true);
        self::add($e, self::INCLUDE_TEXT_EXTERNAL, 'event_code', 'warn', null, 'Finding text goes to an external sink (`observe.include_text`).', 'A deliberate setting.', ['`observe` sinks in the configuration.'], ['Turn `observe.include_text` off if that text should stay inside.'], [], self::doc('docs/observability.md', '2-what-is-in-an-event-and-what-never-is'));
        self::add($e, self::HTTP_INSECURE_ALLOWED, 'event_code', 'warn', null, 'A sink uses plain http by explicit permission.', '`allow_insecure` is set for the sink.', ['The sink URL.'], ['Use https.'], [], self::doc('docs/observability.md', '6-sinks-that-never-break-the-run'));
        self::add($e, self::EVENT_CAP_HIT, 'event_code', 'warn', null, '`source.event_cap` was reached.', 'More events than the cap in the window.', ['The read-quality line.'], ['Raise the cap or narrow the selector.'], [self::TRUNCATED], $c('source'));
        self::add($e, self::GROUP_CAP_HIT, 'event_code', 'warn', null, '`source.group_cap` was reached.', 'Too many distinct messages.', ['The "cluster cap hit" notice.'], ['Fix unmasked ids or raise the cap.'], [self::OVERFLOW], $c('source'));
        self::add($e, self::COMPARISON_GAP, 'event_code', 'info', null, 'The previous window had no snapshot; the comparison used an older run.', 'A skipped or failed day.', ['The "compared with the run of" notice.'], ['None.'], [], $o('baseline-modes'));
        self::add($e, self::ANALYSIS_PARTIAL, 'event_code', 'warn', null, 'Some flagged groups were not analysed (`top_m` or an input limit).', 'More groups than `selection.top_m`, or the per-step input limit was reached.', ['The digest notice.'], ['Raise `selection.top_m` or the input limits if needed.'], [], $c('digest-notices-counts-only-never-raw-content'));
        self::add($e, self::CHECK_TEST, 'event_code', 'info', null, 'A test event sent by `watcher check --post-test`.', 'A deliberate test.', [], ['None.'], [], self::doc('docs/observability.md', '6-sinks-that-never-break-the-run'));
        self::add($e, self::STATE_UNREADABLE, 'counter', 'warn', null, 'The count of stored entries the run could not read in the JSON summary.', 'A shared store holds a value of the wrong shape, or the store was unreachable.', ['`watcher state:get` for the key.'], ['Repair or clear the offending entries.'], [self::STATE_UNAVAILABLE], self::doc('docs/observability.md', '8-the-run-summary-line-zero-dependencies'));
        $mcp = static fn (string $meaning, string $cause, string $fix, array $related = []): array => [$meaning, $cause, $fix, $related];
        foreach ([
            self::STATE_UNAVAILABLE => $mcp('The state store could not be read by the MCP server.', 'Store down, wrong credentials, or an unreadable file state directory.', 'Run `watcher check` and repair the state store.', [self::STATE_UNREADABLE]),
            self::BUSY => $mcp('A dry run is already in progress.', 'Two calls overlapped.', 'Wait for the first call to finish.'),
            self::SECRET_REFUSED => $mcp('A value that looks like a secret was refused; it was not stored, logged or echoed.', 'A credential was pasted where only a name is accepted.', 'Give the name of the environment variable, not its value; treat a pasted secret as exposed and rotate it.'),
            self::PATH_NOT_ALLOWED => $mcp('The path is outside the allowed root or not a trusted regular `.php` file.', 'The file is a symlink, group/world-writable, hard-linked, foreign-owned or elsewhere.', 'Place the file next to the server\'s configuration (or in the working directory in setup mode) with mode 0600 or 0644 owned by the same user.'),
            self::FILE_UNREADABLE => $mcp('The file could not be read.', 'Permissions or a race.', 'Check the mode and owner.'),
            self::FILE_TOO_LARGE => $mcp('The file is larger than 256 KB.', 'Not a configuration file.', 'Use the real configuration file.'),
        ] as $code => [$meaning, $cause, $fix, $related]) {
            self::add($e, $code, 'mcp_error', 'warn', null, $meaning, $cause, [], [$fix], array_values($related), self::doc('docs/mcp.md', null));
        }
        unset($o, $c);

        return self::$entries = $e;
    }


    /** @return array{file: string, anchor: string|null} */
    private static function doc(string $file, ?string $anchor): array
    {
        return ['file' => $file, 'anchor' => $anchor];
    }

    /**
     * @param array<string, Entry> $e
     * @param list<string> $check
     * @param list<string> $fix
     * @param list<string> $related
     * @param array{file: string, anchor: string|null} $docs
     */
    private static function add(array &$e, string $code, string $kind, string $sev, ?int $exit, string $meaning, string $cause, array $check, array $fix, array $related, array $docs, ?string $reserved = null, bool $family = false): void
    {
        $e[$code] = ['code' => $code, 'kind' => $kind, 'severity' => $sev, 'exit_code' => $exit, 'meaning' => $meaning, 'likely_cause' => $cause, 'check' => $check, 'fix' => $fix, 'related' => $related, 'docs' => $docs, 'reserved_by' => $reserved, 'family' => $family];
    }

    /**
     * An entry by code; `truncated:deadline` resolves to the family entry `truncated`.
     *
     * @return Entry|null
     */
    public static function explain(string $code): ?array
    {
        $all = self::all();
        if (isset($all[$code])) {
            return $all[$code];
        }
        $base = explode(':', $code, 2)[0];

        return str_contains($code, ':') && ($all[$base]['family'] ?? false) ? $all[$base] : null;
    }
}
