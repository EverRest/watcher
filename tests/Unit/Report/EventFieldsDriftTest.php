<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Report;

use PHPUnit\Framework\TestCase;
use Watcher\Observe\EventSchema;
use Watcher\Report\EventLog;

final class EventFieldsDriftTest extends TestCase
{
    public function testAllowlistFieldsExistInEventSchema(): void
    {
        $envelope = ['schema', 'event_type', 'service', 'environment', 'window_end'];
        foreach ($envelope as $f) {
            self::assertContains($f, EventSchema::ENVELOPE, $f);
        }
        foreach (EventLog::RUN_FIELDS as $f) {
            self::assertContains($f, EventSchema::FIELDS['run'], 'run.' . $f);
        }
        foreach (EventLog::GROUP_FIELDS as $f) {
            self::assertContains($f, EventSchema::FIELDS['group'], 'group.' . $f);
        }
        foreach (EventLog::FINDING_FIELDS as $f) {
            self::assertContains($f, EventSchema::FIELDS['finding'], 'finding.' . $f);
        }
    }
}
