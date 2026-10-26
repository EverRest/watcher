<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;

/** Spec 008 FR-003 / Principle II: the cluster client can only GET events and pods. Fails if another verb or resource appears. */
final class KubernetesReadOnlyTest extends TestCase
{
    /** @return list<string> */
    private static function files(): array
    {
        $root = dirname(__DIR__, 3) . '/src/Source';

        return [...glob($root . '/Kubernetes/*.php') ?: [], $root . '/KubernetesEventsSource.php', dirname($root) . '/Events/EventsRun.php'];
    }

    public function testThereAreFilesToScan(): void
    {
        self::assertGreaterThanOrEqual(5, count(array_filter(self::files(), 'is_file')));
    }

    public function testOnlyGetIsEverUsed(): void
    {
        foreach (array_filter(self::files(), 'is_file') as $file) {
            $code = (string) file_get_contents($file);
            self::assertDoesNotMatchRegularExpression('/[\'"](POST|PUT|PATCH|DELETE|CONNECT|OPTIONS|HEAD)[\'"]/', $code, basename($file));
        }
        // Only KubeApi holds an HTTP client; the ledger's state-store put() is not an HTTP verb.
        $api = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Source/Kubernetes/KubeApi.php');
        self::assertDoesNotMatchRegularExpression('/->(post|put|patch|delete|head|options|postAsync|putAsync|patchAsync|deleteAsync)\s*\(/i', $api);
        self::assertSame(1, preg_match_all("/->request\(\s*'GET'/", $api), 'exactly one request site, and it is a GET');
        self::assertSame(1, preg_match_all('/->request\(/', $api));
    }

    public function testNoForbiddenResourceOrSubresource(): void
    {
        foreach (array_filter(self::files(), 'is_file') as $file) {
            $code = (string) preg_replace('#/\*.*?\*/|//[^\n]*#s', '', (string) file_get_contents($file)); // comments may name what is forbidden
            $code = str_replace('/var/run/secrets/kubernetes.io/serviceaccount', '', $code); // the pod's own token directory, read from disk, not an API resource
            $code = str_replace('allow_insecure', '', $code); // the SecureUrl opt-in for http to a trusted network; it never turns TLS verification off
            foreach (['/exec', '/attach', '/portforward', '/proxy', '/log', '/status', '/scale', '/binding', '/eviction', 'secrets', 'configmaps', '?watch', 'watch=', "'watch'", 'insecure', "'verify' => false", 'CURLOPT_SSL_VERIFYPEER'] as $needle) {
                if (basename($file) === 'EventMasker.php' && in_array($needle, ['secrets', 'configmaps'], true)) {
                    continue; // the masker names these words to HIDE them in event text; it never requests them
                }
                self::assertStringNotContainsString($needle, $code, basename($file) . ' mentions ' . $needle);
            }
        }
    }

    public function testTheClientListsOnlyEventsAndPods(): void
    {
        $code = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Source/Kubernetes/KubeApi.php');
        preg_match_all('#/(?:apis?)/[^\'"\s]*#', $code, $m);
        foreach ($m[0] as $path) {
            self::assertMatchesRegularExpression('#/(events|pods)$#', rtrim($path, '/'), "unexpected API path $path");
        }
    }
}
