<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Mcp;

use PHPUnit\Framework\TestCase;
use Watcher\Config\InvalidConfig;
use Watcher\Mcp\McpSettings;

final class McpSettingsGroupsTest extends TestCase
{
    public function testActionsIsNotOnUnlessNamed(): void
    {
        self::assertSame(['inspect', 'onboarding', 'docs'], McpSettings::fromArray([])->groups);
    }

    public function testActionsCanBeNamed(): void
    {
        self::assertSame(['inspect', 'actions'], McpSettings::fromArray(['groups' => ['inspect', 'actions']])->groups);
    }

    public function testAnUnknownGroupIsRejectedAndTheMessageNamesActions(): void
    {
        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage('inspect, onboarding, docs, actions');

        McpSettings::fromArray(['groups' => ['writes']]);
    }

    public function testTheArtisanPathIsAnAbsolutePathOrNull(): void
    {
        self::assertSame('/var/www/html/artisan', McpSettings::fromArray(['artisan' => '/var/www/html/artisan'])->artisan);
        self::assertNull(McpSettings::fromArray([])->artisan);

        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage('mcp.artisan must be an absolute path');

        McpSettings::fromArray(['artisan' => 'artisan']);
    }
}
