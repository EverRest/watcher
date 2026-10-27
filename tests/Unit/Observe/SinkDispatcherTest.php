<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Observe;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Throwable;
use Watcher\Observe\Event;
use Watcher\Observe\EventSanitizer;
use Watcher\Observe\HttpSink;
use Watcher\Observe\HttpTarget;
use Watcher\Observe\SinkDispatcher;
use Watcher\Privacy\Redactor;
use Watcher\Reduce\Normalizer;
use Watcher\Source\Retry;
use Watcher\Tests\Fakes\RecordingEventSink;
use Watcher\Tests\Fakes\SpyLogger;
use Watcher\Tests\Fakes\TimeBoundedEventSink;

final class SinkDispatcherTest extends TestCase
{
    public function testSinksReceiveTheBatchInTheFixedOrder(): void
    {
        $order = [];
        $make = static function (string $name) use (&$order): RecordingEventSink {
            return new RecordingEventSink(null, static function () use (&$order, $name): void {
                $order[] = $name;
            });
        };

        $failures = $this->dispatcher(['prometheus' => [$make('prometheus'), false], 'log' => [$make('log'), true], 'http' => [$make('http'), false]])->dispatch([$this->event()]);

        self::assertSame(['log', 'http', 'prometheus'], $order);
        self::assertSame([], $failures);
    }

    public function testOneSinkThrowingDoesNotStopTheOthersAndIsCounted(): void
    {
        $ok = $this->recorder();
        $log = new SpyLogger();

        $failures = $this->dispatcher(['log' => [$this->recorder(new \RuntimeException('secret https://x/token')), true], 'file' => [$ok, false]], $log)->dispatch([$this->event()]);

        self::assertSame(['log' => 1], $failures);
        self::assertCount(1, $ok->received);
        self::assertStringNotContainsString('secret', (string) json_encode($log->records));
        self::assertStringContainsString('log', (string) json_encode($log->records));
        self::assertStringContainsString('RuntimeException', (string) json_encode($log->records));
    }

    public function testANetworkSinkGetsOnlyWhatIsLeftOfTheRunDeadline(): void
    {
        $net = new TimeBoundedEventSink();
        $local = $this->recorder();

        $failures = $this->dispatcher(['log' => [$local, true], 'http' => [$net, false]])->dispatch([$this->event()], 7);

        self::assertSame([], $failures);
        self::assertSame(7, $net->within);
        self::assertCount(1, $local->received);
    }

    public function testNoTimeLeftSkipsTheNetworkSinkAndCountsItButLocalSinksStillWrite(): void
    {
        $net = new TimeBoundedEventSink();
        $local = $this->recorder();
        $log = new SpyLogger();

        $failures = $this->dispatcher(['log' => [$local, true], 'http' => [$net, false]], $log)->dispatch([$this->event()], 0);

        self::assertSame(['http' => 1], $failures);
        self::assertSame(0, $net->emitted);
        self::assertCount(1, $local->received);
        self::assertCount(1, $log->records);
    }

    public function testABrokenLoggerDoesNotMakeDispatchThrow(): void
    {
        $logger = new class () extends \Psr\Log\AbstractLogger {
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                throw new \RuntimeException('logger down');
            }
        };

        $failures = (new SinkDispatcher(['log' => [$this->recorder(new \RuntimeException('x')), true]], new EventSanitizer(new Redactor(new Normalizer())), $logger))->dispatch([$this->event()]);

        self::assertSame(['log' => 1], $failures);
    }

    public function testDispatchNeverThrowsEvenOnAnError(): void
    {
        $failures = $this->dispatcher(['log' => [$this->recorder(new \TypeError('x')), true], 'file' => [$this->recorder(new \Error('y')), false]])->dispatch([$this->event()]);

        self::assertSame(['log' => 1, 'file' => 1], $failures);
    }

    public function testIncludeTextIsPerSinkWithIdenticalEventIds(): void
    {
        $withText = $this->recorder();
        $without = $this->recorder();

        $this->dispatcher(['log' => [$withText, true], 'file' => [$without, false]])->dispatch([$this->event()]);

        self::assertSame('Timeouts', $withText->received[0][0]['title']);
        self::assertArrayNotHasKey('title', $without->received[0][0]);
        self::assertSame($withText->received[0][0]['event_id'], $without->received[0][0]['event_id']);
    }

    public function testNothingIsSentForAnEmptyBatch(): void
    {
        $sink = $this->recorder();

        self::assertSame([], $this->dispatcher(['log' => [$sink, true]])->dispatch([]));
        self::assertSame([], $sink->received);
    }

    public function testDryRunPrintsAndCallsNoSink(): void
    {
        $sink = $this->recorder();

        $text = $this->dispatcher(['log' => [$sink, true]])->render([$this->event()]);

        self::assertSame([], $sink->received);
        self::assertStringStartsWith("== DRY RUN events ==\n", $text);
        self::assertStringContainsString('"title":"Timeouts"', $text);
        self::assertStringEndsWith("\n", $text);
    }

    public function testDryRunLeavesTextOutWhenNoSinkWantsIt(): void
    {
        $text = $this->dispatcher(['file' => [$this->recorder(), false]])->render([$this->event()]);

        self::assertStringNotContainsString('Timeouts', $text);
    }

    public function testAnExhaustedHttpSinkWaitsAtMostTheRetryBudget(): void
    {
        $waited = 0;
        $retry = new Retry(static function (int $s) use (&$waited): void {
            $waited += $s;
        }, 15);
        $client = new Client(['handler' => HandlerStack::create(new MockHandler(array_fill(0, 5, new Response(503))))]);
        $http = new HttpSink(HttpTarget::parse('https://logs.example.org/in', [], false, false, null), $client, $retry, 100, 5, 'Authorization', 'Bearer', 'token-123456');

        $failures = $this->dispatcher(['http' => [$http, false]])->dispatch([$this->event()]);

        self::assertSame(['http' => 1], $failures);
        self::assertGreaterThan(0, $waited);
        self::assertLessThanOrEqual(15, $waited);
    }

    /** @param array<string, array{\Watcher\Observe\EventSink, bool}> $sinks */
    private function dispatcher(array $sinks, ?SpyLogger $log = null): SinkDispatcher
    {
        return new SinkDispatcher($sinks, new EventSanitizer(new Redactor(new Normalizer())), $log);
    }

    private function event(): Event
    {
        return new Event('finding', [
            'schema' => 'watcher.event.v2', 'event_type' => 'finding', 'event_id' => '0123456789abcdef-1', 'ts' => '2026-10-24T06:05:00Z',
            'service' => 'bff-a', 'environment' => 'production', 'run_id' => '0123456789abcdef', 'version' => '0.1.70',
            'window_start' => null, 'window_end' => null, 'finding_index' => 1, 'fingerprint' => '0123456789ab', 'fingerprints' => ['0123456789ab'],
            'kind' => 'bug', 'severity' => 'error', 'confidence' => 0.5, 'escalated' => false, 'group_trend' => null, 'group_count' => null,
            'group_previous_count' => null, 'after_release' => false,
        ], ['title' => 'Timeouts']);
    }

    private function recorder(?Throwable $throws = null): RecordingEventSink
    {
        return new RecordingEventSink($throws);
    }
}
