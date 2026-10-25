<?php

declare(strict_types=1);

namespace Watcher\Source\Kubernetes;

use Watcher\Reduce\Normalizer;

/**
 * Deterministic Kubernetes masking (spec 008, research R5), applied to event text after Normalizer::canonical() and before the Normalizer's own rules: it knows the pod
 * and node names of the same read, which the generic rules cannot. Pod names keep only their workload prefix
 * (`api-7d9f8c6b5-x2k4q` -> `api-<POD>`), so N pods of one workload become one text. Returns '' when the text is not valid
 * UTF-8 (the caller drops the event: fail closed).
 */
final class EventMasker
{
    public const MAX_INPUT = 2000;

    /** Exactly the placeholders this class writes, case-sensitive (the patterns that use it are /i): `<payments>` is a name, not ours. */
    private const PLACEHOLDER = '(?-i:<(?:STR|URL|ID|POD|NODE|NS|IMAGE|REGISTRY|TAG)>)';
    private const HASH = '(?:(?=[a-z]*\d)|(?=[bcdfghjklmnpqrstvwxz]{8,10}-))[a-z0-9]{8,10}';
    /** Words that follow `container`, `volume` and the like in ordinary sentences and name nothing. */
    private const WORDS = 'was|is|are|were|restarted|image|with|in|failed|started|created|killed|exited|status|has|have|had|ran|crashed|id|runtime|logs?|config|port|probe|not|that|which|for|to|and|or|the|a|an|will|did|cannot|could|terminated|stopped|ready|waiting|running|volumes?|mount|type|must|exists|attach|attachment|stopping|killing|restarting';
    private const IMAGE_LIKE = '#^[A-Za-z0-9][A-Za-z0-9._-]*(?::\d{1,5})?(?:/[A-Za-z0-9._-]+)*(?::[A-Za-z0-9._-]+)?(?:@sha256:[0-9a-f]{64})?$#';

    /** @var array<string, string> pod name => its masked form, for the lists given last */
    private array $podSet = [];

    /** @var array<string, true> */
    private array $nodeSet = [];

    /** @var list<string>|null */
    private ?array $preparedPods = null;

    /** @var list<string>|null */
    private ?array $preparedNodes = null;

    /**
     * @param list<string> $namespaces      shown as given (the Operator wrote them); any other namespace in front of a pod is <NS>
     * @param list<string> $allowRegistries registry hosts kept in image references
     * @param list<string> $allowImages     repository paths (without registry) kept in image references
     */
    public function __construct(
        private readonly array $namespaces,
        private readonly array $allowRegistries = [],
        private readonly array $allowImages = [],
    ) {
    }

    /**
     * @param list<string> $podNames  pods of the same read (exact names)
     * @param list<string> $nodeNames nodes of the same read
     * @param string|null $extraNode a node named by this one event (its reporting host), beside the shared list
     */
    public function mask(string $text, array $podNames, array $nodeNames, ?string $extraNode = null): string
    {
        if (!mb_check_encoding($text, 'UTF-8')) {
            return '';
        }
        $t = trim((string) preg_replace('/\s+/u', ' ', mb_substr($text, 0, self::MAX_INPUT)));
        $folded = class_exists(\Normalizer::class) ? \Normalizer::normalize($t, \Normalizer::FORM_KC) : false;
        $t = is_string($folded) ? $folded : $t; // fullwidth keywords are keywords; invisible characters are kept for the step below
        // A keyword followed by an invisible character and then a name is still a keyword followed by a name: the character would be
        // deleted below and glue the two ("secret<U+00AD>name" -> "secretname"), so it becomes the space it stands for.
        $t = (string) preg_replace('/\b(secrets?|configmaps?|containers?|endpoints?|services?|serviceaccounts?|persistentvolumeclaims?|pvcs?|volumes?|claim|pod|image)[\p{Cf}\p{Cc}\p{Cn}]++(?=[^\p{Cf}\p{Cc}\p{Cn}\s=:])/iu', '$1 ', $t);
        // Twice, on purpose. On the RAW text a credentialed token goes whole, up to the last '@' before real whitespace, so a password
        // holding %3E, %22, &quot; or &#32; is not cut where its decoded form would have a delimiter. Then on the canonical text, so a
        // fullwidth or encoded URL is masked as itself.
        $t = $this->rawUserinfo($t);
        $t = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[\p{Cc}\p{Cn}]++/u', '', mb_substr(Normalizer::canonical($t), 0, self::MAX_INPUT))));
        $t = $this->urls($this->rawUserinfo($t));
        $t = $this->quotedSpans($t);
        // Admission webhooks and operators write free text (user names, resource names) after their verdict: keep the verdict only.
        $t = (string) preg_replace('/\b(denied the request|rejected the request|failed calling webhook)\b:?.*$/iu', '$1: <STR>', $t);
        $t = (string) preg_replace('#\b(?:containerd|docker|cri-o|crio)://[0-9a-f]{8,}\b#', '<ID>', $t);
        $t = (string) preg_replace('/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i', '<ID>', $t);
        // The names of this read are matched in ONE pass over the text against a set built once per read (not one regex per pod per event).
        $this->prepare($podNames, $nodeNames);
        $t = (string) preg_replace_callback(
            '/(?<![A-Za-z0-9-])[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?(?![A-Za-z0-9-])/',
            fn (array $m): string => $this->podSet[$m[0]] ?? $m[0],
            $t,
        );
        $t = (string) preg_replace_callback(
            '/(?<![A-Za-z0-9.-])[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?(?![A-Za-z0-9-])/',
            fn (array $m): string => isset($this->nodeSet[$m[0]]) || $m[0] === $extraNode ? '<NODE>' : $m[0],
            $t,
        );
        $t = (string) preg_replace([
            '/\bip-\d{1,3}-\d{1,3}-\d{1,3}-\d{1,3}(?:\.[a-z0-9.-]*[a-z0-9])?/',
            '/\bgke-[a-z0-9-]+-[a-z0-9]{8}-[a-z0-9]{4}\b/',
            '/\baks-[a-z0-9]+-\d{8}-vmss[0-9a-z]{6}\b/',
        ], '<NODE>', $t);
        // Names the kubelet writes unquoted: the Secret or ConfigMap a container reference points at, its key, and mounted volumes.
        $t = (string) preg_replace(
            ['/\b(in (?:Secret|ConfigMap))\s+\S+/i', '/\b(find key)\s+\S+/i', '/\b((?:un)?(?:mounted|attached) volumes)=\[[^\]]*\]/'],
            ['$1 <STR>', '$1 <STR>', '$1=[<STR>]'],
            $t,
        );
        // Where the sentence names a node, whether or not its name was learned from this read (its pod may be gone by now).
        $t = (string) preg_replace(['/\b(assigned\s+\S+\s+to)\s+(?!<NODE>)\S+/i', '/\b(Node|on node|nodes?)\s+(?!<NODE>|are\b|is\b|was\b|were\b|available\b|didn\'t\b|had\b|affinity\b|selector\b|affinities\b)([a-z0-9][-a-z0-9.]{2,})/'], ['$1 <NODE>', '$1 <NODE>'], $t);
        $t = (string) preg_replace('/\b([a-z0-9](?:[-a-z0-9]*[a-z0-9])?)-' . self::HASH . '-[a-z0-9]{5}(?![a-z0-9-])/', '$1-<POD>', $t);
        $t = (string) preg_replace_callback(
            '#(?<![\w.\-/])((?:[a-z0-9-]+\.)+[a-z0-9-]+(?::\d{1,5})?|localhost(?::\d{1,5})?)/([A-Za-z0-9._-]+(?:/[A-Za-z0-9._-]+)*)(?::([A-Za-z0-9._-]+))?(@sha256:[0-9a-f]{64})?#',
            fn (array $m): string => $this->image($m[1], $m[2], ($m[3] ?? '') !== '' || ($m[4] ?? '') !== ''),
            $t,
        );
        // An image written without quotes (older runtimes, third-party controllers): `nginx:1.2.3`, `registry:5000/app:1.0`, `acme/app`.
        $t = (string) preg_replace_callback(
            '#\b(image|for|repository|pulling|pull)\s+(?!(?:image|for|repository|pulling|pull)\b)([A-Za-z0-9][A-Za-z0-9._-]*+(?::\d{1,5}(?=/))?(?:/[A-Za-z0-9._-]++)*+(?::[A-Za-z0-9._-]++)?(?:@sha256:[0-9a-f]{64})?)#i',
            fn (array $m): string => (str_contains($m[2], '/') || str_contains($m[2], ':') || str_contains($m[2], '@sha256:')) && !str_contains($m[2], '<') ? $m[1] . ' ' . $this->quoted($m[2]) : $m[0],
            $t,
        );
        $t = (string) preg_replace("/(?<![A-Za-z])'[^'\\n]+?'(?![A-Za-z])/u", '<STR>', $t);
        // Names that identify the service or the infrastructure and are not on the allowlist: containers, taints and labels in braces,
        // and the Secret, ConfigMap, claim or volume a sentence names without quotes. A plain English word after the keyword is kept.
        $t = (string) preg_replace('/\{[^{}]*+\}/', '{<STR>}', $t);
        // key=value forms the kubelet and controllers write: the value names a container, pod, claim, secret or endpoint.
        $t = (string) preg_replace('/\b(container[ _]?id|containerID)\s*[=:]\s*(?!' . self::PLACEHOLDER . ')\S++/i', '$1=<STR>', $t);
        $t = (string) preg_replace('/\b(container|pod|secret|configmap|endpoints?|pvc|persistentvolumeclaim|volume|service|serviceaccount|claim)\s*[=:]\s*(?!' . self::PLACEHOLDER . '|\s)[^\s,;]++/i', '$1=<STR>', $t);
        // Kind/name: `Secret/db-creds`, `endpoint prod/payments-api`.
        $t = (string) preg_replace('~\b(secrets?|configmaps?|endpoints?|services?|serviceaccounts?|persistentvolumeclaims?|pvcs?)/(?!' . self::PLACEHOLDER . ')[A-Za-z0-9][\w.-]*+~i', '$1/<STR>', $t);
        // A pulled image written as one bare word (`registry/` and `:tag` forms are handled above).
        $t = (string) preg_replace_callback(
            '/\b((?:pulling|pulled|pull|failed to pull|failed to pull and unpack)\s+image)\s+(?!<|(?:' . self::WORDS . ')(?![\w.\/-]))([A-Za-z0-9][\w.-]*+)(?![\w.\/:@-])/i',
            fn (array $m): string => $m[1] . ' ' . $this->image(null, $m[2], false),
            $t,
        );
        $t = (string) preg_replace('/\b(containers?)\s+(?!' . self::PLACEHOLDER . '|(?:' . self::WORDS . ')(?![\w.\/-]))(<[^<>\s]++>|[A-Za-z0-9][\w.-]*+)/i', '$1 <STR>', $t);
        // `containers payments, billing`: the names after the first one.
        $t = (string) preg_replace_callback('/\b(containers <STR>)((?:,\s*(?:and\s+)?(?!<)[A-Za-z0-9][\w.-]*+)++)/i', static fn (array $m): string => $m[1] . (string) preg_replace('/[A-Za-z0-9][\w.-]*/', '<STR>', $m[2]), $t);
        $t = (string) preg_replace('/\b(secrets?|configmaps?|persistentvolumeclaims?|pvcs?|volumes?|endpoints?)\s+(?!' . self::PLACEHOLDER . '|(?:' . self::WORDS . ')(?![\w.\/-]))([(\[]?)(<[^<>\s]++>|[A-Za-z0-9][\w.\/-]*+)[)\]]?/i', '$1 <STR>', $t);
        $t = (string) preg_replace_callback(
            '#(?<![\w.\-/])([a-z0-9](?:[-a-z0-9]{0,61}[a-z0-9])?)/((?:[a-z0-9]+(?:-[a-z0-9]+)*-)?<POD>)#',
            fn (array $m): string => (in_array($m[1], $this->namespaces, true) ? $m[1] : '<NS>') . '/' . $m[2],
            $t,
        );

        // Final leftover pass: a span the rules above matched only in part leaves a tail (or a head) of name characters against its
        // placeholder. A placeholder never keeps a name next to it.
        $t = (string) preg_replace('/(?:<STR>|<URL>|<ID>)[\w.\/-]++/', '<STR>', $t);
        $t = (string) preg_replace('/[\w.-]++(?=<STR>|<URL>)/', '', $t);

        return trim($t);
    }

    /**
     * Every quote style is paired on its own, with its own opening and closing characters and an inner class that holds no quote
     * character at all, so a stray or mismatched quote cannot change how another style pairs. Two rounds let a pair that was
     * blocked by an inner pair match once that is masked. A quote that is still unpaired afterwards masks the rest of the text.
     */
    private function quotedSpans(string $t): string
    {
        $apostrophes = "\u{2018}\u{2019}\u{2032}"; // quote a name in pairs, but a lone one is an English apostrophe: never a "stray" quote
        $quotes = "\"\u{201C}\u{201D}\u{201E}\u{00AB}\u{00BB}\u{2039}\u{203A}`\u{201A}\u{300C}\u{300D}\u{300E}\u{300F}\u{275D}\u{275E}\u{FF02}" . $apostrophes;
        $styles = [
            ['"', '"'], ["\u{201C}", "\u{201D}"], ["\u{201E}", "\u{201C}"], ["\u{201E}", "\u{201D}"], ["\u{00AB}", "\u{00BB}"], ["\u{2039}", "\u{203A}"], ['`', '`'],
            ["\u{2018}", "\u{2019}"], ["\u{2019}", "\u{2019}"], ["\u{201A}", "\u{2018}"], ["\u{300C}", "\u{300D}"], ["\u{300E}", "\u{300F}"],
            ["\u{275D}", "\u{275E}"], ["\u{2032}", "\u{2032}"], ["\u{FF02}", "\u{FF02}"],
        ];
        for ($round = 0; $round < 2; ++$round) {
            foreach ($styles as [$open, $close]) {
                $t = (string) preg_replace_callback(
                    '/' . preg_quote($open, '/') . '([^' . preg_quote($quotes, '/') . ']*+)' . preg_quote($close, '/') . '/u',
                    fn (array $m): string => $this->quoted($m[1]),
                    $t,
                );
            }
        }

        return (string) preg_replace('/[' . preg_quote(str_replace(str_split($apostrophes, 3), '', $quotes), '/') . '].*+$/su', '<STR>', $t);
    }

    /**
     * A whitespace-delimited token whose CANONICAL form is credentialed (a ':' before an '@' that is not an image digest) goes whole,
     * however it is written: fullwidth or zero-width delimiters, %40, quotes, brackets, or a password holding %3E, %22, &quot; or &#32;
     * (which decode into the delimiters the URL rules split on). Judged per raw token so the decoded form cannot cut the span short.
     */
    private function rawUserinfo(string $t): string
    {
        return (string) preg_replace_callback('/\S+/u', static fn (array $m): string => preg_match('/:.*@(?!sha256:)/s', Normalizer::canonical($m[0])) === 1 ? '<URL>' : $m[0], $t);
    }

    /**
     * A URL (and anything shaped user:password@host) goes whole, so no later rule can cut it in two and leave the credentials behind.
     * Container-runtime ids (containerd://...) are not URLs and are handled by mask().
     */
    private function urls(string $t): string
    {
        // Userinfo is masked for EVERY scheme first, the container-runtime ones included (only their bare ids are exempt).
        $t = (string) preg_replace('#\b[a-z][a-z0-9+.-]*://[^@\s"\'<>]*@[^\s"\'<>]+#i', '<URL>', $t);
        $t = (string) preg_replace('#(?<![a-z])(?<!cri-)(?!(?:containerd|docker|cri-o|crio)://)[a-z][a-z0-9+.-]*://[^\s"\'<>]+#i', '<URL>', $t);

        return (string) preg_replace('#(?<![\w@.-])[^\s:@"\'<>]+:\S+@[^\s"\'<>]+#', '<URL>', $t);
    }

    /**
     * Rebuilt only when the lists change: a read hands the same arrays to every event, and an identical array is recognised without
     * walking it, so the cost of the matcher is paid once per read.
     *
     * @param list<string> $podNames
     * @param list<string> $nodeNames
     */
    private function prepare(array $podNames, array $nodeNames): void
    {
        if ($podNames !== $this->preparedPods) {
            $this->podSet = [];
            foreach ($podNames as $pod) {
                if ($pod !== '') {
                    $base = self::podBase($pod);
                    $this->podSet[$pod] = $base === null ? '<POD>' : $base . '-<POD>';
                }
            }
            $this->preparedPods = $podNames;
        }
        if ($nodeNames !== $this->preparedNodes) {
            $this->nodeSet = array_fill_keys(array_filter($nodeNames, static fn (string $n): bool => $n !== ''), true);
            $this->preparedNodes = $nodeNames;
        }
    }

    /** Workload prefix of a generated pod name, null when the name has no recognisable generated suffix. */
    public static function podBase(string $pod): ?string
    {
        foreach (['/^(.+)-(?:(?=[a-z]*\d)|(?=[bcdfghjklmnpqrstvwxz]{8,10}-))[a-z0-9]{8,10}-[a-z0-9]{5}$/', '/^(.+)-\d+$/', '/^(.+)-[a-z0-9]{5}$/'] as $shape) {
            if (preg_match($shape, $pod, $m) === 1) {
                return $m[1];
            }
        }

        return null;
    }

    private function quoted(string $inner): string
    {
        if (preg_match(self::IMAGE_LIKE, $inner) === 1 && (str_contains($inner, '/') || str_contains($inner, ':') || str_contains($inner, '@sha256:'))) {
            $hasTag = str_contains($inner, '@sha256:');
            $ref = (string) preg_replace('/@sha256:[0-9a-f]{64}$/', '', $inner);
            $slash = strrpos($ref, '/');
            $colon = strrpos($ref, ':');
            if ($colon !== false && ($slash === false || $colon > $slash)) {
                $hasTag = true;
                $ref = substr($ref, 0, $colon);
            }
            $parts = explode('/', $ref);
            $registry = null;
            if (count($parts) > 1 && (str_contains($parts[0], '.') || str_contains($parts[0], ':') || $parts[0] === 'localhost')) {
                $registry = array_shift($parts);
            }

            return $this->image($registry, implode('/', $parts), $hasTag);
        }

        return '<STR>';
    }

    private function image(?string $registry, string $path, bool $hasTag): string
    {
        $host = $registry === null ? '' : (in_array($registry, $this->allowRegistries, true) ? $registry : '<REGISTRY>') . '/';

        return $host . (in_array($path, $this->allowImages, true) ? $path : '<IMAGE>') . ($hasTag ? ':<TAG>' : '');
    }

}
