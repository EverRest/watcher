<?php

declare(strict_types=1);

namespace Watcher\Probe;

/** A deterministic check that makes no model call and reads no log line (certificates, declared secret expiry). */
interface Probe
{
    public function name(): string;

    /** @return list<ProbeResult> one per subject; never throws for an unreachable subject (that is `unknown`) */
    public function check(): array;
}
