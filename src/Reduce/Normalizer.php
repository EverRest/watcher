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
 *
 * Input longer than MAX_CHARS characters is cut at that length before any rule runs (bounded work on hostile
 * lines; a log message is a few hundred characters, the tail of a longer one is never read or sent).
 *
 * A host becomes `<URL:name>` / `<HOST:name>` (the DECLARED name only, never a suffix) if it equals a declared
 * dependency name or `name.<domain>` / `name.<one label>.<domain>` for a configured domain; every other host is
 * masked as `<URL>` / `<HOST>`.
 */
final class Normalizer
{
    /**
     * Placeholders THIS class wrote are skipped by every later rule: exact names, case-sensitive,
     * host-shaped payload only. Angle brackets in the input are neutralised first (see scan()), so
     * placeholder-looking input text can never hide a value from the rules.
     */
    private const SKIP_NAMES = 'EMAIL|URL|HOST|IP|ID|TOKEN|PAN|N|STR|PATH|USER';

    /** Private-use stand-ins for '<' and '>' of the input while the rules run. */
    /** Characters kept of any one input; the rest is dropped before the rules run. */
    public const MAX_CHARS = 4096;

    private const LT = "\u{E000}";

    private const GT = "\u{E001}";

    /** Character-class members that end a URL token (angle brackets and their stand-ins). */
    private const ANG = '<>\x{E000}\x{E001}';

    private const FILE_EXT = 'php|json|log|txt|yml|yaml|xml|js|ts|css|html|sql|csv|env|lock|rb|jar|gz';

    /** Characters that end a path segment (inside a negated class). */
    private const SEG = '\s\/\\\\\'"()\[\]{}<>,;:|\x{E000}\x{E001}';

    private const H = '[0-9a-f]{1,4}';

    /**
     * One path segment of plain words, at least as strict as looksRandom's identifier test: words of 2+ letters
     * (camelCase / snake / kebab), digit groups of at most 3 separated from the word before them, at most 3 digits
     * in all, no run of 6 consonants.
     */
    private const WORDS = '/^(?!(?:\D*+\d){4})(?![^b-df-hj-np-tv-xz]*+[b-df-hj-np-tv-xz]{6})'
        . '(?:[A-Z]?+[a-z]{2,}+|\d{1,3}+(?!\d))(?:[A-Z]?+[a-z]{2,}+|[_+-](?:[A-Z]?+[a-z]{2,}+|\d{1,3}+(?!\d)))*+$/';

    /** Digit-bearing path parts that are not secrets: a trailing `-2026-10-23` date and CPU architecture names. */
    private const KNOWN = '/[_-]\d{4}-\d{2}-\d{2}$|\b(?:x86_64|amd64|arm64|aarch64|i[3-6]86)\b/';

    /** @var list<string> */
    private array $dependencyHosts;

    /** @var list<string> */
    private array $domains;

    /** @var array<string, string> */
    private array $extraPatterns;

    private string $skip;

    /** Skips an extra placeholder of an earlier scan (its angle brackets are inert stand-ins by now) before digits are masked. */
    private string $inertExtra = '';

    /**
     * @param list<string> $declaredDependencyHosts declared dependency names; a host is recognised as one when it equals it or sits under a domain
     * @param list<string> $dependencyDomains       suffixes allowed after a declared name and at most one label (`payments.prod.svc.cluster.local`);
     *                                              the name is kept, the suffix never
     * @param array<string, string> $extraPatterns name => PCRE with delimiters; matched on the raw text BEFORE any rule
     *                                             (so `/CUST-\d{6}/` still sees its digits) and replaced by `<NAME>`
     */
    public function __construct(array $declaredDependencyHosts = [], array $dependencyDomains = [], private readonly bool $maskNumbers = true, array $extraPatterns = [])
    {
        $this->extraPatterns = $extraPatterns;
        $names = array_map(static fn ($n): string => preg_quote(strtoupper((string) $n), '#'), array_keys($extraPatterns));
        $this->skip = '<(?-i:(?:' . self::SKIP_NAMES . ($names === [] ? '' : '|' . implode('|', $names)) . ')(?::[a-z0-9._-]+)?)>(*SKIP)(*F)|';
        if ($names !== []) {
            $this->inertExtra = '\x{E000}(?-i:' . implode('|', $names) . ')\x{E001}(*SKIP)(*F)|';
        }
        $this->dependencyHosts = array_map(strtolower(...), $declaredDependencyHosts);
        $this->domains = array_map(static fn (string $d): string => ltrim(strtolower($d), '.'), $dependencyDomains);
    }

    /** Same table, but plain numbers stay readable (for text written by the model, which never saw personal data). */
    public function keepingNumbers(): self
    {
        return new self($this->dependencyHosts, $this->domains, false, $this->extraPatterns);
    }

    /** @param array<string, string> $extraPatterns */
    public function withExtraPatterns(array $extraPatterns): self
    {
        return new self($this->dependencyHosts, $this->domains, $this->maskNumbers, $extraPatterns);
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

        if (mb_strlen($text, 'UTF-8') > self::MAX_CHARS) {
            // A value straddling the cut would no longer match any rule: drop the trailing partial token too.
            $text = (string) preg_replace('/\S*\z/u', '', mb_substr($text, 0, self::MAX_CHARS, 'UTF-8'));
        }

        // Input '<' and '>' (and any stray stand-ins) become inert so only our own placeholders are '<...>'.
        $text = strtr($text, [self::LT => "\u{FFFD}", self::GT => "\u{FFFD}"]);
        $text = strtr($text, ['<' => self::LT, '>' => self::GT]);

        $changed = false;
        foreach ($this->extraPatterns as $name => $pattern) {
            $placeholder = '<' . strtoupper((string) $name) . '>';
            $out = @preg_replace_callback($pattern, static fn (): string => $placeholder, $text, -1, $count);
            if ($out === null) {
                throw new NormalizationException('Extra pattern failed: ' . $name);
            }
            $text = $out;
            $changed = $changed || $count > 0;
        }
        foreach ($this->rules() as [$pattern, $replace]) {
            $out = preg_replace_callback(
                '#' . $this->skip . '(?:' . $pattern . ')#iu',
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

        return ['text' => strtr(trim($text), [self::LT => '<', self::GT => '>']), 'changed' => $changed];
    }

    /** @return list<array{string, string|callable(array<int|string,string>): string}> */
    private function rules(): array
    {
        $h = self::H;

        $ang = self::ANG;

        return [
            [
                // scheme://user:password@... where the password is NOT RFC-encoded and holds a / ? or #.
                // After 'user:' (a non-port value) a / ? # appears before a later '@': that is a password
                // swallowing the authority, so the whole token is dropped. RFC-compliant credentials fall
                // through to the next rule; ports and '@' in a query (http://h:80/x?e=a@b) are not matched.
                '\b[a-z][a-z0-9+.-]*://[^\s/?\#@\'"' . $ang . ']*:(?!\d+(?:[/?\#]|$|\s))[^\s/?\#\'"' . $ang . ']*[/?\#][^\s\'"' . $ang . ']*@[^\s\'"' . $ang . ']*',
                '<URL>',
            ],
            [
                // scheme://[creds@]host[:port][/path?query#frag]
                '\b[a-z][a-z0-9+.-]*://(?:[^\s/?\#\'"' . $ang . ']*@)?(?<host>\[[0-9a-f:.]+\]|[^\s/?\#:\'"' . $ang . '\[\]]+)(?::\d+)?(?:[/?\#][^\s\'"' . $ang . ']*)?',
                fn (array $m): string => $this->url($m),
            ],
            // A user directory in an absolute path: the name goes, the root stays (`/root` is no user).
            [
                '(?<![\w.\/\\\\<>' . "\x{E000}\x{E001}" . '-])((?:\/home\/|\/Users\/|[a-z]:[\\\\\/]Users[\\\\\/]))[^' . self::SEG . ']++',
                fn (array $m): string => $m[1] . '<USER>',
            ],
            // Any other absolute path of 3+ segments: only the last two (plain) segments are kept.
            [
                '(?<![\w.\/\\\\<>~' . "\x{E000}\x{E001}" . '-])(?:[a-z]:)?(?:[\/\\\\]{1,2}+(?:<USER>|[^' . self::SEG . ']++)){3,}+',
                static fn (array $m): string => self::pathPlaceholder($m[0]),
            ],
            ['\beyJ[\w-]+\.[\w-]+\.[\w-]+', '<TOKEN>'],
            // Known-prefix secrets (case-sensitive; bodies are never needed for analysis).
            [
                '(?-i:\b(?:sk-(?:ant-)?+[A-Za-z0-9_-]{8,}+|gh[pousr]_[A-Za-z0-9]{8,}+|github_pat_[A-Za-z0-9_]{8,}+|A[KS]IA[0-9A-Z]{12,}+'
                . '|xox[abprs]-[A-Za-z0-9-]{8,}+|glpat-[A-Za-z0-9_-]{8,}+|AIza[A-Za-z0-9_-]{16,}+))',
                '<TOKEN>',
            ],
            // PEM private key: the whole block; an unterminated one is cut to the end of the (capped) input.
            ['(?s:-----BEGIN [A-Z ]{0,40}PRIVATE KEY-----.*?(?:-----END [A-Z ]{0,40}PRIVATE KEY-----|\z))', '<TOKEN>'],
            ['\b(?:ssh-(?:rsa|dss|ed25519)|ecdsa-sha2-nistp\d++)\s++AAAA[A-Za-z0-9+/=]++', '<TOKEN>'],
            // Credential headers: a known scheme word stays; the REST of the value (commas, spaces, quotes inside
            // a quoted JSON value included) goes, up to the end of the line or the closing quote.
            [
                '(\b(?:authorization|api-key|x-auth-token|set-cookie|cookie)["\']?+\s*+(?:=?+\x{E001}|[:=\]])\s*+(?>(["\'])|)'
                . '(?:(?:bearer|basic|token|digest|negotiate|ntlm|apikey|hawk|oauth|aws4-hmac-sha256)\s++)?+)'
                . '(?(2)(?:\\\\.|(?!\2)[^\r\n])++|[^\r\n]++)',
                fn (array $m): string => $m[1] . '<TOKEN>',
            ],
            ['(\bbearer\s++)(?!<)[\w.~+/=-]{8,}+', fn (array $m): string => $m[1] . '<TOKEN>'],
            ['(\b(?:basic|token)\s++)(?!<)(?=[\w.~+/=-]*[\d+/=])[\w.~+/=-]{8,}+', fn (array $m): string => $m[1] . '<TOKEN>'],
            // Letters only: needs both cases with a capital past the first letter (so `Authentication` stays) and 12+ chars.
            ['(\b(?:basic|token)\s++)(?-i:(?=[\w.~+/=-]*[a-z])(?=[\w.~+/=-]+[A-Z]))[\w.~+/=-]{12,}+', fn (array $m): string => $m[1] . '<TOKEN>'],
            // `basic` is followed by base64 by definition: any 8+ base64 run that is not one plain word.
            ['(\bbasic\s++)(?!(?-i:[a-z]++|[A-Z][a-z]++|[A-Z]++)(?![A-Za-z0-9+/=_-]))[A-Za-z0-9+/]{8,}+={0,2}+', fn (array $m): string => $m[1] . '<TOKEN>'],
            // Credentials by KEY name, any value length or case: `password=x`, `"secret":"x"`, `X-Amz-Security-Token: x`,
            // `?token=x&sig=x`. The key stays, the value (quoted: up to the closing quote; else up to space , ; & " ' } ]) goes.
            [
                '((?<![\w-])(?:[\w-]{0,30}?(?:pass(?:word|wd|phrase)?+|pwd|secret|api[_-]?key|access[_-]?key|private[_-]?key|credentials?+|token|signature)[\w-]{0,30}'
                . '|(?:x|dd|ocp|proxy)-[\w-]{0,30}?(?:key|sig)[\w-]{0,30}|(?<=[?&])(?:key|sig|auth))'
                . '["\']?+\s*+(?:=>|[:=])\s*+)(?:(["\'])((?:\\\\.|(?!\\2)[^\r\n])*+)(?:\\2|$)|(?!<|(?:bearer|basic|token|digest)\s++<)[^\s,;&"\'}\]]++)',
                static fn (array $m): string => $m[1] . (($m[2] ?? '') !== '' ? $m[2] . '<TOKEN>' . $m[2] : '<TOKEN>'),
            ],
            // A relative request line's query string: the keys stay, every value goes.
            [
                '(?<=[\w\/.-])(\?)([\w.%\[\]-]++=[^\s\'"\#' . $ang . ']*+(?:&[\w.%\[\]-]++=[^\s\'"\#' . $ang . ']*+)*+)',
                static fn (array $m): string => $m[1] . (string) preg_replace('/=[^&]*+/', '=<STR>', $m[2]),
            ],
            ['(?<![\w.+-])(?:"[^"\r\n@]{1,64}"|[\w.+-]++)@[\w-]++(?:\.[\w-]++)*+', '<EMAIL>'],
            ['\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b', '<ID>'],
            ['\b(?=[0-9a-f]*[a-f])[0-9a-f]{8,}\b', '<ID>'],
            ['\b(?:\d{32}|\d{40}|\d{64})\b', '<ID>'], // md5 / sha1 / sha256 that happen to hold no a-f
            ['\b(?:\d{1,3}\.){3}\d{1,3}\b', '<IP>'],
            ["(?<![\\w:])(?:(?:$h:){7}$h|(?:$h:){1,7}:(?:$h(?::$h){0,6})?|::$h(?::$h){0,6})(?![\\w:])", '<IP>'],
            [
                // bare FQDN: >= 2 labels, alphabetic 2+ letter last label that is not a file extension
                '(?<![\w\\\\.-])(?<host>(?:[a-z0-9][a-z0-9-]*+\.)+(?!(?:' . self::FILE_EXT . ')(?![a-z]))[a-z]{2,})(?![\w-]|\.\w)',
                fn (array $m): string => $this->hostPlaceholder(strtolower($m['host']), 'HOST'),
            ],
            ['\b(?:\d[ -]?){12,18}\d\b', '<PAN>'],
            // Any other long random-looking run (base64, base64url, mixed-case ids). Before the digit rule: digits are part of it.
            [
                '(?<![A-Za-z0-9_+/-])[A-Za-z0-9_+/-]{20,}+={0,2}+(?![A-Za-z0-9_+/=-])',
                static fn (array $m): string => self::cutRun($m[0]),
            ],
            ...($this->maskNumbers ? [[$this->inertExtra . '\d+', '<N>']] : []),
            ['"[^"]{33,}"', '<STR>'],
            ['(?<!\w)\'[^\']{33,}\'(?!\w)', '<STR>'],
        ];
    }

    /** A path segment that may be sent: plain characters, not `.`/`..`, not random-looking (digit runs and the extension do not count). */
    public static function plainSegment(string $seg): bool
    {
        $shape = (string) preg_replace(['/\.[A-Za-z0-9]{1,5}$/', '/\d+/', '/\./'], ['', '0', '_'], $seg);

        return $seg !== '..' && $seg !== '.' && preg_match('/^[A-Za-z0-9_.-]++$/D', $seg) === 1 && !self::looksRandom($shape);
    }

    private static function pathPlaceholder(string $path): string
    {
        $segments = array_values(array_filter(preg_split('#[\\\\/]+#', preg_replace('#^[a-z]:#i', '', $path) ?? $path) ?: [], static fn (string $x): bool => $x !== ''));
        // A secret split over short segments is no path: judge the whole run as cutRun() does.
        if (self::looksRandom((string) preg_replace([self::KNOWN, '/\.[A-Za-z0-9]{1,5}$/'], ['ab', ''], str_replace('<USER>', 'user', implode('/', $segments))))) {
            return '<TOKEN>';
        }
        $tail = array_slice($segments, -2);
        foreach ($tail as $seg) {
            if ($seg !== '<USER>' && !self::plainSegment($seg)) {
                return '<PATH>';
            }
        }

        return '<PATH>/…/' . implode('/', $tail);
    }

    /**
     * A run with slashes is split into path segments only when it clearly is a path: rooted (`/`, `./`, `~/`, `../`)
     * or made of plain words only. Anything else is judged whole, with and without its slashes, so a base64 value
     * cannot hide behind short segments. Doubt means masked.
     */
    private static function cutRun(string $run): string
    {
        $cut = static fn (string $part): string => strlen($part) >= 20 && self::looksRandom($part) ? '<TOKEN>' : $part;
        if (!str_contains($run, '/')) {
            return $cut($run);
        }

        $rooted = preg_match('#^(?:\.{0,2}+/|~/)#', $run) === 1;
        $segments = $rooted || substr_count($run, '/') >= 2 ? explode('/', $run) : [$run];
        $parts = array_map($cut, $segments);
        $odd = array_filter($segments, static fn (string $p): bool => $p !== '' && preg_match(self::WORDS, preg_replace(self::KNOWN, 'ab', $p) ?? $p) !== 1);

        // Segment by segment only when every kept segment is plain words: a secret split across short segments is no path.
        if ($odd === [] || ($rooted && $parts !== $segments && array_diff_key($odd, array_diff_assoc($parts, $segments)) === [])) {
            return implode('/', $parts);
        }
        // Not clearly a path, or a rooted one with an odd kept segment: judge the whole run.
        $whole = rtrim(ltrim($run, './~'), '=');

        return self::looksRandom($whole) || $parts !== $segments ? '<TOKEN>' : $run;
    }

    /**
     * At least two of lower / upper / digit, Shannon entropy >= 3.5 bits per char, and not a plain identifier
     * (camelCase or snake / kebab words, at most 3 digits, no run of 6 consonants). Doubt means masked.
     */
    public static function looksRandom(string $s): bool
    {
        $classes = (preg_match('/[a-z]/', $s) === 1 ? 1 : 0) + (preg_match('/[A-Z]/', $s) === 1 ? 1 : 0) + (preg_match('/\d/', $s) === 1 ? 1 : 0);
        if ($classes < 2) {
            return false;
        }

        $len = strlen($s);
        $entropy = 0.0;
        foreach (array_count_values(str_split($s)) as $n) {
            $entropy -= ($n / $len) * log($n / $len, 2);
        }
        if ($entropy < 3.5) {
            return false;
        }

        $words = rtrim($s, '=');
        $identifier = preg_match('/^(?:[A-Z]?+[a-z]++|\d{1,2}+(?!\d))++(?:[_+\/-](?:[A-Z]?+[a-z]++|\d{1,2}+(?!\d))++)*+$/', $words) === 1
            && preg_match_all('/\d/', $words) <= 3
            && preg_match('/[b-df-hj-np-tv-xz]{6}/', $words) !== 1;

        return !$identifier;
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

    /**
     * Only the declared name is ever carried, never a suffix: a host is that dependency when it equals the name or is
     * `name.<d>` / `name.<one label>.<d>` for a configured domain `<d>`. Everything else is masked.
     */
    private function hostPlaceholder(string $host, string $kind): string
    {
        foreach ($this->dependencyHosts as $name) {
            if ($host === $name) {
                return '<' . $kind . ':' . $name . '>';
            }
            foreach ($this->domains as $d) {
                if (preg_match('/^' . preg_quote($name . '.', '/') . '(?:[a-z0-9-]+\.)?' . preg_quote($d, '/') . '$/', $host) === 1) {
                    return '<' . $kind . ':' . $name . '>';
                }
            }
        }

        return '<' . $kind . '>';
    }
}
