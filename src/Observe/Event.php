<?php

declare(strict_types=1);

namespace Watcher\Observe;

/** One event of the stream. Free text is kept apart so each sink can leave it out (include_text). */
final class Event
{
    /**
     * @param array<string, mixed> $fields envelope and type fields
     * @param array<string, mixed> $text   title, evidence, ... (finding) or error_type (group)
     */
    public function __construct(
        public readonly string $type,
        public readonly array $fields,
        public readonly array $text = [],
    ) {
    }

    /** @return array<string, mixed> keys in the order of EventSchema::fields(); unknown keys are not carried */
    public function toArray(bool $includeText): array
    {
        $all = $includeText ? $this->fields + $this->text : $this->fields;
        $out = [];
        foreach (EventSchema::fields($this->type, $includeText) as $key) {
            if (array_key_exists($key, $all)) {
                $out[$key] = $all[$key];
            }
        }

        return $out;
    }
}
