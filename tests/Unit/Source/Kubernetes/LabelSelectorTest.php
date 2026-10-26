<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Source\Kubernetes;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Watcher\Source\Kubernetes\LabelSelector;

final class LabelSelectorTest extends TestCase
{
    /** @return array<string, array{string, array<string, string>, bool}> */
    public static function cases(): array
    {
        return [
            'equals' => ['app=api', ['app' => 'api'], true],
            'equals other' => ['app=api', ['app' => 'bff'], false],
            'double equals' => ['app==api', ['app' => 'api'], true],
            'missing key never equals' => ['app=api', [], false],
            'not equals' => ['app!=api', ['app' => 'bff'], true],
            'not equals missing key' => ['app!=api', [], true],
            'not equals same' => ['app!=api', ['app' => 'api'], false],
            'in' => ['app in (api,bff)', ['app' => 'bff'], true],
            'in no match' => ['app in (api,bff)', ['app' => 'web'], false],
            'in spaces' => ['app in ( api , bff )', ['app' => 'api'], true],
            'notin' => ['app notin (api,bff)', ['app' => 'web'], true],
            'notin hit' => ['app notin (api,bff)', ['app' => 'api'], false],
            'notin missing key' => ['app notin (api)', [], true],
            'exists' => ['app', ['app' => ''], true],
            'exists missing' => ['app', [], false],
            'not exists' => ['!canary', [], true],
            'not exists hit' => ['!canary', ['canary' => 'true'], false],
            'several and' => ['app=api,tier in (web,edge),!canary', ['app' => 'api', 'tier' => 'web'], true],
            'several one fails' => ['app=api,tier in (web,edge)', ['app' => 'api', 'tier' => 'db'], false],
            'prefixed key' => ['app.kubernetes.io/name=api', ['app.kubernetes.io/name' => 'api'], true],
            'empty value' => ['tier=', ['tier' => ''], true],
        ];
    }

    /** @param array<string, string> $labels */
    #[DataProvider('cases')]
    public function testMatches(string $selector, array $labels, bool $expected): void
    {
        self::assertSame($expected, LabelSelector::parse($selector)->matches($labels));
    }

    /** @return array<string, array{string}> */
    public static function invalid(): array
    {
        return [
            'empty' => [''],
            'blank requirement' => ['app=api,,x=y'],
            'unbalanced' => ['app in (api'],
            'bad operator' => ['app ~ api'],
            'bad key' => ['-bad=1'],
            'bad value' => ['app=a b'],
            'in without list' => ['app in api'],
            'empty list' => ['app in ()'],
            'too long key' => [str_repeat('a', 64) . '=x'],
        ];
    }

    #[DataProvider('invalid')]
    public function testInvalidSelectorIsRejected(string $selector): void
    {
        $this->expectException(InvalidArgumentException::class);
        LabelSelector::parse($selector);
    }

    public function testTextIsKeptForTheServerFilter(): void
    {
        self::assertSame('app in (api,bff)', LabelSelector::parse(' app in (api,bff) ')->text());
    }
}
