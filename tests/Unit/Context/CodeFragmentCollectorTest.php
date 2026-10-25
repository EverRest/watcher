<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Context;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Watcher\Config\WatchConfig;
use Watcher\Context\CodeFragmentCollector;
use Watcher\Context\CodeLinkBuilder;
use Watcher\Context\CodeRepository;
use Watcher\Context\CodeRepositoryException;
use Watcher\Level;
use Watcher\Privacy\GroupStatusLabel;
use Watcher\Privacy\RedactedGroup;
use Watcher\Privacy\Redactor;
use Watcher\Reduce\Normalizer;

final class CodeFragmentCollectorTest extends TestCase
{
    private const REPO = [
        'provider' => 'github',
        'web_url' => 'https://github.com/acme/api',
        'path_map' => ['/var/www/' => 'app/'],
        'api_token' => 't',
    ];

    public function testCollectsRedactedWindowForResolvableFrame(): void
    {
        $php = "<?php\nfinal class Auth {\n    public function check(): void {\n        \$user = null;\n        \$user->id;\n    }\n}\n";
        $repo = new class ($php) implements CodeRepository {
            public function __construct(private readonly string $body)
            {
            }

            public function readFile(string $commit, string $path, int $maxBytes): string
            {
                return $this->body;
            }
        };
        $limits = WatchConfig::fromArray(self::config())->codeAware;
        unset($limits['enabled']);
        $group = new RedactedGroup('fp1', 'err', Level::Error, 'Type', ['app/Services/Auth.php:5'], 3, null, GroupStatusLabel::New, [], new DateTimeImmutable('@0'), new DateTimeImmutable('@0'));
        $collector = new CodeFragmentCollector($repo, new CodeLinkBuilder(self::REPO, 'deadbeef'), new Redactor(new Normalizer([], [])), 'deadbeef', $limits);
        $out = $collector->forGroups([$group]);
        self::assertArrayHasKey('fp1', $out);
        self::assertStringContainsString('$user', $out['fp1'][0]['body']);
    }

    public function testSkipsVendorFramesWithoutRepositoryCall(): void
    {
        $repo = new class () implements CodeRepository {
            public int $calls = 0;

            public function readFile(string $commit, string $path, int $maxBytes): string
            {
                ++$this->calls;
                throw new CodeRepositoryException('should not run');
            }
        };
        $limits = WatchConfig::fromArray(self::config())->codeAware;
        unset($limits['enabled']);
        $group = new RedactedGroup('fp', 'err', Level::Error, null, ['vendor/foo.php:1'], 1, null, GroupStatusLabel::New, [], new DateTimeImmutable('@0'), new DateTimeImmutable('@0'));
        $collector = new CodeFragmentCollector($repo, new CodeLinkBuilder(self::REPO, 'c'), new Redactor(new Normalizer([], [])), 'c', $limits);
        self::assertSame([], $collector->forGroups([$group]));
        self::assertSame(0, $repo->calls);
    }

    /** @return array<string, mixed> */
    private static function config(): array
    {
        return [
            'service' => 'svc',
            'schedule' => ['run_at' => '06:00'],
            'source' => ['driver' => 'loki', 'loki' => ['url' => 'https://loki']],
            'analysis' => [
                'api_key' => 'k',
                'models' => ['triage' => 'm1', 'analyze' => 'm2', 'escalate' => 'm3'],
                'prices' => ['m1' => [1, 5, 0.1, 1.25], 'm2' => [3, 15, 0.3, 3.75], 'm3' => [5, 25, 0.5, 6.25]],
            ],
            'state' => ['driver' => 'file', 'file' => ['path' => '/tmp/w']],
            'notify' => ['digest' => ['driver' => 'slack', 'webhook' => 'https://hooks.example/x']],
            'repo' => self::REPO,
            'code_aware' => ['enabled' => true],
        ];
    }
}
