<?php

declare(strict_types=1);

namespace Watcher\Laravel;

use Closure;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Watcher\Config\InvalidConfig;

final class StateGetCommand extends Command
{
    protected $signature = 'watcher:state-get {key : heartbeat, last-snapshot, snapshot:<window end> or run:<window end>}';

    protected $description = 'Read-only: print one state entry with every string redacted and cut to 200 characters (exit 1 when nothing is stored)';

    /** @param Closure(string): ?string $factory the logical key to the text; throws InvalidArgumentException for a refused key, InvalidConfig for a bad config */
    public function __construct(private readonly Closure $factory)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $key = $this->argument('key');
            $text = ($this->factory)(is_string($key) ? $key : '');
        } catch (InvalidConfig $e) {
            $this->error('invalid config: ' . $e->getMessage());

            return 2;
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return 2;
        }
        if ($text === null) {
            $this->error('state:get: no entry stored for that key');

            return 1;
        }
        $this->line($text);

        return 0;
    }
}
