<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Hygiene;

use PHPUnit\Framework\TestCase;
use Watcher\Hygiene\LiteralFloor;

/** The word-level privacy floor of a shown template (review M-2, second review H-2). */
final class LiteralFloorTest extends TestCase
{
    public function testVocabularyAndExactPlaceholdersStayAndEveryOtherWordBecomesW(): void
    {
        $floor = new LiteralFloor([]);

        self::assertSame('user <w> <w> logged in reason <*>', $floor->mask('user Ivan Petrenko logged in reason <*>'));
        self::assertSame('login failed for <EMAIL> from <IP> after <N> attempts', $floor->mask('login failed for <EMAIL> from <IP> after <N> attempts'));
        self::assertSame('failed <ID> <HOST> <URL> <TOKEN> <PATH> <w>', $floor->mask('failed <ID> <HOST> <URL> <TOKEN> <PATH> <w>'));
    }

    public function testAWordSeenAtEnoughSitesIsCommon(): void
    {
        self::assertSame('<w> sync done', (new LiteralFloor([]))->mask('Petrenko sync done'));
        self::assertSame('Petrenko sync done', (new LiteralFloor(['petrenko' => true]))->mask('Petrenko sync done'));
    }

    /** @return array<string, array{string, string}> */
    public static function angleBracketText(): array
    {
        return [
            'repr with a name' => ['for <User: ivan_petrenko> failed', 'for <User: <w>> failed'],
            'repr with a full name' => ['for <Customer: Ivan Petrenko> failed', 'for <Customer: <w> <w>> failed'],
            'attributes' => ['for <Person firstName=Ivan lastName=Petrenko> failed', 'for <<w> <w>=<w> <w>=<w>> failed'],
            'one identifier' => ['user lookup <IvanPetrenko> failed', 'user lookup <<w>> failed'],
            'nested' => ['user lookup <<Ivan>> failed', 'user lookup <<<w>>> failed'],
            'upper case name' => ['user lookup <IVAN> failed', 'user lookup <<w>> failed'],
            'placeholder look-alike' => ['user lookup <EMAILX> failed', 'user lookup <<w>> failed'],
            'lower-case placeholder look-alike' => ['user lookup <emailx> failed', 'user lookup <<w>> failed'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('angleBracketText')]
    public function testOnlyExactPlaceholdersAreSkippedAnythingElseInAngleBracketsIsText(string $in, string $out): void
    {
        self::assertSame($out, (new LiteralFloor([]))->mask($in));
    }

    public function testAPluralIsAcceptedOnlyForAVocabularyWordAndSurnameLikeWordsAreNotVocabulary(): void
    {
        $floor = new LiteralFloor([]);

        self::assertSame('errors users', $floor->mask('errors users'));
        self::assertSame('<w> <w> <w>', $floor->mask('Smalls Larges Pools'));
        self::assertSame('<w> <w> <w> <w>', $floor->mask('small large pool lock'));
        self::assertSame('<w>', $floor->mask('failedsss'), 'a run of trailing s on a vocabulary word is not a plural');
    }
}
