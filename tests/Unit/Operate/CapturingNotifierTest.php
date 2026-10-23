<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Operate;

use PHPUnit\Framework\TestCase;
use Watcher\Notify\Destination;
use Watcher\Notify\Message;
use Watcher\Notify\Notifier;
use Watcher\Operate\CapturingNotifier;

final class CapturingNotifierTest extends TestCase
{
    private const HOOK = 'https://hooks.example/T000/SECRET-TOKEN';

    public function testPrintsExactTextWithDryRunLabelAndNeverTheWebhook(): void
    {
        $out = '';
        $n = new CapturingNotifier(static function (string $s) use (&$out): void {
            $out .= $s;
        });
        $digest = new Message("line1\n<b>& line2", [['kind' => 'header', 'text' => 'H'], ['kind' => 'section', 'text' => 'x']]);
        $notice = new Message('budget hit', [['kind' => 'notice', 'text' => 'budget hit']]);

        self::assertInstanceOf(Notifier::class, $n);
        $n->send($digest, new Destination(self::HOOK));
        $n->send($notice, new Destination(self::HOOK));

        self::assertSame(
            "== DRY RUN message to digest ==\nline1\n<b>& line2\n== DRY RUN message to notice ==\nbudget hit\n",
            $out,
        );
        self::assertStringNotContainsString('SECRET', $out);
        self::assertSame([$digest, $notice], $n->messages());
    }

    public function testTextEndingInNewlineIsNotDoubled(): void
    {
        $out = '';
        $n = new CapturingNotifier(static function (string $s) use (&$out): void {
            $out .= $s;
        });
        $n->send(new Message("a\n"), new Destination(self::HOOK));
        self::assertSame("== DRY RUN message to notice ==\na\n", $out);
    }
}
