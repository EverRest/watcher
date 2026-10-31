<?php

declare(strict_types=1);

namespace Watcher\Mcp;

use Throwable;

/**
 * The fixed tool list for the enabled groups (FR-014, FR-060), argument validation (FR-041) and the secret guard (FR-064).
 * A tool of a group that is off is absent from the list and `-32602` on call.
 */
final class ToolRegistry
{
    private const GUARDED = ['onboarding', 'docs'];

    /** @var array<string, Tool> */
    private array $enabled = [];

    /**
     * @param list<Tool> $tools every tool the process could serve
     * @param list<string> $groups enabled groups
     * @param bool $allowDryRun `mcp.allow_dry_run`
     * @param resource|null $err
     */
    public function __construct(array $tools, array $groups, bool $allowDryRun, private readonly Envelope $envelope, private readonly SecretGuard $guard, private $err = null)
    {
        foreach ($tools as $t) {
            if (in_array($t->group(), $groups, true) && ($t->name() !== 'watcher_dry_run' || $allowDryRun)) {
                $this->enabled[$t->name()] = $t;
            }
        }
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->enabled);
    }

    /** @return list<array<string, mixed>> */
    public function list(): array
    {
        $out = [];
        foreach ($this->enabled as $t) {
            $schema = $t->inputSchema();
            if (($schema['properties'] ?? []) === []) {
                $schema['properties'] = (object) []; // JSON object, not []
            }
            $out[] = [
                'name' => $t->name(),
                'description' => $t->description(),
                'inputSchema' => $schema,
                'annotations' => ['readOnlyHint' => $t->group() !== 'actions', 'destructiveHint' => $t->group() === 'actions', 'openWorldHint' => $t->openWorld()],
            ];
        }

        return $out;
    }

    /**
     * @param array<mixed> $arguments
     * @return array{content: list<array{type: string, text: string}>, isError: bool}
     * @throws InvalidParams
     */
    public function call(string $name, array $arguments): array
    {
        $tool = $this->enabled[$name] ?? null;
        if ($tool === null) {
            throw new InvalidParams('invalid params: name');
        }
        try {
            if (in_array($tool->group(), self::GUARDED, true)) {
                $this->guard->assertClean($arguments); // before the schema: a secret is never reported as a "pattern mismatch"
            }
            if (($bad = SchemaCheck::violation($tool->inputSchema(), $arguments)) !== null) {
                throw new InvalidParams('invalid params' . ($bad === '' ? '' : ': ' . $bad));
            }
            ob_start();
            try {
                $data = $tool->call($arguments);
            } finally {
                $stray = (string) ob_get_clean();
            }
            if ($stray !== '') {
                $this->log('stray output from ' . $name); // stdout is the protocol channel: it is dropped, not forwarded
            }

            return self::result($this->envelope->ok($name, $data), false);
        } catch (SecretRefused $e) {
            $this->log($e::class . ' in ' . $name);

            return self::result($this->envelope->error($name, 'secret_refused'), true);
        } catch (ToolError $e) {
            return self::result($this->envelope->error($name, $e->errorCode, $e->extra), true);
        } catch (InvalidParams $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->log($e::class . ' in ' . $name);

            return self::result($this->envelope->error($name, 'internal_error'), true);
        }
    }

    /** @return array{content: list<array{type: string, text: string}>, isError: bool} */
    private static function result(string $json, bool $isError): array
    {
        return ['content' => [['type' => 'text', 'text' => $json]], 'isError' => $isError];
    }

    private function log(string $what): void
    {
        if ($this->err !== null) {
            fwrite($this->err, 'watcher [error] ' . $what . "\n");
        }
    }
}
