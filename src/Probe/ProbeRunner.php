<?php

declare(strict_types=1);

namespace Watcher\Probe;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use Watcher\Notify\DeliveryFailed;
use Watcher\Notify\Destination;
use Watcher\Notify\Message;
use Watcher\Notify\Notifier;
use Watcher\State\Keys;
use Watcher\State\StateStore;

/**
 * Runs the probes once per interval and alerts through the notifier, with no model call. A subject alerts when its status
 * changes (a recovery to ok included), or when a warning or failure stands and its dedupe key has expired: the key lives
 * {@see self::REMINDER_SECONDS}, so a standing problem repeats once a day. `unknown` never alerts and never changes the
 * stored status. A probe failure never fails the run: it is logged by class only.
 */
final class ProbeRunner
{
    public const REMINDER_SECONDS = 86400;

    /** A run may start a little before the interval is over; the due key then expires a minute early so that run counts. */
    private const EARLY_SECONDS = 60;

    /** @param list<Probe> $probes */
    public function __construct(
        private readonly array $probes,
        private readonly StateStore $state,
        private readonly Notifier $notifier,
        private readonly Destination $to,
        private readonly string $service,
        private readonly int $everySeconds,
        private readonly ?LoggerInterface $hostLog = null,
    ) {
    }

    /** @return list<Probe> the probes the settings enable */
    public static function probesFor(ProbeSettings $settings, ClockInterface $clock): array
    {
        return [
            new CertificateProbe($settings->hosts, $settings->warnDays, $settings->failDays, $clock),
            new SecretExpiryProbe($settings->secrets, $settings->warnDays, $settings->failDays, $clock),
        ];
    }

    /** Runs the probes when the interval has passed since the last run; otherwise does nothing. Never throws. */
    public function runDue(): void
    {
        try {
            if (!$this->state->acquire(Keys::probeDue($this->service), 'probes', $this->everySeconds - self::EARLY_SECONDS)) {
                return;
            }
            foreach ($this->probes as $probe) {
                try { // one probe that fails must not keep the others from running this interval
                    foreach ($probe->check() as $result) {
                        $this->observe($result);
                    }
                } catch (Throwable $e) {
                    $this->hostLog?->warning('probe failed: ' . $e::class, ['service' => $this->service]);
                }
            }
        } catch (Throwable $e) {
            $this->hostLog?->warning('probes not completed: ' . $e::class, ['service' => $this->service]);
        }
    }

    private function observe(ProbeResult $result): void
    {
        if ($result->status === ProbeResult::UNKNOWN) {
            return;
        }
        $key = Keys::probe($this->service, $result->subject);
        $last = $this->state->get($key)['status'] ?? ProbeResult::OK; // no record = it was ok (or the key expired while ok)
        if ($result->status === $last) {
            return;
        }
        $text = $result->status === ProbeResult::OK
            ? "[{$this->service}] probe back to ok: {$result->message}"
            : '[' . $this->service . '] probe ' . strtoupper($result->status) . ": {$result->message}";
        try {
            $this->notifier->send(new Message($text, [['kind' => 'notice', 'text' => $text]]), $this->to);
        } catch (DeliveryFailed $e) {
            $this->hostLog?->warning('probe notice not delivered: ' . $e::class, ['service' => $this->service]);

            return; // not recorded: the next run tries again
        }
        $this->state->put($key, ['status' => $result->status], self::REMINDER_SECONDS);
    }
}
