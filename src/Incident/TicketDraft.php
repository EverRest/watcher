<?php

declare(strict_types=1);

namespace Watcher\Incident;

use DateTimeImmutable;
use Watcher\Analyze\Finding;
use Watcher\Notify\DigestRenderer;
use Watcher\Notify\Message;
use Watcher\Privacy\RedactedGroup;

final class TicketDraft
{
    public const MAX_CHARS = DigestRenderer::MAX_SECTION_CHARS;

    /**
     * @param array<string, RedactedGroup>           $groups
     * @param array<string, string>                  $findingLabels
     * @param array{log: ?string, code: list<string>} $links
     */
    public static function message(
        Finding $finding,
        string $service,
        ?string $environment,
        DateTimeImmutable $windowStart,
        DateTimeImmutable $windowEnd,
        ?string $release,
        array $groups,
        array $findingLabels,
        array $links,
    ): Message {
        $fp = $finding->fingerprints[0];
        $group = $groups[$fp] ?? null;
        $env = $environment ?? 'default';
        $title = sprintf('[%s/%s] %s: %s', $service, $env, $finding->severity->value, $finding->title);
        if (isset($findingLabels[$fp]) && str_starts_with($findingLabels[$fp], 'known, grown · ')) {
            $key = substr($findingLabels[$fp], strlen('known, grown · '));
            $title = 'Update on ' . $key . ' — ' . $title;
        }
        $lines = ['Title: ' . $title, 'Severity: ' . $finding->severity->value . ' · kind: ' . $finding->kind->value];
        $lines[] = 'Service: ' . $service . ' · environment: ' . $env . ($release !== null && $release !== '' ? ' · release: ' . $release : '');
        $lines[] = sprintf('Window: %s — %s', $windowStart->format('Y-m-d\TH:i:s\Z'), $windowEnd->format('Y-m-d\TH:i:s\Z'));
        if ($group !== null) {
            $prev = $group->previousCount !== null ? number_format($group->previousCount) : '?';
            $lines[] = sprintf(
                'Counts: %s events (previous window %s) · trend: %s · first seen %s · last seen %s',
                number_format($group->count),
                $prev,
                $group->status->value,
                $group->firstSeen->format('Y-m-d\TH:i:s\Z'),
                $group->lastSeen->format('Y-m-d\TH:i:s\Z'),
            );
        }
        foreach ([
            ['Evidence:', $finding->evidence],
            ['Hypothesis (unconfirmed):', $finding->suspectedCause],
            ['Suggested next step:', $finding->suggestedAction],
            ['How to check:', $finding->howToCheck],
        ] as [$head, $body]) {
            if ($body !== '') {
                $lines[] = '';
                $lines[] = $head;
                $lines[] = $body;
            }
        }
        if ($finding->relatedChecks !== []) {
            $lines[] = '';
            $lines[] = 'Related: ' . implode(' · ', array_slice($finding->relatedChecks, 0, 3));
        }
        if (($links['log'] ?? null) !== null) {
            $lines[] = 'Logs: ' . $links['log'];
        }
        $code = $links['code'];
        if ($code !== []) {
            $lines[] = 'Code: ' . implode(' ', array_slice($code, 0, 3));
        }
        $lines[] = 'Fingerprint: ' . substr($fp, 0, 12);
        $body = mb_strlen($text = implode("\n", $lines)) > self::MAX_CHARS ? mb_substr($text, 0, self::MAX_CHARS - 1) . '…' : $text;

        return new Message(mb_substr($title, 0, 200), [['kind' => 'draft', 'text' => $body]]);
    }
}
