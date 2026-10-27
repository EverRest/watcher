<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Analyze;

use PHPUnit\Framework\TestCase;
use Watcher\Analyze\ModelProvider;
use Watcher\Analyze\ModelReply;
use Watcher\Analyze\ProviderRegistry;

/** A fourth provider needs only an adapter and configuration: the registry resolves it per step with no change to the core. */
final class ProviderRegistryTest extends TestCase
{
    public function testEachStepResolvesToItsOwnProvider(): void
    {
        $a = $this->named('a');
        $b = $this->named('b');
        $registry = new ProviderRegistry(['alpha' => $a, 'fourth' => $b], ['triage' => 'fourth', 'analyze' => 'alpha', 'escalate' => 'alpha']);

        self::assertSame($b, $registry->for('triage'));
        self::assertSame($a, $registry->for('analyze'));
        self::assertSame($a, $registry->for('escalate'));
    }

    public function testAStepWithoutAProviderFailsLoudly(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('no provider for step escalate');
        (new ProviderRegistry(['alpha' => $this->named('a')], ['triage' => 'alpha', 'analyze' => 'alpha']))->for('escalate');
    }

    private function named(string $name): ModelProvider
    {
        return new class ($name) implements ModelProvider {
            public function __construct(private readonly string $name)
            {
            }

            public function create(string $model, int $maxTokens, string $system, string $user, array $schema, bool $thinking): ModelReply
            {
                return new ModelReply($this->name, 'end', $model);
            }
        };
    }
}
