<?php

declare(strict_types=1);

namespace Cbstian\AiChat\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\AiServiceProvider;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Cbstian\AiChat\AiChatServiceProvider;
use Cbstian\AiChat\Tests\Fixtures\AllowAllChatAccess;
use Cbstian\AiChat\Tests\Fixtures\FakeChatAgentFactory;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai-chat.enabled' => true,
            'ai-chat.persist' => true,
            'ai-chat.verbose_logs' => true,
            'ai-chat.agent' => FakeChatAgentFactory::class,
            'ai-chat.access' => AllowAllChatAccess::class,
            'ai-chat.provider' => 'openai',
            'ai-chat.model' => 'gpt-4o-mini',
            'ai.default' => 'openai',
            'ai.providers.openai.key' => 'test-key',
        ]);

        $this->setUpDatabase();
    }

    protected function getPackageProviders($app): array
    {
        return [
            AiServiceProvider::class,
            LivewireServiceProvider::class,
            AiChatServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    }

    protected function setUpDatabase(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->timestamps();
        });

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $aiMigrations = __DIR__.'/../vendor/laravel/ai/database/migrations';

        if (is_dir($aiMigrations)) {
            $this->loadMigrationsFrom($aiMigrations);
        }
    }
}
