<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Source\Kubernetes;

use PHPUnit\Framework\TestCase;
use Watcher\Source\Kubernetes\EventMasker;

/** Spec 008 privacy requirements: deterministic masking of pod, node, image, id and quoted-name text before grouping. */
final class EventMaskerTest extends TestCase
{
    /**
     * @param list<string> $namespaces
     * @param list<string> $registries
     * @param list<string> $images
     */
    private function masker(array $namespaces = ['shop'], array $registries = [], array $images = []): EventMasker
    {
        return new EventMasker($namespaces, $registries, $images);
    }

    public function testKnownPodNameKeepsOnlyTheWorkloadPrefix(): void
    {
        $text = 'Back-off restarting failed container api in pod api-7d9f8c6b5-x2k4q_shop(9b1deb4d-3b7d-4bad-9bdd-2b0d7b3d0064)';

        self::assertSame(
            'Back-off restarting failed container <STR> in pod api-<POD>_shop(<ID>)',
            $this->masker()->mask($text, ['api-7d9f8c6b5-x2k4q'], []),
        );
    }

    public function testEightPodsCollapseToOneText(): void
    {
        $texts = [];
        foreach (['x2k4q', 'm8v2p', 'h5t7n', 'c9w3z', 'j4b6d', 'r2f8s', 'k7g1x', 'q3y5l'] as $s) {
            $texts[] = $this->masker()->mask("Back-off restarting failed container api in pod api-7d9f8c6b5-{$s}_shop(uid)", ["api-7d9f8c6b5-$s"], []);
        }

        self::assertCount(1, array_unique($texts));
    }

    public function testTwoHundredGeneratedPodsCollapseToOneText(): void
    {
        $texts = [];
        mt_srand(8);
        for ($i = 0; $i < 200; ++$i) {
            $pod = 'api-' . substr(md5((string) mt_rand()), 0, 9) . '-' . substr(md5((string) mt_rand()), 0, 5);
            $texts[] = $this->masker()->mask("Readiness probe failed for pod $pod", [], []); // not in the known list: the shape rule must catch it
        }

        self::assertCount(1, array_unique($texts));
        self::assertSame('Readiness probe failed for pod api-<POD>', $texts[0]);
    }

    public function testStatefulSetOrdinalNeedsTheKnownName(): void
    {
        self::assertSame('Unhealthy pod db-<POD>', $this->masker()->mask('Unhealthy pod db-2', ['db-2'], []));
        self::assertSame('Unhealthy pod db-2', $this->masker()->mask('Unhealthy pod db-2', [], []));
    }

    public function testNodeNamesAreMaskedKnownOrByCloudShape(): void
    {
        $m = $this->masker();

        self::assertSame('assigned to <NODE>', $m->mask('assigned to ip-10-0-4-17.eu-west-1.compute.internal', [], []));
        self::assertSame('assigned to <NODE>', $m->mask('assigned to gke-prod-pool-1-1a2b3c4d-wxyz', [], []));
        self::assertSame('assigned to <NODE>', $m->mask('assigned to aks-nodepool1-12345678-vmss000003', [], []));
        self::assertSame('assigned to <NODE>', $m->mask('assigned to worker-blue-7', [], ['worker-blue-7']));
    }

    public function testImageReferencesLoseRegistryPathAndTag(): void
    {
        $m = $this->masker();

        self::assertSame(
            'Failed to pull image <REGISTRY>/<IMAGE>:<TAG>: rpc error: code = Unknown desc = pull access denied for <REGISTRY>/<IMAGE>, repository does not exist or may require <STR>',
            $m->mask('Failed to pull image "registry.corp.example:5000/payments/api:2.4.1-rc": rpc error: code = Unknown desc = pull access denied for registry.corp.example:5000/payments/api, repository does not exist or may require \'docker login\'', [], []),
        );
        self::assertSame('Container image <IMAGE>:<TAG> already present on machine', $m->mask('Container image "nginx:1.25" already present on machine', [], []));
        self::assertSame('pulled <REGISTRY>/<IMAGE>:<TAG>', $m->mask('pulled "docker.io/library/app@sha256:' . str_repeat('ab', 32) . '"', [], []));
    }

    public function testAllowlistedRegistryAndImageAreKept(): void
    {
        $m = $this->masker(['shop'], ['ghcr.io'], ['org/api']);

        self::assertSame('Pulling image ghcr.io/org/api:<TAG>', $m->mask('Pulling image "ghcr.io/org/api:1.2.3"', [], []));
        self::assertSame('Pulling image ghcr.io/<IMAGE>:<TAG>', $m->mask('Pulling image "ghcr.io/org/other:1.2.3"', [], []));
        self::assertSame('Pulling image <REGISTRY>/org/api:<TAG>', $m->mask('Pulling image "evil.example/org/api:1.2.3"', [], []));
    }

    public function testQuotedNamesAndIdsAreMasked(): void
    {
        $m = $this->masker();

        self::assertSame(
            'MountVolume.SetUp failed for volume <STR> : secret <STR> not found',
            $m->mask('MountVolume.SetUp failed for volume "db-credentials" : secret "db-credentials-prod" not found', [], []),
        );
        self::assertSame('Killing container with id <ID>', $m->mask('Killing container with id containerd://' . str_repeat('c0ffee12', 8), [], []));
        self::assertSame('Get <STR>: dial tcp refused', $m->mask('Get "http://10.42.3.17:8080/healthz?token=sk-live-9f8e7d6c5b4a3921abcdef01": dial tcp refused', [], []));
    }

    public function testNamespaceInFrontOfAPodIsKeptOnlyWhenConfigured(): void
    {
        $m = $this->masker(['shop']);

        self::assertSame('assigned shop/api-<POD> to <NODE>', $m->mask('assigned shop/api-7d9f8c6b5-x2k4q to node-a', ['api-7d9f8c6b5-x2k4q'], ['node-a']));
        self::assertSame('assigned <NS>/ledger-<POD> to <NODE>', $m->mask('assigned billing/ledger-6d4f5b7c9-p2q8r to node-a', ['ledger-6d4f5b7c9-p2q8r'], ['node-a']));
    }

    public function testAdmissionWebhookFreeTextIsReducedToItsVerdict(): void
    {
        self::assertSame(
            'admission webhook <STR> denied the request: <STR>',
            $this->masker()->mask('admission webhook "validate.policy.example.com" denied the request: user olena.shevchenko@example.com may not scale deployment checkout-eu', [], []),
        );
    }

    public function testTheNodeAfterToIsMaskedWhateverItIsCalled(): void
    {
        $m = $this->masker();

        self::assertSame('Successfully assigned shop/api-<POD> to <NODE>', $m->mask('Successfully assigned shop/api-7d9f8c6b5-x2k4q to worker-alpha', [], []));
        self::assertSame('Node <NODE> status is now: NodeNotReady', $m->mask('Node worker-alpha status is now: NodeNotReady', [], []));
    }

    public function testSecretConfigMapAndKeyNamesInKubeletTextAreMasked(): void
    {
        $m = $this->masker();

        self::assertSame('Error: couldn\'t find key <STR> in Secret <STR>', $m->mask('Error: couldn\'t find key STRIPE_LIVE_KEY in Secret prod/payments-stripe-creds', [], []));
        self::assertSame('Error: configmap in ConfigMap <STR> not found', $m->mask('Error: configmap in ConfigMap prod/acme-ledger-config not found', [], []));
    }

    public function testVolumeNamesInMountFailuresAreMaskedSoPodsCollapse(): void
    {
        $m = $this->masker();
        $a = $m->mask('Unable to attach or mount volumes: unmounted volumes=[acme-ledger], unattached volumes=[kube-api-access-x7k2p acme-ledger]: timed out', [], []);
        $b = $m->mask('Unable to attach or mount volumes: unmounted volumes=[acme-ledger], unattached volumes=[kube-api-access-q8z2m acme-ledger]: timed out', [], []);

        self::assertSame($a, $b);
        self::assertStringNotContainsString('acme-ledger', $a);
    }

    public function testUnquotedImagePathsWithoutARegistryAreMasked(): void
    {
        $m = $this->masker();

        self::assertStringNotContainsString('acme', $m->mask('pull access denied for acme/private-repo, repository does not exist', [], []));
        self::assertSame('pulling <IMAGE>:<TAG>', $m->mask('pulling team/api:1.2.3', [], []));
    }

    public function testAGonePodWithANamespaceSuffixStillCollapses(): void
    {
        $m = $this->masker();

        self::assertSame(
            'Back-off restarting failed container <STR> in pod api-<POD>_prod(<ID>)',
            $m->mask('Back-off restarting failed container api in pod api-7d9f8c6b5-x2k4q_prod(9b1deb4d-3b7d-4bad-9bdd-2b0d7b3d0064)', [], []),
        );
    }

    public function testTheSchedulersNodeAffinityPhraseIsNotTurnedIntoANodeName(): void
    {
        self::assertStringContainsString('node affinity', $this->masker()->mask("0/5 nodes are available: 1 node(s) didn't match Pod's node affinity/selector.", [], []));
    }

    public function testPodBaseNeedsAHashShapedMiddle(): void
    {
        self::assertSame('node-exporter', EventMasker::podBase('node-exporter-abcde'));
        self::assertSame('log-collector', EventMasker::podBase('log-collector-x7k2p'));
        self::assertSame('api', EventMasker::podBase('api-7d9f8c6b5-x2k4q'));
        self::assertSame('cron', EventMasker::podBase('cron-28812345-abcde'));
        self::assertSame('db', EventMasker::podBase('db-2'));
    }

    /** @return array<string, array{string, list<string>}> text => values that must not survive masking plus normalising */
    public static function leaks(): array
    {
        return [
            'userinfo url with ip' => ['Get http://user:pass@10.1.2.3:8080/health', ['pass', 'user', '10.1.2.3']],
            'userinfo url with cluster host' => ['Get http://user:s3cretpw@svc.shop.svc:8080/x', ['s3cretpw', 'svc.shop']],
            'userinfo https registry' => ['Get https://admin:hunter2@reg.io/v2/', ['hunter2', 'admin', 'reg.io']],
            'token as userinfo' => ['probe https://tok-abc123@host.example/x failed', ['tok-abc123', 'host.example']],
            'bare userinfo' => ['dial admin:hunter2@db.internal:5432 refused', ['hunter2', 'admin', 'db.internal']],
            'bare image with tag' => ['Failed to pull image nginx:1.2.3-rc', ['nginx', '1.2.3', 'rc']],
            'port only registry' => ['Failed to pull image registry:5000/app:1.0', ['registry', 'app']],
            'container name' => ['Created container payments-api', ['payments']],
            'container name in sentence' => ['Container payments-api failed liveness probe, will be restarted', ['payments']],
            'taint value' => ['1 node(s) had untolerated taint {dedicated: payments-olena}', ['payments-olena', 'dedicated']],
            'unquoted secret' => ['Error: secret db-credentials-prod not found', ['db-credentials-prod']],
            'fullwidth userinfo url' => ["Get \u{FF48}\u{FF54}\u{FF54}\u{FF50}://admin:hunter2\u{FF20}reg.io/v2/", ['hunter2', 'admin', 'reg.io']],
            'percent-encoded userinfo url' => ['Get http%3A%2F%2Fadmin:hunter2%40reg.io%2Fv2%2F failed', ['hunter2', 'admin', 'reg.io']],
            'encoded at sign inside a url' => ['Get http://admin:hunter2%40reg.io/v2/ failed', ['hunter2', 'admin', 'reg.io']],
            'secret name starting with a word' => ['Error: secret config-prod not found', ['config-prod']],
            'secret path starting with a word' => ['Error: secret secret-x/y not found', ['secret-x', 'x/y']],
            'container name starting with a word' => ['Created container image-api', ['image-api']],
            'volume name with a dot' => ['Unable to attach volume config.local', ['config.local']],
            'docker url with userinfo' => ['Pulled docker://admin:hunter2@reg.io/app', ['hunter2', 'admin', 'reg.io']],
            'containerd url with userinfo' => ['Started containerd://admin:hunter2@reg.io/app', ['hunter2', 'admin']],
            'cri-o url with userinfo' => ['Started cri-o://admin:hunter2@reg.io/app', ['hunter2', 'admin']],
            'password with a slash' => ['Get https://admin:hun/ter2@reg.io/v2/', ['hun/ter2', 'ter2', 'admin']],
            'bare password with colon and slash' => ['dial admin:hu:nt/er2@db.internal:5432 refused', ['hu:nt', 'er2', 'admin', 'db.internal']],
            'encoded gt in password' => ['Get https://admin:hun%3Eter2@reg.io/v2/ failed', ['hun', 'ter2', 'admin', 'reg.io']],
            'encoded quote in password' => ['Get https://admin:hun%22ter2@reg.io/v2/ failed', ['hun', 'ter2', 'admin', 'reg.io']],
            'entity quote in password' => ['Get https://admin:hun&quot;ter2@reg.io/v2/ failed', ['hun', 'ter2', 'admin', 'reg.io']],
            'entity space in password' => ['Get https://admin:hun&#32;ter2@reg.io/v2/ failed', ['hun', 'ter2', 'admin', 'reg.io']],
            'encoded gt in bare userinfo' => ['dial admin:hun%3Eter2@db.internal:5432 refused', ['hun', 'ter2', 'admin', 'db.internal']],
            'fullwidth colon and at' => ["Get http://admin\u{FF1A}hunter2\u{FF20}reg.io/v2/ failed", ['hunter2', 'admin', 'reg.io']],
            'zero-width inside userinfo' => ["Get http://adm\u{200B}in:hun\u{200D}ter2@reg.io/v2/ failed", ['hunter2', 'admin', 'reg.io']],
            'quoted userinfo token' => ['dial "admin:hunter2@db.internal:5432" refused', ['hunter2', 'admin', 'db.internal']],
            'angle bracketed userinfo token' => ['dial <admin:hunter2@db.internal:5432> refused', ['hunter2', 'admin', 'db.internal']],
            'encoded at with encoded gt' => ['Get https://admin:hun%3Eter2%40reg.io/v2/ failed', ['hun', 'ter2', 'admin', 'reg.io']],
            'fullwidth with entity quote' => ["Get http://admin\u{FF1A}hun&quot;ter2\u{FF20}reg.io/v2/ failed", ['hun', 'ter2', 'admin', 'reg.io']],
            'zero-width with entity space' => ["Get http://admin:hun&#32;te\u{200B}r2@reg.io/v2/ failed", ['hun', 'ter2', 'admin', 'reg.io']],
            'angle bracketed container name' => ['Back-off: container <payments-svc> failed', ['payments', 'svc']],
            'angle bracketed lower-case word as container' => ['Back-off: container <payments> failed', ['payments']],
            'angle bracketed capitalised word as secret' => ['Error: secret <Hunter> not found', ['Hunter']],
            'angle bracketed canary as secret' => ['Error: secret <zqkvcanary> not found', ['zqkvcanary']],
            'angle bracketed secret name' => ['Error: secret <db-creds> not found', ['db-creds']],
            'angle bracketed secret with digits' => ['Error: secret <hunter2abc> not found', ['hunter', 'abc']],
            'endpoint with namespace' => ['Failed to update endpoint prod/payments-api: conflict', ['payments', 'prod/']],
            'kind slash name secret' => ['Secret/db-creds not found', ['db-creds']],
            'kind slash name configmap' => ['configmap/app-config-prod missing', ['app-config-prod']],
            'plural secrets' => ['secrets db-creds not found', ['db-creds']],
            'plural configmaps' => ['configmaps db-creds-prod not found', ['db-creds-prod']],
            'curly quoted secret' => ["secret \u{201C}db-creds-prod\u{201D} not found", ['db-creds-prod']],
            'guillemet quoted secret' => ["secret \u{00AB}db-creds-prod\u{00BB} not found", ['db-creds-prod']],
            'backticked secret' => ['secret `db-creds-prod` not found', ['db-creds-prod']],
            'parenthesised secret' => ['secret (db-creds-prod) not found', ['db-creds-prod']],
            'bracketed secret' => ['secret [db-creds-prod] not found', ['db-creds-prod']],
            'container key value' => ['back-off 5m0s restarting failed container=payments-api pod=api-xyz_prod(abc)', ['payments-api', 'xyz']],
            'container id key value' => ['container id=payments-api started', ['payments-api']],
            'container id colon' => ['container id: payments-api started', ['payments-api']],
            'single word image' => ['Back-off pulling image acmecorp', ['acmecorp']],
            'container list' => ['containers payments, billing failed', ['payments', 'billing']],
            'pvc colon' => ['pvc:data-pg-0 not found', ['data-pg']],
            'hyphen digit before a url' => ['x 1-https://h.example/p?token=hunter2abc', ['hunter2abc']],
            'hyphen before a url' => [' -https://h.example/p?token=hunter2abc', ['hunter2abc']],
            'hyphens before a userinfo url' => ['a-b-https://u:hunter2abc@h.example/', ['hunter2abc']],
            'docker url userinfo after a hyphen' => ['x-docker://u:hunter2abc@h.example/x', ['hunter2abc']],
            'single curly quoted secret' => ["secret \u{2018}zqkvcanary\u{2019} not found", ['zqkvcanary']],
            'right single quotes as a pair' => ["secret \u{2019}zqkvcanary\u{2019} not found", ['zqkvcanary']],
            'low-9 quotes' => ["secret \u{201A}zqkvcanary\u{2018} not found", ['zqkvcanary']],
            'corner brackets' => ["secret \u{300C}zqkvcanary\u{300D} not found", ['zqkvcanary']],
            'white corner brackets' => ["secret \u{300E}zqkvcanary\u{300F} not found", ['zqkvcanary']],
            'heavy double quotes' => ["secret \u{275D}zqkvcanary\u{275E} not found", ['zqkvcanary']],
            'primes' => ["secret \u{2032}zqkvcanary\u{2032} not found", ['zqkvcanary']],
            'fullwidth quotes' => ["secret \u{FF02}zqkvcanary\u{FF02} not found", ['zqkvcanary']],
            'fullwidth apostrophes' => ["secret \u{FF07}zqkvcanary\u{FF07} not found", ['zqkvcanary']],
            'soft hyphen after the keyword' => ["secret\u{00AD}zqkvcanary not found", ['zqkvcanary']],
            'zero-width space after the keyword' => ["secret\u{200B}zqkvcanary not found", ['zqkvcanary']],
            'word joiner after the keyword' => ["secret\u{2060}zqkvcanary not found", ['zqkvcanary']],
            'bom after the keyword' => ["secret\u{FEFF}zqkvcanary not found", ['zqkvcanary']],
            'nul after the keyword' => ["secret\x00zqkvcanary not found", ['zqkvcanary']],
            'control after the keyword' => ["container\x01zqkvcanary failed", ['zqkvcanary']],
            'delete after the keyword' => ["configmap\x7Fzqkvcanary missing", ['zqkvcanary']],
            'unassigned after the keyword' => ["secret\u{0378}zqkvcanary not found", ['zqkvcanary']],
            'control inside the name' => ["secret zqkv\x00canary not found", ['zqkvcanary', 'canary']],
            'percent-encoded space after the keyword' => ['Error: secret%20zqkvcanary not found', ['zqkvcanary']],
            'percent-encoded keyword and space' => ['Error: %73ecret%20zqkvcanary not found', ['zqkvcanary']],
            'json-like quoted key with an unquoted value' => ['Error: {"secret":zqkvcanary} not found', ['zqkvcanary']],
            'json-like key with a space' => ['Error: "configmap": zqkvcanary not found', ['zqkvcanary']],
            'unquoted pvc' => ['persistentvolumeclaim data-acme-ledger-0 not found', ['acme-ledger']],
        ];
    }

    /** @param list<string> $secrets */
    #[\PHPUnit\Framework\Attributes\DataProvider('leaks')]
    public function testNothingIdentifyingSurvivesMaskingAndTheNormalizer(string $text, array $secrets): void
    {
        $out = (new \Watcher\Reduce\Normalizer())->normalize($this->masker()->mask($text, [], []));

        foreach ($secrets as $secret) {
            self::assertStringNotContainsString($secret, $out, $text . ' -> ' . $out);
        }
    }

    public function testTheMaskerSeesTheCanonicalTextNotTheEncodedForm(): void
    {
        foreach (["Get \u{FF48}\u{FF54}\u{FF54}\u{FF50}://admin:hunter2\u{FF20}reg.io/v2/", 'Get http%3A%2F%2Fadmin:hunter2%40reg.io%2Fv2%2F', 'Get ht&#116;p://admin:hunter2&#64;reg.io/v2/'] as $text) {
            $out = $this->masker()->mask($text, [], []);

            self::assertSame('Get <URL>', $out, $text);
        }
    }

    public function testADelimiterEncodedInThePasswordDoesNotCutTheMaskShort(): void
    {
        foreach (['hun%3Eter2', 'hun%22ter2', 'hun&quot;ter2', 'hun&#32;ter2'] as $pw) {
            self::assertSame('Get <URL> failed', $this->masker()->mask("Get https://admin:$pw@reg.io/v2/ failed", [], []), $pw);
            self::assertSame('dial <URL> refused', $this->masker()->mask("dial admin:$pw@db.internal:5432 refused", [], []), $pw);
        }
    }

    public function testACredentialedTokenIsMaskedWholeWhateverDelimitersItIsWrittenWith(): void
    {
        foreach ([
            "http://admin\u{FF1A}hunter2\u{FF20}reg.io",
            "http://adm\u{200B}in:hun\u{200D}ter2@reg.io",
            '"admin:hunter2@reg.io"',
            '<admin:hunter2@reg.io>',
            'https://admin:hun%3Eter2%40reg.io/x',
            "http://admin\u{FF1A}hun&quot;ter2\u{FF20}reg.io",
            'http://admin:hun&#32;ter2@reg.io',
        ] as $token) {
            $out = $this->masker()->mask("Get $token failed", [], []);

            self::assertStringContainsString('<URL>', $out, $token);
            self::assertDoesNotMatchRegularExpression('/hun|ter2|admin|reg\.io/', $out, $token);
        }
        // An image digest has an '@' and a ':' but no credentials: still masked as an image, not as a URL.
        self::assertSame('Container image <REGISTRY>/<IMAGE>:<TAG> already present', $this->masker()->mask('Container image "reg.io/app@sha256:' . str_repeat('a', 64) . '" already present', [], []));
    }

    public function testUserinfoIsMaskedForEverySchemeByTheMaskerItself(): void
    {
        foreach (['docker', 'containerd', 'cri-o', 'crio', 'http'] as $scheme) {
            self::assertSame('Pulled <URL>', $this->masker()->mask("Pulled $scheme://admin:hun/ter:2@reg.io/app", [], []), $scheme);
        }
        self::assertSame('Started <ID>', $this->masker()->mask('Started containerd://abcdef0123456789', [], []), 'a bare runtime id is an id, not a URL');
    }

    /** Every keyword the rewrites know, followed by every separator the rewrites treat specially, inside a credentialed token. */
    public function testNoRewriteMayRunBeforeTheCredentialPasses(): void
    {
        $keywords = ['secret', 'secrets', 'configmap', 'configmaps', 'container', 'containers', 'endpoint', 'endpoints', 'service', 'services', 'serviceaccount',
            'serviceaccounts', 'persistentvolumeclaim', 'persistentvolumeclaims', 'pvc', 'pvcs', 'volume', 'volumes', 'claim', 'pod', 'image', 'Secret', 'POD', "\u{FF53}ecret", '%73ecret'];
        $separators = ['%20', '%2520', '+', '%09', '%0a', "\u{00AD}", "\u{200B}", '&#32;', "\u{FF05}20"];
        $templates = [
            'https://u:%s%sQPW@host.example/x', 'docker://u:%s%sQPW@host.example', 'https://%s%su:QPW@h.example/', 'dial u:%s%sQPW@db.internal:5432',
            '"https://u:%s%sQPW@host.example"', 'url=https://u:%s%sQPW@host.example', 'https://u%s:%s%sQPW@h' ,
        ];
        foreach ($keywords as $kw) {
            foreach ($separators as $sep) {
                foreach ($templates as $tpl) {
                    $input = 'Pull failed: ' . (substr_count($tpl, '%s') === 3 ? sprintf($tpl, '', $kw, $sep) : sprintf($tpl, $kw, $sep));
                    $out = $this->masker()->mask($input, [], []);

                    self::assertStringNotContainsString('QPW', $out, $input);
                    self::assertStringNotContainsString('host.example', $out, $input);
                    self::assertStringNotContainsString('db.internal', $out, $input);
                }
            }
        }
    }

    public function testHostileLongStringsAreMaskedFast(): void
    {
        $m = $this->masker();
        foreach ([
            str_repeat('secret a-', 220), str_repeat('container=', 200), str_repeat('containers a, ', 140), str_repeat('endpoint x/', 180),
            str_repeat('`', 1990), str_repeat("\u{201C}a", 600), str_repeat('pulling image ', 140), str_repeat('pvc:', 480), str_repeat('Secret/', 280),
        ] as $hostile) {
            $t = microtime(true);
            $m->mask($hostile, [], []);

            self::assertLessThan(0.25, microtime(true) - $t);
        }
    }

    public function testImageDigestsAndOurOwnWordsAreStillNotMasked(): void
    {
        $m = $this->masker();

        self::assertSame('Container image <REGISTRY>/<IMAGE>:<TAG> already present', $m->mask('Container image "reg.io/app@sha256:' . str_repeat('a', 64) . '" already present', [], []));
        self::assertSame('container image already present on machine', $m->mask('container image already present on machine', [], []));
        self::assertSame('Back-off pulling image <IMAGE>', $m->mask('Back-off pulling image acmecorp', [], []));
    }

    public function testAUrlAfterAnyPrefixCharacterIsStillMasked(): void
    {
        foreach (['-', '.', '+', '1', '_', '/', '"', "'", '(', '='] as $prefix) {
            $out = $this->masker()->mask("pre{$prefix}https://h.example/p?token=hunter2abc end", [], []);

            self::assertStringNotContainsString('hunter2abc', $out, $prefix);
            self::assertStringNotContainsString('h.example', $out, $prefix);
        }
    }

    public function testEveryRuntimeIdSchemeBecomesAnIdNotAUrl(): void
    {
        foreach (['containerd', 'docker', 'cri-o', 'crio'] as $scheme) {
            self::assertSame('Started <ID>', $this->masker()->mask("Started $scheme://" . str_repeat('ab12', 16), [], []), $scheme);
        }
    }

    public function testOurOwnSyntheticTextsAreKept(): void
    {
        $m = $this->masker();

        self::assertSame('container was killed because it ran out of memory', $m->mask('container was killed because it ran out of memory', [], []));
        self::assertSame('container restarted', $m->mask('container restarted', [], []));
        self::assertSame('Container image <IMAGE>:<TAG> already present on machine', $m->mask('Container image "nginx:1.25" already present on machine', [], []));
    }

    public function testInputIsBounded(): void
    {
        $out = $this->masker()->mask(str_repeat('a ', 5000), [], []);

        self::assertLessThanOrEqual(2000, strlen($out));
    }

    public function testNoSeededValueSurvives(): void
    {
        $text = 'Failed to pull image "registry.corp.example:5000/payments/api:2.4.1-rc" on ip-10-0-4-22.eu-west-1.compute.internal for pod worker-5c8d9f7b4-t6n2v: secret "db-credentials-prod" in (9b1deb4d-3b7d-4bad-9bdd-2b0d7b3d0064)';
        $out = $this->masker()->mask($text, ['worker-5c8d9f7b4-t6n2v'], ['ip-10-0-4-22.eu-west-1.compute.internal']);

        foreach (['registry.corp.example', 'payments', '2.4.1-rc', 'ip-10-0-4', '5c8d9f7b4', 't6n2v', 'db-credentials', '9b1deb4d'] as $seed) {
            self::assertStringNotContainsString($seed, $out);
        }
    }
}
