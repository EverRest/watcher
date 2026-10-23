<?php

declare(strict_types=1);

namespace Watcher\Reduce;

/**
 * The single ordered replacement table (research R7), shared by fingerprinting and the Redactor:
 * whatever is normalised away is also never sent.
 *
 * Order deviates from R7 in two places, both for safety:
 *  - URLs run before emails, so `user:pass@host` is one URL, not a URL holding an `<EMAIL>`.
 *  - JWTs run before hex ids, so a hex-looking JWT segment cannot be cut out and leave the rest.
 *
 * Invalid UTF-8 (or a regex failure) throws NormalizationException; never returns raw text.
 */
final class Normalizer
{
    /** Placeholders already written are skipped by every later rule. */
    private const SKIP = '<[A-Z]+(?::[^>\s]*)?>(*SKIP)(*F)|';

    private const FILE_EXT = 'php|json|log|txt|yml|yaml|xml|js|ts|css|html|md|sql|csv|env|lock|sh|py|rb|go|jar|zip|gz';

    private const H = '[0-9a-f]{1,4}';

    /** @var list<string> */
    private array $dependencyHosts;

    /** @var list<string> */
    private array $domains;

    /**
     * @param list<string> $declaredDependencyHosts
     * @param list<string> $dependencyDomains
     */
    public function __construct(array $declaredDependencyHosts = [], array $dependencyDomains = [])
    {
        $this->dependencyHosts = array_map(strtolower(...), $declaredDependencyHosts);
        $this->domains = array_map(static fn (string $d): string => ltrim(strtolower($d), '.'), $dependencyDomains);
    }

    public function normalize(string $text): string
    {
        return $this->scan($text)['text'];
    }

    /**
     * @return array{text: string, changed: bool} `changed` is true when any placeholder was applied
     *                                            (whitespace collapsing alone does not count).
     */
    public function scan(string $text): array
    {
        if (preg_match('//u', $text) !== 1) {
            throw new NormalizationException('Input is not valid UTF-8.');
        }

        $changed = false;
        foreach ($this->rules() as [$pattern, $replace]) {
            $out = preg_replace_callback(
                '#' . self::SKIP . '(?:' . $pattern . ')#iu',
                static function (array $m) use ($replace): string {
                    return is_string($replace) ? $replace : $replace($m);
                },
                $text,
                -1,
                $count,
            );
            if ($out === null) {
                throw new NormalizationException('Pattern failed: ' . preg_last_error_msg());
            }
            $text = $out;
            $changed = $changed || $count > 0;
        }

        $text = preg_replace('/\s+/u', ' ', $text);
        if ($text === null) {
            throw new NormalizationException('Whitespace collapse failed: ' . preg_last_error_msg());
        }

        return ['text' => trim($text), 'changed' => $changed];
    }

    /** @return list<array{string, string|callable(array<int|string,string>): string}> */
    private function rules(): array
    {
        $h = self::H;

        return [
            [
                // scheme://[creds@]host[:port][/path?query#frag]
                '\b[a-z][a-z0-9+.-]*://(?:[^\s/?\#\'"<>]*@)?(?<host>\[[0-9a-f:.]+\]|[^\s/?\#:\'"<>\[\]]+)(?::\d+)?(?:[/?\#][^\s\'"<>]*)?',
                fn (array $m): string => $this->url($m),
            ],
            ['\beyJ[\w-]+\.[\w-]+\.[\w-]+', '<TOKEN>'],
            ['[\w.+-]+@[\w-]+(?:\.[\w-]+)+', '<EMAIL>'],
            ['\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b', '<ID>'],
            ['\b(?=[0-9a-f]*[a-f])[0-9a-f]{8,}\b', '<ID>'],
            ['\b(?:\d{1,3}\.){3}\d{1,3}\b', '<IP>'],
            ["(?<![\\w:])(?:(?:$h:){7}$h|(?:$h:){1,7}:(?:$h(?::$h){0,6})?|::$h(?::$h){0,6})(?![\\w:])", '<IP>'],
            [
                // bare FQDN: >= 2 labels, alphabetic 2+ letter last label that is not a file extension
                '(?<![\w\\\\.-])(?<host>[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)*\.(?!(?:' . self::FILE_EXT . ')(?![a-z]))[a-z]{2,})(?![\w-]|\.\w)',
                fn (array $m): string => $this->hostPlaceholder(strtolower($m['host']), 'HOST'),
            ],
            ['\b(?:\d[ -]?){12,18}\d\b', '<PAN>'],
            ['\d+', '<N>'],
            ['"[^"]{33,}"', '<STR>'],
            ['(?<!\w)\'[^\']{33,}\'(?!\w)', '<STR>'],
        ];
    }

    /** @param array<int|string,string> $m */
    private function url(array $m): string
    {
        $tail = preg_match('/[.,;:!?)\]]+$/', $m[0], $t) === 1 ? $t[0] : '';
        $host = strtolower(rtrim($m['host'], '.,;:!?)'));

        if (str_starts_with($host, '[') || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return '<IP>' . $tail;
        }

        return $this->hostPlaceholder($host, 'URL') . $tail;
    }

    /** Keep the name only for single-label, declared dependency or dependency-domain hosts. */
    private function hostPlaceholder(string $host, string $kind): string
    {
        $keep = preg_match('/^[a-z0-9-]+$/', $host) === 1
            || in_array($host, $this->dependencyHosts, true);
        foreach ($this->domains as $d) {
            $keep = $keep || $host === $d || str_ends_with($host, '.' . $d);
        }

        return $keep ? '<' . $kind . ':' . $host . '>' : '<' . $kind . '>';
    }
}
