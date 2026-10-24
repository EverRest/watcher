<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Fleet;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Watcher\Config\WatchConfig;
use Watcher\Fleet\CorrelatedFinding;
use Watcher\Fleet\CorrelationRenderer;
use Watcher\Fleet\Correlator;
use Watcher\Fleet\ServiceEntry;
use Watcher\Level;
use Watcher\Notify\DigestRenderer;
use Watcher\Privacy\GroupStatusLabel;
use Watcher\Privacy\RedactedGroup;

final class CorrelationRendererTest extends TestCase
{
    private static function group(string $fp, string $tpl, int $n = 12, GroupStatusLabel $s = GroupStatusLabel::Grown): RedactedGroup
    {
        return new RedactedGroup($fp, $tpl, Level::Error, null, [], $n, 9, $s, [], new DateTimeImmutable('2026-10-23T05:41:00Z'), new DateTimeImmutable('2026-10-24T05:58:00Z'));
    }

    /** @param list<string> $consumers */
    private static function finding(array $consumers = ['bff-a'], string $originTemplate = 'connection refused to <HOST>'): CorrelatedFinding
    {
        $entries = [new ServiceEntry(WatchConfig::fromArray(FleetConfigTest::service('api')))];
        $registry = ['api' => [self::group('ab12cd34ef56aabb', $originTemplate, 412)]];
        foreach ($consumers as $c) {
            $entries[] = new ServiceEntry(WatchConfig::fromArray(FleetConfigTest::service($c, ['dependencies' => ['api' => ['stream_tags' => ['app' => 'api']]]])));
            $registry[$c] = [self::group('c' . $c, 'GET <URL:api> returned <N>', 30, GroupStatusLabel::New)];
        }

        return (new Correlator($entries))->findings('platform', $registry)[0];
    }

    public function testRendersTheTemplateWordingAsAHypothesis(): void
    {
        $m = (new CorrelationRenderer())->render(self::finding(), 'UTC');

        $text = implode("\n", array_column($m->sections, 'text'));
        self::assertSame(['header', 'finding', 'footer'], array_column($m->sections, 'kind'));
        self::assertStringContainsString('Probable upstream cause', $text);
        self::assertStringContainsString('(hypothesis)', $text);
        self::assertStringContainsString('api', $text);
        self::assertStringContainsString('ab12cd34ef56', $text);
        self::assertStringContainsString('grown 9 -> 412', $text);
        self::assertStringContainsString('bff-a 30 events (1 group)', $text);
        self::assertStringContainsString('annotated with this origin', $text);
        self::assertStringContainsString('23 Oct 05:41 - 24 Oct 05:58', $text);
    }

    public function testManyConsumersCollapseWithinTheCharacterLimit(): void
    {
        $names = [];
        for ($i = 0; $i < 400; ++$i) {
            $names[] = sprintf('consumer-service-%03d', $i);
        }

        $m = (new CorrelationRenderer())->render(self::finding($names), 'UTC');

        foreach ($m->sections as $s) {
            self::assertLessThanOrEqual(DigestRenderer::MAX_SECTION_CHARS, mb_strlen($s['text']));
        }
        self::assertLessThanOrEqual(DigestRenderer::MAX_TEXT_CHARS, mb_strlen($m->text));
        self::assertMatchesRegularExpression('/\+\d+ more services/', implode("\n", array_column($m->sections, 'text')));
    }

    public function testSlackControlSequencesInTheTemplateAreNeutralised(): void
    {
        $m = (new CorrelationRenderer())->render(self::finding(originTemplate: '<!channel> see https://x.example/a `tick`'), 'UTC');

        $text = implode("\n", array_column($m->sections, 'text'));
        self::assertStringNotContainsString('<!channel>', $text);
        self::assertStringNotContainsString('https://x', $text);
    }
}
