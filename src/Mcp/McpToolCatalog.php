<?php

declare(strict_types=1);

namespace Cbstian\AiChat\Mcp;

use Closure;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Contracts\Transport;
use Laravel\Mcp\Server\Tool;
use Throwable;

class McpToolCatalog
{
    private bool $forcedOff = false;

    /** @var list<callable(): iterable<mixed>> */
    private array $extensions = [];

    /**
     * @param  callable(): iterable<mixed>  $resolver
     */
    public function extend(callable $resolver): void
    {
        $this->extensions[] = $resolver;
    }

    public function forceOff(bool $off): void
    {
        $this->forcedOff = $off;
    }

    /**
     * @return list<object>
     */
    public function tools(): array
    {
        if ($this->forcedOff || ! $this->enabled() || ! $this->mcpInstalled()) {
            return [];
        }

        $accepted = [];
        $seen = [];

        foreach ($this->candidates() as $candidate) {
            $tool = $this->makeTool($candidate);

            if (! $tool instanceof Tool) {
                continue;
            }

            if (! $this->eligible($tool)) {
                continue;
            }

            $name = $tool->name();

            if ($name === '' || isset($seen[$name]) || in_array($name, $this->except(), true)) {
                continue;
            }

            if (! $this->readOnly($tool)) {
                Log::debug('Se omite la tool MCP ['.$name.'] porque no es de solo lectura. La confirmación de escritura no está en esta versión.');

                continue;
            }

            $seen[$name] = true;
            $accepted[] = $tool;
        }

        return $accepted;
    }

    protected function mcpInstalled(): bool
    {
        return class_exists(Tool::class) && class_exists(Server::class);
    }

    public function enabled(): bool
    {
        $value = config('ai-chat.mcp.enabled', false);

        if ($value === null || $value === false) {
            return false;
        }

        if ($value === true) {
            return true;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        if (is_string($value) && $value !== '') {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }

        return false;
    }

    /**
     * @return list<mixed>
     */
    private function candidates(): array
    {
        $candidates = [];

        foreach ($this->configured('tools') as $class) {
            $candidates[] = $class;
        }

        foreach ($this->serverTools() as $tool) {
            $candidates[] = $tool;
        }

        foreach ($this->extensions as $resolver) {
            try {
                $resolved = $resolver();
            } catch (Throwable) {
                Log::debug('Se omite una extensión del catálogo MCP porque no se pudo resolver.');

                continue;
            }

            foreach ($resolved as $item) {
                $candidates[] = $item;
            }
        }

        return $candidates;
    }

    /**
     * @return list<object>
     */
    private function serverTools(): array
    {
        if (! class_exists(Server::class) || ! interface_exists(Transport::class)) {
            return [];
        }

        $transport = new class implements Transport
        {
            public function onReceive(Closure $handler): void {}

            public function send(string $message): void {}

            public function stream(Closure $stream): void {}

            public function run(): void {}
        };

        $tools = [];

        foreach ($this->configured('servers') as $class) {
            if (! is_a($class, Server::class, true)) {
                continue;
            }

            try {
                $server = new $class($transport);

                foreach ($server->createContext()->tools() as $tool) {
                    $tools[] = $tool;
                }
            } catch (Throwable) {
                Log::debug('Se omite el servidor MCP ['.$class.'] porque no se pudo instanciar en proceso.');
            }
        }

        return $tools;
    }

    private function makeTool(mixed $candidate): ?object
    {
        if ($candidate instanceof Tool) {
            return $candidate;
        }

        if (! is_string($candidate) || ! is_a($candidate, Tool::class, true)) {
            return null;
        }

        try {
            $tool = app($candidate);
        } catch (Throwable) {
            Log::debug('Se omite la tool MCP ['.$candidate.'] porque no se pudo instanciar.');

            return null;
        }

        return $tool instanceof Tool ? $tool : null;
    }

    private function eligible(Tool $tool): bool
    {
        try {
            return $tool->eligibleForRegistration();
        } catch (Throwable) {
            Log::debug('Se omite una tool MCP porque shouldRegister falló.');

            return false;
        }
    }

    private function readOnly(Tool $tool): bool
    {
        try {
            $annotations = $tool->annotations();
        } catch (Throwable) {
            return false;
        }

        return ($annotations['readOnlyHint'] ?? false) === true;
    }

    /**
     * @return list<string>
     */
    private function configured(string $key): array
    {
        $value = config('ai-chat.mcp.'.$key, []);

        if (! is_array($value)) {
            return [];
        }

        $names = [];

        foreach ($value as $item) {
            if (is_string($item) && $item !== '') {
                $names[] = $item;
            }
        }

        return $names;
    }

    /**
     * @return list<string>
     */
    private function except(): array
    {
        return $this->configured('except');
    }
}
