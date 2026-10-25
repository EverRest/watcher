<?php

declare(strict_types=1);

namespace Watcher\ApiContext;

use Throwable;
use Watcher\Source\SourceUnavailable;

/** Cache hit -> reduced spec; miss -> source -> reducer -> cache. Never throws: a failure is a token (Principle VII). */
final class ApiContextReader
{
    public function __construct(
        private readonly OpenApiSource $source,
        private readonly OpenApiReducer $reducer,
        private readonly ApiSpecCache $cache,
        private readonly string $service,
    ) {
    }

    public function spec(): ApiContextResult
    {
        $id = $this->source->id() . '-' . $this->reducer->fingerprint(); // source + reducer settings: a settings change is a miss
        try {
            $hit = $this->cache->get($this->service, $id);
        } catch (Throwable) {
            $hit = null; // a broken store costs a read, not the feature
        }
        if ($hit !== null) {
            return new ApiContextResult($hit, null, 'hit');
        }

        $fresh = $this->readFresh();
        if ($fresh->spec === null) {
            return new ApiContextResult(null, $fresh->failure, 'failed');
        }
        try {
            $this->cache->put($this->service, $id, $fresh->spec);
        } catch (Throwable) {
            // not cached: the next run reads again
        }

        return new ApiContextResult($fresh->spec, null, 'miss');
    }

    /** Source -> reducer without touching the cache (no get, no put): `watcher check`. */
    public function readFresh(): ApiContextResult
    {
        try {
            return new ApiContextResult($this->reducer->reduce($this->source->read()), null, 'none');
        } catch (SourceUnavailable $e) {
            return new ApiContextResult(null, $e->getMessage(), 'none');
        } catch (Throwable) {
            return new ApiContextResult(null, 'unreadable', 'none');
        }
    }
}
