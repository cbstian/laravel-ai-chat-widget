<?php

declare(strict_types=1);

use Cbstian\AiChat\Contracts\ChatDriver;
use Cbstian\AiChat\Contracts\ResolvesChatAgent;
use Cbstian\AiChat\Livewire\AiChatWidget;
use Cbstian\AiChat\Mcp\McpToolCatalog;
use Cbstian\AiChat\Tests\Fixtures\CatalogProbeAgent;
use Cbstian\AiChat\Tests\Fixtures\CatalogProbeAgentFactory;
use Cbstian\AiChat\Tests\Fixtures\Mcp\DemoServer;
use Cbstian\AiChat\Tests\Fixtures\Mcp\HiddenTool;
use Cbstian\AiChat\Tests\Fixtures\Mcp\NotATool;
use Cbstian\AiChat\Tests\Fixtures\Mcp\ReadOnlyTool;
use Cbstian\AiChat\Tests\Fixtures\Mcp\WriteTool;
use Cbstian\AiChat\Tests\Fixtures\User;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Tools\McpServerTool;
use Laravel\Ai\Tools\Request;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

it('keeps mcp off unless the config flag is true', function () {
    expect(config('ai-chat.mcp.enabled'))->toBeFalse()
        ->and(config('ai-chat.mcp.tools'))->toBe([])
        ->and(config('ai-chat.mcp.servers'))->toBe([])
        ->and(app(McpToolCatalog::class)->enabled())->toBeFalse();

    config([
        'ai-chat.mcp.tools' => [ReadOnlyTool::class],
    ]);

    expect(app(McpToolCatalog::class)->tools())->toBe([]);
});

it('treats a published config without the mcp block as disabled', function () {
    $aiChat = config('ai-chat');
    unset($aiChat['mcp']);
    config(['ai-chat' => $aiChat]);

    expect(config('ai-chat.mcp.enabled', false))->toBeFalse()
        ->and(app(McpToolCatalog::class)->tools())->toBe([]);
});

it('returns nothing when laravel mcp is not installed', function () {
    $catalog = new class extends McpToolCatalog
    {
        protected function mcpInstalled(): bool
        {
            return false;
        }
    };

    config([
        'ai-chat.mcp.enabled' => true,
        'ai-chat.mcp.tools' => [ReadOnlyTool::class],
    ]);

    expect($catalog->tools())->toBe([]);
});

it('does not require laravel mcp at runtime', function () {
    $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/composer.json'), true);

    expect($composer['require'])->not->toHaveKey('laravel/mcp')
        ->and($composer['require-dev']['laravel/mcp'])->toBe('^1.1')
        ->and($composer['suggest']['laravel/mcp'])->toBeString();

    $contract = (string) file_get_contents(dirname(__DIR__, 2).'/src/Contracts/ChatDriver.php');

    expect($contract)->toContain('function canAccess')
        ->and($contract)->toContain('function ask(')
        ->and($contract)->not->toContain('function tools(');
});

it('filters the allowlist to read-only tools that should register', function () {
    $debug = mcpDebugLog();

    config([
        'ai-chat.mcp.enabled' => true,
        'ai-chat.mcp.tools' => [
            ReadOnlyTool::class,
            WriteTool::class,
            HiddenTool::class,
            NotATool::class,
            'Missing\\Tool',
        ],
        'ai-chat.mcp.except' => [],
    ]);

    $names = toolNames(app(McpToolCatalog::class)->tools());

    expect($names)->toBe(['read-only-demo'])
        ->and(implode("\n", $debug->getArrayCopy()))->toContain('write-tool')
        ->and(implode("\n", $debug->getArrayCopy()))->toContain('solo lectura');
});

it('drops a tool named in except', function () {
    config([
        'ai-chat.mcp.enabled' => true,
        'ai-chat.mcp.tools' => [ReadOnlyTool::class],
        'ai-chat.mcp.except' => ['read-only-demo'],
    ]);

    expect(app(McpToolCatalog::class)->tools())->toBe([]);
});

it('pulls read-only tools from a server class of this app', function () {
    $debug = mcpDebugLog();

    config([
        'ai-chat.mcp.enabled' => true,
        'ai-chat.mcp.tools' => [],
        'ai-chat.mcp.servers' => [DemoServer::class],
    ]);

    expect(toolNames(app(McpToolCatalog::class)->tools()))->toBe(['read-only-demo'])
        ->and(implode("\n", $debug->getArrayCopy()))->toContain('write-tool');
});

it('passes the authenticated user into the mcp tool', function () {
    $user = User::query()->create([
        'name' => 'Seba',
        'email' => 'mcp-user@example.test',
    ]);

    $this->actingAs($user);

    $text = (new McpServerTool(new ReadOnlyTool))->handle(new Request([]));

    expect($text)->toBe('user:'.$user->getAuthIdentifier());
});

it('lets the app extend the catalog and still skips write tools', function () {
    $debug = mcpDebugLog();

    config([
        'ai-chat.mcp.enabled' => true,
        'ai-chat.mcp.tools' => [],
    ]);

    $catalog = app(McpToolCatalog::class);
    $catalog->extend(fn (): array => [ReadOnlyTool::class, WriteTool::class]);

    expect(toolNames($catalog->tools()))->toBe(['read-only-demo'])
        ->and(implode("\n", $debug->getArrayCopy()))->toContain('write-tool');
});

it('hides mcp tools for a widget that forces them off', function () {
    config([
        'ai-chat.mcp.enabled' => true,
        'ai-chat.mcp.tools' => [ReadOnlyTool::class],
        'ai-chat.agent' => CatalogProbeAgentFactory::class,
    ]);

    app()->forgetInstance(ResolvesChatAgent::class);
    app()->forgetInstance(ChatDriver::class);

    CatalogProbeAgent::fake(['ok']);
    CatalogProbeAgent::$names = [];

    $user = User::query()->create([
        'name' => 'Seba',
        'email' => 'mcp-off@example.test',
    ]);

    $this->actingAs($user);

    ob_start();

    try {
        Livewire::test(AiChatWidget::class, ['mcp' => false])
            ->assertSet('mcp', false)
            ->set('pendingQuestion', 'hola')
            ->call('ask');
    } finally {
        ob_end_clean();
    }

    expect(CatalogProbeAgent::$names)->toBe([]);
    expect(fn () => Livewire::test(AiChatWidget::class, ['mcp' => false])->set('mcp', true))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

it('exposes read-only tools while the widget allows mcp', function () {
    config([
        'ai-chat.mcp.enabled' => true,
        'ai-chat.mcp.tools' => [ReadOnlyTool::class, WriteTool::class],
        'ai-chat.agent' => CatalogProbeAgentFactory::class,
    ]);

    app()->forgetInstance(ResolvesChatAgent::class);
    app()->forgetInstance(ChatDriver::class);

    CatalogProbeAgent::fake(['ok']);
    CatalogProbeAgent::$names = [];

    $user = User::query()->create([
        'name' => 'Seba',
        'email' => 'mcp-on@example.test',
    ]);

    $this->actingAs($user);

    ob_start();

    try {
        Livewire::test(AiChatWidget::class)
            ->assertSet('mcp', true)
            ->set('pendingQuestion', 'hola')
            ->call('ask');
    } finally {
        ob_end_clean();
    }

    expect(CatalogProbeAgent::$names)->toBe(['read-only-demo']);
});

function mcpDebugLog(): ArrayObject
{
    $debug = new ArrayObject;

    Log::listen(function (MessageLogged $event) use ($debug): void {
        if ($event->level === 'debug') {
            $debug[] = $event->message;
        }
    });

    return $debug;
}

/**
 * @param  list<object>  $tools
 * @return list<string>
 */
function toolNames(array $tools): array
{
    $names = [];

    foreach ($tools as $tool) {
        if (is_object($tool) && method_exists($tool, 'name')) {
            $name = $tool->name();

            if (is_string($name)) {
                $names[] = $name;
            }
        }
    }

    return $names;
}
