<?php

declare(strict_types=1);

namespace Watcher\Mcp\Tools;

use Watcher\Mcp\ConfigFileReader;
use Watcher\Mcp\ConfigFileValidator;
use Watcher\Mcp\PathPolicy;
use Watcher\Mcp\Tool;

final class ValidateConfigTool implements Tool
{
    public function __construct(private readonly PathPolicy $policy, private readonly ConfigFileReader $reader, private readonly ConfigFileValidator $validator)
    {
    }

    public function name(): string
    {
        return 'watcher_validate_config';
    }

    public function group(): string
    {
        return 'onboarding';
    }

    public function description(): string
    {
        return 'Validates a watcher configuration file next to the server (the same validator as `watcher check`, no network). Reports problems by key path with hints; never returns a value from the file.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'required' => ['path'], 'properties' => ['path' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 1024, 'x-path' => true, 'description' => 'FR-067 policy: realpath inside an allowed root, regular .php file']]];
    }

    public function openWorld(): bool
    {
        return false;
    }

    public function call(array $args): array
    {
        $file = $this->reader->read($this->policy->approve((string) $args['path']));
        try {
            $r = $this->validator->validate($file);
        } finally {
            $file->close();
        }
        unset($r['config']);
        $r['problems'] = array_map(static fn (array $p): array => ['key' => $p['key'], 'code' => $p['code'], 'fix' => $p['fix'], 'docs' => $p['docs'], 'did_you_mean' => $p['did_you_mean']], $r['problems']);

        return $r;
    }
}
