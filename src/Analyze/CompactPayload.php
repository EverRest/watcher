<?php

declare(strict_types=1);

namespace Watcher\Analyze;

use Watcher\Privacy\RedactedGroup;

/** Token-lean model input: each distinct template once in `t`, groups point at it by index. */
final class CompactPayload
{
    /** @param list<RedactedGroup> $groups */
    public static function encode(array $groups): string
    {
        $templates = [];
        $rows = [];
        foreach ($groups as $g) {
            $row = $g->toArray();
            $tpl = $row['tpl'];
            unset($row['tpl']);
            if ($tpl === null) {
                $idx = null;
            } else {
                $idx = array_search($tpl, $templates, true);
                if ($idx === false) {
                    $templates[] = $tpl;
                    $idx = count($templates) - 1;
                }
            }
            // "t" first, right after the fingerprint
            $rows[] = ['fp' => $row['fp'], 't' => $idx] + $row;
        }

        return json_encode(['t' => $templates, 'g' => $rows], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
