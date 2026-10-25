<?php

declare(strict_types=1);

namespace Watcher\Hygiene;

use Watcher\Reduce\Normalizer;

/**
 * Which kinds of personal-data pattern a piece of text holds, read off the Normalizer's own placeholders (research R2):
 * the report cannot disagree with what is masked, and nothing of the match is kept, only the category.
 * Text that cannot be normalised yields nothing (fail closed: it is neither counted nor stored).
 */
final class PiiScan
{
    /** A phone number written with a plus sign: `+` then nine or more digits, spaces, dashes or brackets. */
    private const PHONE = '/(?<![\w.+])\+\d[\d ()\-]{7,}\d/';

    private readonly string $other;

    /** Texts that could not be normalised (invalid UTF-8, a rule failure): not counted in any category, but counted here. */
    public int $unscannable = 0;

    /** @param list<string> $extraPatternNames names of `redaction.extra_patterns`; their placeholders count as `other`, as does a user directory (`<USER>`). A path (`<PATH>`) is no category. */
    public function __construct(private readonly Normalizer $normalizer, array $extraPatternNames = [])
    {
        $names = array_map(static fn (string $n): string => preg_quote(strtoupper($n), '/'), $extraPatternNames);
        $this->other = '/<(?:USER' . ($names === [] ? '' : '|' . implode('|', $names)) . ')>/';
    }

    private const MEMO = 4096;

    /** Texts up to this long are remembered: field values repeat heavily (review M5). */
    private const MEMO_LENGTH = 512;

    /** @var array<string, list<Category>> */
    private array $memo = [];

    /**
     * @param string|null $masked the Normalizer's output for `$text` when the caller already has it (it is not computed twice)
     * @return list<Category> in the order of the enum, each at most once
     */
    public function categories(string $text, ?string $masked = null): array
    {
        $key = strlen($text) <= self::MEMO_LENGTH ? $text : null;
        if ($key !== null && isset($this->memo[$key])) {
            return $this->memo[$key];
        }
        $found = $this->classify($text, $masked);
        if ($key !== null) {
            if (count($this->memo) >= self::MEMO) {
                $this->memo = [];
            }
            $this->memo[$key] = $found;
        }

        return $found;
    }

    /** @return list<Category> */
    private function classify(string $text, ?string $masked): array
    {
        $masked ??= $this->normalizer->normalizeOrUnscannable($text);
        if ($masked === '<UNSCANNABLE>') {
            ++$this->unscannable;

            return [];
        }
        $found = [
            Category::Email->value => str_contains($masked, '<EMAIL>'),
            Category::Token->value => str_contains($masked, '<TOKEN>'),
            Category::Address->value => str_contains($masked, '<IP>'),
            Category::Phone->value => preg_match(self::PHONE, $text) === 1,
            Category::Card->value => str_contains($masked, '<PAN>'),
            // A bare placeholder: `<HOST:name>` / `<URL:name>` is a declared dependency and may be named.
            Category::Host->value => str_contains($masked, '<HOST>') || str_contains($masked, '<URL>'),
            Category::Other->value => preg_match($this->other, $masked) === 1,
        ];

        return array_values(array_filter(Category::cases(), static fn (Category $c): bool => $found[$c->value]));
    }
}
