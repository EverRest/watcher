<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Fleet;

use PHPUnit\Framework\TestCase;
use Watcher\Fleet\CollectingNotifier;
use Watcher\Fleet\CombinedDigest;
use Watcher\Notify\Destination;
use Watcher\Notify\Message;
use Watcher\Tests\Fakes\FakeNotifier;

final class CombinedDigestTest extends TestCase
{
    private static function digest(string $service, int $findings): Message
    {
        $sections = [['kind' => 'header', 'text' => "*watcher · $service*"], ['kind' => 'severity', 'text' => '🟠 *error* (' . $findings . ')']];
        for ($i = 1; $i <= $findings; ++$i) {
            $sections[] = ['kind' => 'finding', 'text' => "finding $i of $service"];
        }
        $sections[] = ['kind' => 'footer', 'text' => "footer $service"];

        return new Message("watcher · $service\n$findings findings", $sections);
    }

    public function testNoticesPassThroughAndDigestsAreHeld(): void
    {
        $inner = new FakeNotifier();
        $c = new CollectingNotifier($inner);
        $notice = new Message('⚠️ budget', [['kind' => 'notice', 'text' => '⚠️ budget']]);
        $digest = self::digest('api', 1);

        $c->send($notice, new Destination('https://hooks.invalid/n'));
        $c->send($digest, new Destination('https://hooks.invalid/d'));

        self::assertCount(1, $inner->sent);
        self::assertSame($notice, $inner->sent[0]['message']);
        self::assertSame([$digest], $c->held());
    }

    public function testNothingReportedPostsNothing(): void
    {
        self::assertNull((new CombinedDigest())->build('platform', [], ['api', 'bff-a']));
    }

    public function testOneMessageWithAHeadingPerServiceAndQuietOnesCollapsed(): void
    {
        $m = (new CombinedDigest())->build('platform', ['api' => self::digest('api', 2), 'bff-a' => self::digest('bff-a', 1)], ['bff-b', 'bff-c']);

        self::assertNotNull($m);
        $texts = array_column($m->sections, 'text');
        self::assertStringContainsString('platform', $texts[0]);
        self::assertContains('*api*', $texts);
        self::assertContains('*bff-a*', $texts);
        self::assertContains('finding 2 of api', $texts);
        self::assertContains('finding 1 of bff-a', $texts);
        self::assertNotContains('*watcher · api*', $texts, 'the per-service header is replaced by the service heading');
        $quiet = array_values(array_filter($texts, static fn (string $t): bool => str_contains($t, 'bff-b')));
        self::assertCount(1, $quiet);
        self::assertStringContainsString('bff-c', $quiet[0]);
        self::assertStringContainsString('quiet', $quiet[0]);
        self::assertStringContainsString('api', $m->text);
        self::assertStringContainsString('bff-a', $m->text);
    }

    public function testTwentyServicesOneMessageWithinTheLimits(): void
    {
        $digests = [];
        for ($i = 1; $i <= 20; ++$i) {
            $digests['svc-' . $i] = self::digest('svc-' . $i, $i % 5 + 1);
        }
        $m = (new CombinedDigest())->build('platform', $digests, ['quiet-1']);

        self::assertNotNull($m);
        self::assertLessThanOrEqual(50, count($m->sections));
        self::assertLessThanOrEqual(4000, mb_strlen($m->text));
        $all = implode("\n", array_column($m->sections, 'text'));
        for ($i = 1; $i <= 20; ++$i) {
            self::assertStringContainsString('*svc-' . $i . '*', $all, "svc-$i has a heading");
        }
        // the services with the most findings keep their findings; the smallest are collapsed
        self::assertStringContainsString('finding 5 of svc-4', $all);
        self::assertStringNotContainsString('finding 1 of svc-5', $all);
    }

    public function testMoreServicesThanBlocksMergeTheRest(): void
    {
        $digests = [];
        for ($i = 1; $i <= 80; ++$i) {
            $digests[sprintf('s%02d', $i)] = self::digest(sprintf('s%02d', $i), 1);
        }
        $m = (new CombinedDigest())->build('platform', $digests, []);

        self::assertNotNull($m);
        self::assertLessThanOrEqual(50, count($m->sections));
        self::assertStringContainsString('more services: see their digests', implode("\n", array_column($m->sections, 'text')));
    }
}
