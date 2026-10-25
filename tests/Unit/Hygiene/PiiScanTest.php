<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Hygiene;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Watcher\Hygiene\Category;
use Watcher\Hygiene\PiiScan;
use Watcher\Reduce\Normalizer;

final class PiiScanTest extends TestCase
{
    private static function scan(): PiiScan
    {
        return new PiiScan(new Normalizer(['payments'], ['svc.cluster.local'], extraPatterns: ['ORDER' => '/ORD-\d+/']), ['ORDER']);
    }

    /** @return iterable<string, array{string, list<Category>}> */
    public static function lines(): iterable
    {
        yield 'e-mail' => ['mailed anna.kowalski@example.org today', [Category::Email]];
        yield 'token by prefix' => ['key ghp_16C7e42F292c6912E7710c838347A', [Category::Token]];
        yield 'bearer header' => ['Authorization: Bearer 9f8e7d6c5b4a39281706f5e4d3c2b1a0', [Category::Token]];
        yield 'ipv4' => ['from 203.0.113.42', [Category::Address]];
        yield 'ipv6' => ['from 2001:db8:85a3::8a2e:370:7334', [Category::Address]];
        yield 'card-like' => ['card 4111 1111 1111 1111', [Category::Card]];
        yield 'bare host' => ['resolved api.vendor.example', [Category::Host]];
        yield 'undeclared url' => ['GET https://tenant.example.org/a?x=1', [Category::Host]];
        yield 'declared dependency is allowed' => ['GET http://payments/charge failed', []];
        yield 'declared dependency under the cluster domain' => ['GET http://payments.svc.cluster.local/charge', []];
        yield 'user directory' => ['open /home/olena/notes.txt', [Category::Other]];
        yield 'extra pattern' => ['order ORD-77 failed', [Category::Other]];
        yield 'phone with plus and spaces' => ['sms +380 67 123 45 67', [Category::Phone]];
        yield 'phone with plus, no spaces' => ['sms +380501234567', [Category::Phone]];
        yield 'phone with brackets' => ['sms +1 (415) 555-0132', [Category::Phone]];
        yield 'a date is no phone' => ['at 2026-10-07 10:00:01', []];
        yield 'a growth figure is no phone' => ['cache +5 entries', []];
        yield 'plain digits' => ['order 1001 created in 35 ms', []];
        yield 'uuid is no category' => ['job 3f2504e0-4f89-11d3-9a0c-0305e82c3301 done', []];
        yield 'several' => ['anna@example.org from 203.0.113.42 sms +380 67 123 45 67', [Category::Email, Category::Address, Category::Phone]];
        yield 'one category twice counts once' => ['a@example.org b@example.org', [Category::Email]];
        yield 'clean' => ['Order created', []];
        yield 'empty' => ['', []];
    }

    /** @param list<Category> $expected */
    #[DataProvider('lines')]
    public function testItNamesTheCategoriesOfALine(string $text, array $expected): void
    {
        self::assertSame($expected, self::scan()->categories($text));
    }

    public function testTextThatCannotBeScannedYieldsNothingAndDoesNotThrow(): void
    {
        self::assertSame([], self::scan()->categories("bad \xB1\xB2 bytes anna@example.org"));
    }

    public function testTheResultHoldsNoPartOfTheInput(): void
    {
        $out = self::scan()->categories('anna.kowalski@example.org +380 67 123 45 67');

        self::assertSame(['email', 'phone'], array_map(static fn (Category $c): string => $c->value, $out));
    }

    public function testCategoryNamesAreTheDocumentedClosedSet(): void
    {
        self::assertSame(['email', 'token', 'address', 'phone', 'card', 'host', 'other'], array_map(static fn (Category $c): string => $c->value, Category::cases()));
    }

    public function testARepeatedTextIsScannedOnceAndGivesTheSameAnswer(): void
    {
        $scan = self::scan();
        $first = $scan->categories('mailed anna@example.org from 203.0.113.42');
        $second = $scan->categories('mailed anna@example.org from 203.0.113.42');

        self::assertSame($first, $second);
        self::assertSame([Category::Email, Category::Address], $second);
    }

    public function testAnAlreadyMaskedMessageIsNotNormalisedAgain(): void
    {
        $n = new Normalizer();
        $scan = new PiiScan($n);
        $raw = 'mailed anna@example.org';

        self::assertSame($scan->categories($raw), $scan->categories($raw, $n->normalize($raw)));
    }
}
