<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Watcher\Config\WatchConfig;

/** Spec 012: privacy is not optional. No flag may switch off the Normalizer, the Redactor or the allowlist. */
final class FeatureFlagsArchitectureTest extends TestCase
{
    public function testNoFlagNamesPrivacyMachinery(): void
    {
        foreach (WatchConfig::FLAGS as $flag) {
            self::assertDoesNotMatchRegularExpression('/redact|normaliz|allowlist|privacy|scrub/i', $flag);
        }
    }

    public function testNoCodePathConditionsRedactionOnAFlag(): void
    {
        foreach (['src/Watcher.php', 'src/Analyze/ModelRouter.php', 'src/Privacy/Redactor.php'] as $file) {
            foreach (file(__DIR__ . '/../../' . $file) ?: [] as $n => $line) {
                self::assertDoesNotMatchRegularExpression('/->on\(.*(Redactor|Normalizer|redact\()|(Redactor|Normalizer|redact\().*->on\(/', $line, "$file:" . ($n + 1) . ' gates redaction on a flag');
            }
        }
        $src = (string) file_get_contents(__DIR__ . '/../../src/Watcher.php');
        self::assertStringContainsString('$redactor = new Redactor(', $src, 'the Redactor is built unconditionally');
        self::assertMatchesRegularExpression('/\$redacted(By)?\[.*\] = \$redactor->redact\(/', $src);
    }

    public function testRedactionSectionAcceptsNoOnOffKey(): void
    {
        $c = (new \ReflectionClass(WatchConfig::class))->getFileName();
        $text = (string) file_get_contents((string) $c);

        self::assertStringContainsString("['extra_patterns', 'min_group_events_for_text'], 'redaction'", $text);
    }

    public function testThereIsNoReadOnlySwitch(): void
    {
        $text = (string) file_get_contents(__DIR__ . '/../../src/Config/WatchConfig.php');

        self::assertDoesNotMatchRegularExpression('/read_only|readonly_mode/i', $text);
    }
}
