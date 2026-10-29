<?php

declare(strict_types=1);

namespace Watcher\Alert;

use DateTimeImmutable;
use Watcher\Level;
use Watcher\Notify\Message;
use Watcher\Notify\NoticeRenderer;
use Watcher\Privacy\Redactor;
use Watcher\Source\LogEvent;
use Watcher\State\Keys;
use Watcher\State\StateStore;

/**
 * alerts.immediate: matches each event of the run's read as it streams past, groups repeats into one message with a count,
 * and delivers at most one per (rule, message, affected service) per window. At most MAX_PER_READ messages go out per read;
 * the rest stay unrecorded, so the next read sends them. Context values reach an alert only when listed in alerts.fields.
 *
 * @phpstan-type Rule array{level: Level, message: ?string, action: ?string}
 * @phpstan-type Pending array{identity: string, rule: string, level: Level, template: string, literal: ?string, fields: array<string, string>, count: int, first: DateTimeImmutable}
 */
final class ImmediateAlerts
{
    /** Most alert messages per read; the rest are held and summarised in one line. */
    public const MAX_PER_READ = 5;

    /** Context names alerts.fields may list; the parser's flattened keys map back to these. */
    public const FIELDS = ['service', 'http_code', 'error', 'services'];

    /** @var array<string, Pending> one entry per distinct alert of this read, in first-seen order */
    private array $pending = [];

    /**
     * @param array<string, Rule> $rules
     * @param list<string>        $fields alerts.fields: the only context names that may be rendered (empty = none)
     */
    public function __construct(
        private readonly array $rules,
        private readonly Redactor $redactor,
        private readonly NoticeRenderer $notices,
        private readonly string $service,
        private readonly string $environment,
        private readonly array $fields = [],
        /** Same threshold as the digest (redaction.min_group_events_for_text): below it, an event text that is not a rule literal is withheld. */
        private readonly int $minTextCount = 3,
    ) {
    }

    /** The raw message and context are only read here, in memory; what is kept is a hash, a masked template and masked allowed fields. */
    public function observe(LogEvent $e): void
    {
        $raw = self::canonical($e->fields);
        foreach ($this->rules as $name => $rule) {
            if (!$e->level->atLeast($rule['level'])) {
                continue;
            }
            if ($rule['message'] !== null && !str_contains($e->message, $rule['message'])) {
                continue;
            }
            if ($rule['action'] !== null && ($e->fields['action'] ?? null) !== $rule['action']) {
                continue;
            }
            $template = $this->redactor->redactText($e->message, false) ?? '[withheld]';
            // The affected service identifies the alert even when its name is not rendered; only its hash is kept.
            $identity = sha1($name . "\x1f" . $template . "\x1f" . ($raw['service'] ?? ''));
            $fields = $this->allowed($raw);
            $group = $identity . "\x1f" . json_encode($fields, JSON_THROW_ON_ERROR);
            $this->pending[$group] ??= ['identity' => $identity, 'rule' => $name, 'level' => $e->level, 'template' => $template, 'literal' => $rule['message'], 'fields' => $fields, 'count' => 0, 'first' => $e->time];
            $this->pending[$group]['count']++;
        }
    }

    /**
     * Sends the due alerts, at most MAX_PER_READ of them, then one line counting the rest. A failure (state or delivery) is
     * reported to $failed and never escapes: the run and the digest go on.
     *
     * @param \Closure(Message): void $send
     * @param \Closure(\Throwable): void $failed
     */
    public function deliver(StateStore $state, string $stateKey, int $windowSeconds, \Closure $send, \Closure $failed): void
    {
        $due = [];
        foreach ($this->pending as $alert) {
            try {
                $key = Keys::immediateAlert($stateKey, $alert['identity']);
                if ($state->get($key) === null) {
                    $due[] = [$key, $alert];
                }
            } catch (\Throwable $e) {
                $failed($e);
            }
        }
        foreach (array_slice($due, 0, self::MAX_PER_READ) as [$key, $alert]) {
            try {
                $send($this->notices->alert($this->service, $this->environment, strtolower($alert['level']->name), $this->shownText($alert), $alert['fields'], $alert['count'], $alert['first']));
                $state->put($key, ['at' => $alert['first']->format(DATE_ATOM)], $windowSeconds);
            } catch (\Throwable $e) {
                $failed($e);
            }
        }
        $held = count($due) - self::MAX_PER_READ;
        if ($held > 0) {
            try {
                $send($this->notices->held($this->service, $this->environment, strtolower($due[self::MAX_PER_READ][1]['level']->name), $held, self::MAX_PER_READ));
            } catch (\Throwable $e) {
                $failed($e);
            }
        }
    }

    /**
     * Canonical field name => raw value. The parser flattens nested context to `context.service` and lists to `services.0`,
     * so both shapes map back to one name; list items are joined. Only names in FIELDS and `service` are kept here.
     *
     * @param array<string, scalar|null> $raw
     *
     * @return array<string, string>
     */
    private static function canonical(array $raw): array
    {
        $out = [];
        foreach ($raw as $key => $value) {
            $name = (string) preg_replace('/^context\.|\.\d+$/', '', (string) $key);
            if ($value === null || !in_array($name, self::FIELDS, true)) {
                continue;
            }
            $out[$name] = isset($out[$name]) ? $out[$name] . ', ' . (string) $value : (string) $value;
        }

        return $out;
    }

    /**
     * The rule's own literal when it has one (the operator wrote it, and it is what matched). Otherwise the masked template,
     * shown only for a group as large as the digest shows text; a smaller group says [withheld].
     *
     * @param Pending $alert
     */
    private function shownText(array $alert): string
    {
        if ($alert['literal'] !== null) {
            return $alert['literal'];
        }

        return $alert['count'] >= $this->minTextCount ? $alert['template'] : '[withheld]';
    }

    /**
     * The configured names only, masked the way the digest masks text.
     *
     * @param array<string, string> $raw
     *
     * @return array<string, string>
     */
    private function allowed(array $raw): array
    {
        $out = [];
        foreach ($this->fields as $name) {
            if (isset($raw[$name])) {
                $out[$name] = $this->redactor->redactText($raw[$name], $name === 'http_code') ?? '[withheld]';
            }
        }

        return $out;
    }
}
