<?php

declare(strict_types=1);

namespace Watcher\Analyze;

use Watcher\Context\DependencyContext;
use Watcher\Privacy\RedactedGroup;

/** Token-lean model input: each distinct template once in `t`, groups point at it by index. */
final class CompactPayload
{
    /**
     * @param list<RedactedGroup>     $groups
     * @param list<DependencyContext> $dependencyContext adds the `d` section; absent when empty
     */
    public static function encode(array $groups, array $dependencyContext = []): string
    {
        $templates = [];
        $rows = [];
        $at = static function (?string $tpl) use (&$templates): ?int {
            if ($tpl === null) {
                return null;
            }
            $idx = array_search($tpl, $templates, true);
            if ($idx === false) {
                $templates[] = $tpl;
                $idx = count($templates) - 1;
            }

            return $idx;
        };
        $own = [];
        foreach ($groups as $i => $g) {
            $row = $g->toArray();
            $idx = $at($row['tpl']);
            $own[$i] = $idx;
            unset($row['tpl']);
            // "t" first, right after the fingerprint
            $rows[] = ['fp' => $row['fp'], 't' => $idx] + $row;
        }

        $deps = [];
        foreach ($dependencyContext as $dep) {
            if ($dep->unavailable) {
                continue;
            }
            $top = [];
            foreach ($dep->topErrors as $g) {
                // No `fp`: the model must not cite a dependency group as a finding.
                $top[] = ['t' => $at($g->template), 'lvl' => strtolower($g->level->name), 'err' => $g->errorType, 'n' => $g->count, 'prev' => $g->previousCount, 'st' => $g->status->value];
            }
            $refs = [];
            foreach ($dep->referencedByTemplateIndexes as $i) {
                if (($own[$i] ?? null) !== null && !in_array($own[$i], $refs, true)) {
                    $refs[] = $own[$i];
                }
            }
            $deps[] = ['n' => $dep->name, 'err' => $dep->errors(), 'prev' => $dep->previousErrors(), 'top' => $top, 'r' => $refs];
        }
        $out = ['t' => $templates, 'g' => $rows];
        if ($deps !== []) {
            $out['d'] = $deps;
        }

        return json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
