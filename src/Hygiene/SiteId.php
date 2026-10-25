<?php

declare(strict_types=1);

namespace Watcher\Hygiene;

use Watcher\Level;
use Watcher\Privacy\Redactor;
use Watcher\Reduce\ProblemGroup;
use Watcher\Source\LogEvent;

/**
 * Names the place a log line comes from (research R4): context keys `file` + `line`, else the first stack frame, else
 * the fingerprint. A path goes through the Redactor's frame rewriting like any frame sent anywhere, so an identifier
 * the Redactor drops (a personal name in a path, a random segment) falls through to the next rule.
 */
final class SiteId
{
    /** A site is a source file: anything else in a `file` context key (an upload, an import, a document) is data. */
    private const CODE = ['php', 'phtml', 'js', 'mjs', 'cjs', 'jsx', 'ts', 'tsx', 'vue', 'py', 'rb', 'java', 'kt', 'scala', 'go', 'cs', 'rs', 'swift'];

    /**
     * The only directory names that are code structure by themselves. Anything else (`customers`, `Petrenko`, a hex token, a person)
     * may be data: such a path is not a site id and falls back to the fingerprint, unless `hygiene.site_allow_dirs` names the directory.
     */
    private const CODE_DIRS = [
        'app', 'src', 'lib', 'libs', 'source', 'sources', 'packages', 'package', 'modules', 'module', 'core', 'common', 'shared', 'utils', 'util',
        'helpers', 'helper', 'controllers', 'controller', 'models', 'model', 'views', 'view', 'services', 'service', 'jobs', 'job', 'handlers',
        'handler', 'middleware', 'middlewares', 'commands', 'command', 'console', 'http', 'api', 'domain', 'application', 'infrastructure',
        'presentation', 'config', 'database', 'migrations', 'routes', 'tests', 'test', 'components', 'component', 'pages', 'hooks', 'plugins',
        'plugin', 'providers', 'provider', 'repositories', 'repository', 'events', 'event', 'listeners', 'listener', 'mail', 'notifications',
        'policies', 'rules', 'requests', 'resources', 'support', 'traits', 'contracts', 'interfaces', 'entities', 'entity', 'dto', 'adapters',
        'adapter', 'clients', 'client', 'workers', 'worker', 'tasks', 'task', 'queue', 'queues', 'schedule', 'cli', 'bin', 'internal', 'pkg',
        'cmd', 'main', 'java', 'kotlin', 'scala', 'php', 'js', 'ts', 'jobs', 'actions', 'action', 'filters', 'validators', 'validation',
        'serializers', 'transformers', 'factories', 'seeders', 'observers', 'exceptions', 'enums', 'values', 'types', 'concerns', 'engine',
    ];

    /** @var list<string> container roots without trailing slash, longest first */
    private readonly array $roots;

    /**
     * @param list<string> $pathRoots the roots of `repo.path_map`: a site is a file below one of them; without any, no file is a site
     * @param list<string> $allowDirs `hygiene.site_allow_dirs`: deny-listed directory names accepted anyway
     */
    public function __construct(private readonly Redactor $redactor, array $pathRoots = [], private readonly array $allowDirs = [])
    {
        $roots = array_values(array_filter(array_map(static fn (string $r): string => rtrim(str_replace('\\', '/', $r), '/'), $pathRoots), static fn (string $r): bool => $r !== ''));
        usort($roots, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        $this->roots = $roots;
    }

    /**
     * The fingerprint's inputs without the frame: a line that moves inside its file keeps the same shape.
     *
     * @param list<string> $tokens
     */
    public static function shapeKey(LogEvent $e, array $tokens): string
    {
        return substr(hash('sha256', implode('|', [$e->level->value, $e->errorType ?? '', mb_strtolower(implode(' ', array_slice($tokens, 0, 2)))])), 0, 16);
    }

    public function of(LogEvent $e, string $fingerprint, string $shapeKey): Site
    {
        foreach ($this->candidates($e) as $frame) {
            $id = $this->mask($frame);
            if ($id !== null) {
                $at = (int) strrpos($id, ':');

                return new Site(substr(hash('sha256', 'f|' . substr($id, 0, $at) . '|' . $shapeKey), 0, 16), $id, 'frame');
            }
        }

        // Only what a deploy does not change: level, error type and the first two masked words. The run fingerprint also hashes the line
        // number of frame 0 and the token count, so every deploy (or a longer tail) would make every unmapped site gone-and-new at once.
        return new Site(substr(hash('sha256', 't|' . $shapeKey), 0, 16), 'tpl:' . substr(hash('sha256', 'tpl|' . $shapeKey), 0, 12), 'template');
    }

    /** @return list<string> `path:line` strings, most specific first */
    private function candidates(LogEvent $e): array
    {
        $out = [];
        $file = $e->fields['file'] ?? null;
        $line = $e->fields['line'] ?? null;
        if (is_string($file) && $file !== '' && (is_int($line) || (is_string($line) && ctype_digit($line)))) {
            $out[] = $file . ':' . $line;
        }
        if (isset($e->frames[0])) {
            $out[] = $e->frames[0];
        }

        return array_values(array_filter($out, $this->isCode(...)));
    }

    /**
     * A source file below a `repo.path_map` root: normalised, no `..`, one known code extension (a double extension such as
     * `.pdf.php` is a document with a suffix), no data directory below the root.
     */
    private function isCode(string $frame): bool
    {
        $path = str_replace('\\', '/', substr($frame, 0, (int) strrpos($frame, ':')));
        foreach ($this->roots as $root) {
            if (str_starts_with($path, $root . '/')) {
                $segments = explode('/', substr($path, strlen($root) + 1));
                $file = array_pop($segments);
                if (in_array('..', $segments, true) || in_array('', $segments, true) || in_array('.', $segments, true)) {
                    return false;
                }
                foreach ($segments as $segment) {
                    $segment = strtolower($segment);
                    $allowed = in_array($segment, $this->allowDirs, true);
                    if (!$allowed && !in_array($segment, self::CODE_DIRS, true)) {
                        return false; // an allow list, not a deny list: unknown directory names may be people
                    }
                }

                // One identifier (a class or a module name), at most one underscore, and every word of it is code vocabulary or named by the
                // operator: a file named after a person or a customer (`IvanPetrenko.php`, `Jane_Doe.py`) is data, not a site.
                if (preg_match('/\A([A-Za-z][A-Za-z0-9]*(?:_[A-Za-z0-9]+)?)\.(?:' . implode('|', self::CODE) . ')\z/i', $file, $m) !== 1) {
                    return false;
                }

                return $this->stemIsCode($m[1]);
            }
        }

        return false;
    }

    private function stemIsCode(string $stem): bool
    {
        preg_match_all('/[A-Z]+(?![a-z])|[A-Z][a-z]+|[a-z]+|[0-9]+/', $stem, $m);
        foreach ($m[0] as $word) {
            $w = strtolower($word);
            $known = ctype_digit($w) ? true : (strlen($w) <= 2 || LiteralFloor::isVocabulary($w) || in_array($w, self::CODE_DIRS, true) || in_array($w, $this->allowDirs, true));
            if (!$known) {
                return false;
            }
        }

        return $m[0] !== [] && !preg_match('/[0-9]{3,}/', $stem) && preg_match_all('/[0-9]+/', $stem) <= 1; // a token has digits all over it
    }

    private function mask(string $frame): ?string
    {
        $t = new \DateTimeImmutable('@0');
        $group = new ProblemGroup('0', null, Level::Info, null, [$frame], 1, $t, $t);

        return $this->redactor->redact($group)->frames[0] ?? null;
    }
}
