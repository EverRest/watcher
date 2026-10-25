<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\ApiContext;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Watcher\ApiContext\OpenApiReducer;
use Watcher\ApiContext\OperationSummary;
use Watcher\Privacy\Redactor;
use Watcher\Reduce\Normalizer;
use Watcher\Source\SourceUnavailable;
use Watcher\State\StateCodec;

final class OpenApiReducerTest extends TestCase
{
    private function reducer(int $cap = 80, ?string $base = null): OpenApiReducer
    {
        return new OpenApiReducer(new Redactor(new Normalizer()), $cap, $base);
    }

    /** @return array<mixed> */
    private static function fixture(string $name): array
    {
        $doc = json_decode((string) file_get_contents(__DIR__ . '/../../Fixtures/api_context/' . $name . '.json'), true);
        self::assertIsArray($doc);

        return $doc;
    }

    private function token(callable $fn): string
    {
        try {
            $fn();
        } catch (SourceUnavailable $e) {
            return $e->getMessage();
        }
        self::fail('expected SourceUnavailable');
    }

    public function testSwagger2KeepsOnlyTheSixFields(): void
    {
        $spec = $this->reducer()->reduce(self::fixture('petstore_v2'));

        self::assertSame('/v2', $spec->base);
        self::assertCount(3, $spec->ops, '$ref path item and the parameters key add nothing');
        [$list, $add, $del] = $spec->ops;
        self::assertEquals(new OperationSummary('GET', '/pets', 'List pets', 'pets', false, ''), $list, 'operationId dropped when a summary exists; first tag only');
        self::assertEquals(new OperationSummary('POST', '/pets', '', 'pets', false, 'addPet'), $add, 'operationId kept as the fallback');
        self::assertEquals(new OperationSummary('DELETE', '/pets/{petId}', 'Delete a pet', 'pets', true, ''), $del);
    }

    public function testOpenApi30BaseFromFirstServerAndStrictFlags(): void
    {
        $spec = $this->reducer()->reduce(self::fixture('orders_v30'));

        self::assertSame('/api/v1', $spec->base);
        $byKey = [];
        foreach ($spec->ops as $o) {
            $byKey[$o->method . ' ' . $o->template] = $o;
        }
        self::assertSame(['GET /orders/{id}', 'DELETE /orders/{id}', 'POST /orders/{id}/refund', 'GET /empty'], array_keys($byKey), 'document order; lower-case verbs only; non-slash key ignored');
        self::assertFalse($byKey['DELETE /orders/{id}']->deprecated, 'deprecated === true only');
        self::assertSame('refundOrder', $byKey['POST /orders/{id}/refund']->operationId);
    }

    public function testServerVariableInUrlGivesEmptyBase(): void
    {
        self::assertSame('', $this->reducer()->reduce(self::fixture('orders_v31'))->base);
    }

    public function testConfiguredBasePathWins(): void
    {
        self::assertSame('/custom', $this->reducer(80, '/custom')->reduce(self::fixture('petstore_v2'))->base);
    }

    public function testVersionGate(): void
    {
        foreach ([['openapi' => '4.0.0'], ['swagger' => '1.2'], [], ['openapi' => 3], ['openapi' => '3.2.0']] as $doc) {
            self::assertSame('unsupported_version', $this->token(fn () => $this->reducer()->reduce($doc + ['paths' => []])));
        }
        self::assertSame(0, count($this->reducer()->reduce(['openapi' => '3.1.0-rc1', 'paths' => []])->ops));
    }

    public function testBadPaths(): void
    {
        self::assertSame('bad_format', $this->token(fn () => $this->reducer()->reduce(['openapi' => '3.0.0'])));
        self::assertSame('bad_format', $this->token(fn () => $this->reducer()->reduce(['openapi' => '3.0.0', 'paths' => 'x'])));
        self::assertSame('bad_format', $this->token(fn () => $this->reducer()->reduce(['openapi' => '3.0.0', 'paths' => [['get' => []]]])));
    }

    public function testStringsAreMaskedThenCapped(): void
    {
        $doc = ['openapi' => '3.0.0', 'paths' => ['/a' => ['get' => ['summary' => 'Mail bob@example.com now', 'tags' => ['t']]]]];
        $spec = $this->reducer()->reduce($doc);
        self::assertStringNotContainsString('bob@example.com', $spec->ops[0]->summary);
        self::assertStringContainsString('<EMAIL>', $spec->ops[0]->summary);

        $long = ['openapi' => '3.0.0', 'paths' => ['/a' => ['get' => ['summary' => str_repeat('x', 500), 'tags' => [str_repeat('t', 500)]]]]];
        $op = $this->reducer(20)->reduce($long)->ops[0];
        self::assertSame(20, mb_strlen($op->summary));
        self::assertSame(20, mb_strlen($op->tag));
    }

    private const GHP = 'ghp_AbCdEfGhIjKlMnOpQrStUvWxYz0123456789';

    /** G1: a secret that straddles the cut must be masked whole, never left as an unmasked prefix. */
    public function testSecretStraddlingTheCutNeverSurvivesInAnyReducedField(): void
    {
        $text = str_repeat('x', 55) . ' ' . self::GHP; // the cut at 80 lands inside the token
        $doc = ['openapi' => '3.0.0', 'paths' => [
            '/a' => ['get' => ['summary' => $text, 'tags' => [$text]]],
            '/b' => ['get' => ['operationId' => $text]],
        ]];
        foreach ([80, 20, 60] as $cap) {
            $spec = $this->reducer($cap)->reduce($doc);
            $encoded = json_encode($spec->toCache('x'), JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString('ghp_', $encoded, "cap $cap");
            self::assertStringNotContainsString('AbCd', $encoded, "cap $cap");
            self::assertCount(2, $spec->ops);
        }
    }

    public function testSecretInAPathIsDroppedNotStoredOrCut(): void
    {
        $doc = ['openapi' => '3.0.0', 'paths' => ['/' . str_repeat('x', 40) . '/' . self::GHP => ['get' => ['summary' => 's']]]];
        $spec = $this->reducer()->reduce($doc);
        self::assertSame([], $spec->ops);
        self::assertSame(1, $spec->dropped);
        self::assertStringNotContainsString('ghp_', json_encode($spec->toCache('x'), JSON_THROW_ON_ERROR));
    }

    public function testHugeInputIsBoundedBeforeMaskingAndNeverLeaksItsTail(): void
    {
        $doc = ['openapi' => '3.0.0', 'paths' => ['/a' => ['get' => ['summary' => str_repeat('y ', 2500) . self::GHP]]]];
        $encoded = json_encode($this->reducer()->reduce($doc)->toCache('x'), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('ghp_', $encoded);
    }

    public function testFingerprintChangesWithEveryReducerSetting(): void
    {
        $base = $this->reducer()->fingerprint();
        self::assertSame($base, $this->reducer()->fingerprint());
        self::assertNotSame($base, $this->reducer(60)->fingerprint());
        self::assertNotSame($base, $this->reducer(80, '/api')->fingerprint());
        self::assertNotSame($base, (new OpenApiReducer(new Redactor(new Normalizer()), 80, null, 5000))->fingerprint());
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $base);
    }

    public function testOperationHasExactlySixProperties(): void
    {
        $names = array_map(static fn ($p) => $p->getName(), (new ReflectionClass(OperationSummary::class))->getProperties());
        self::assertSame(['method', 'template', 'summary', 'tag', 'deprecated', 'operationId'], $names);
    }

    public function testSeededValuesNeverReachTheEncodedEntry(): void
    {
        $encoded = json_encode($this->reducer()->reduce(self::fixture('pii_seeded'))->toCache('x'), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        foreach (['seed', 'jane.doe', 'anna', '@example.com'] as $needle) {
            self::assertStringNotContainsString($needle, $encoded);
        }
        self::assertStringContainsString('/customers/{id}', $encoded);
    }

    public function testEntryIsBoundedAndCountsWhatWasDropped(): void
    {
        $paths = [];
        for ($i = 0; $i < 5000; ++$i) {
            $paths['/resource' . $i . '/{id}'] = ['get' => ['summary' => 'Summary of resource ' . $i, 'tags' => ['group']]];
        }
        $spec = $this->reducer()->reduce(['openapi' => '3.0.0', 'paths' => $paths]);

        self::assertLessThanOrEqual(100000, strlen(json_encode($spec->toCache('2026-10-24T06:00:00Z'), JSON_THROW_ON_ERROR)));
        self::assertGreaterThan(0, $spec->dropped);
        self::assertSame(5000, count($spec->ops) + $spec->dropped);
        self::assertSame('/resource0/{id}', $spec->ops[0]->template, 'document order, the tail is cut');
    }

    public function testTwoMegabyteDocumentFitsTheBound(): void
    {
        $paths = [];
        for ($i = 0; $i < 400; ++$i) {
            $paths['/items' . $i . '/{id}'] = ['get' => [
                'summary' => 'Item ' . $i,
                'description' => str_repeat('long description text ', 200),
                'responses' => ['200' => ['content' => ['application/json' => ['example' => str_repeat('e', 1500)]]]],
            ]];
        }
        $doc = ['openapi' => '3.0.0', 'paths' => $paths];
        self::assertGreaterThan(1_500_000, strlen((string) json_encode($doc)));
        $spec = $this->reducer()->reduce($doc);
        self::assertLessThan(100000, strlen(json_encode($spec->toCache('x'), JSON_THROW_ON_ERROR)));
        self::assertSame(0, $spec->dropped);
    }

    public function testTemplatesTheRedactorRewritesAreDroppedNotStored(): void
    {
        // the Normalizer turns three or more segments into <PATH>/…/tail: such a template can never be matched, and a cache row
        // not starting with "/" would be rejected by ReducedSpec::fromCache on every run
        $doc = ['openapi' => '3.0.0', 'paths' => [
            '/admin/users/search' => ['get' => ['summary' => 'Search']],
            '/api/v1/orders/{id}' => ['get' => ['summary' => 'Get']],
            '/orders/{id}' => ['get' => ['summary' => 'Get an order']],
        ]];
        $spec = $this->reducer()->reduce($doc);

        self::assertSame(['/orders/{id}'], array_map(static fn (OperationSummary $o): string => $o->template, $spec->ops));
        self::assertSame(2, $spec->dropped);
        self::assertEquals($spec, \Watcher\ApiContext\ReducedSpec::fromCache($spec->toCache('x')), 'the entry survives a cache round trip');
    }

    public function testBasePathIsKeptRawEvenWithThreeSegments(): void
    {
        $spec = $this->reducer(80, '/api/v1/x')->reduce(['openapi' => '3.0.0', 'paths' => ['/a' => ['get' => ['summary' => 's']]]]);
        self::assertSame('/api/v1/x', $spec->base);
        self::assertNotNull(\Watcher\ApiContext\ReducedSpec::fromCache($spec->toCache('x')));
    }

    public function testOddBaseFromTheDocumentIsDropped(): void
    {
        $doc = ['openapi' => '3.0.0', 'servers' => [['url' => 'https://h.example/a b@c.example']], 'paths' => []];
        self::assertSame('', $this->reducer()->reduce($doc)->base);
    }

    /** The worst entry the reducer can build must be one the real StateCodec accepts (elements and containers count punctuation inside strings). */
    public function testWorstCaseEntryStaysUnderTheRealStateCodecBudget(): void
    {
        foreach ([',', '[', '{', '"'] as $ch) {
            $paths = [];
            for ($i = 0; $i < 250; ++$i) {
                $op = ['summary' => str_repeat($ch, 80), 'tags' => [str_repeat($ch, 80)], 'deprecated' => true];
                $paths['/a' . $i] = ['get' => $op, 'put' => $op, 'post' => $op, 'delete' => $op];
            }
            $reducer = new OpenApiReducer(new Redactor(new Normalizer()), 200, null);
            $spec = $reducer->reduce(['openapi' => '3.0.0', 'paths' => $paths]);
            $encoded = (string) json_encode($spec->toCache('2026-10-25T06:00:00+00:00'));

            self::assertTrue(StateCodec::fits($encoded), 'char ' . $ch . ': ' . strlen($encoded) . ' bytes');
            self::assertGreaterThan(0, count($spec->ops), 'a bounded spec is still kept');
            self::assertGreaterThan(0, $spec->dropped, 'the rest is counted, not silently lost');
            self::assertSame(1000, count($spec->ops) + $spec->dropped);
            StateCodec::encode($spec->toCache('2026-10-25T06:00:00+00:00'), 0); // would throw StateValueTooLarge
        }
    }
}
