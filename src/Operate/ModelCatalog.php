<?php

declare(strict_types=1);

namespace Watcher\Operate;

/**
 * Optional, provider-specific: what a provider's free models-list endpoint says. Found with `instanceof` by the Doctor;
 * a provider without one gets a `skip`, not a `fail`. Never makes a billable call.
 */
interface ModelCatalog
{
    /**
     * Calls the models list with each auth scheme.
     *
     * @return array<string, int|string> scheme ('x-api-key' = the one the analyzer sends, 'bearer' = the old SDK scheme) => HTTP status, or 'timeout' / 'error'
     */
    public function probe(): array;

    /**
     * Model ids of the last probe (from the scheme that worked, 'x-api-key' first); probes first when nothing was probed yet.
     *
     * @return list<string>
     */
    public function modelIds(): array;
}
