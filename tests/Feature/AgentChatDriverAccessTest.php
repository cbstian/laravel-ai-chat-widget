<?php

declare(strict_types=1);

use Cbstian\AiChat\Contracts\ChatDriver;
use Cbstian\AiChat\Contracts\ResolvesChatAccess;
use Cbstian\AiChat\Contracts\ResolvesChatAgent;
use Cbstian\AiChat\Tests\Fixtures\DenyAllChatAccess;
use Cbstian\AiChat\Tests\Fixtures\User;
use Illuminate\Validation\ValidationException;

it('denies access when the package is disabled', function () {
    config(['ai-chat.enabled' => false]);

    $user = User::query()->create([
        'name' => 'Seba',
        'email' => 'seba@example.test',
    ]);

    expect(app(ChatDriver::class)->canAccess($user))->toBeFalse();
});

it('denies access for guests', function () {
    expect(app(ChatDriver::class)->canAccess(null))->toBeFalse();
});

it('denies access when ResolvesChatAccess returns false', function () {
    config(['ai-chat.access' => DenyAllChatAccess::class]);
    app()->forgetInstance(ResolvesChatAccess::class);
    app()->forgetInstance(ChatDriver::class);

    $user = User::query()->create([
        'name' => 'Seba',
        'email' => 'deny@example.test',
    ]);

    expect(app(ChatDriver::class)->canAccess($user))->toBeFalse();
});

it('rejects ask when access is denied', function () {
    config(['ai-chat.access' => DenyAllChatAccess::class]);
    app()->forgetInstance(ResolvesChatAccess::class);
    app()->forgetInstance(ChatDriver::class);

    $user = User::query()->create([
        'name' => 'Seba',
        'email' => 'ask-deny@example.test',
    ]);

    expect(fn () => app(ChatDriver::class)->ask(
        $user,
        'Hola',
        null,
        [],
        fn () => null,
    ))->toThrow(ValidationException::class);
});

it('allows any authenticated user when access is not configured', function () {
    config(['ai-chat.access' => null]);
    app()->forgetInstance(ResolvesChatAccess::class);
    app()->forgetInstance(ChatDriver::class);

    $user = User::query()->create([
        'name' => 'Seba',
        'email' => 'open-access@example.test',
    ]);

    expect(app(ResolvesChatAccess::class))->toBeNull()
        ->and(app(ChatDriver::class)->canAccess($user))->toBeTrue();
});

it('rejects an agent class that does not implement ResolvesChatAgent', function () {
    config(['ai-chat.agent' => stdClass::class]);
    app()->forgetInstance(ResolvesChatAgent::class);
    app()->forgetInstance(ChatDriver::class);

    expect(fn () => app(ChatDriver::class))->toThrow(RuntimeException::class);
});

it('rejects an access class that does not implement ResolvesChatAccess', function () {
    config(['ai-chat.access' => stdClass::class]);
    app()->forgetInstance(ResolvesChatAccess::class);
    app()->forgetInstance(ChatDriver::class);

    expect(fn () => app(ChatDriver::class))->toThrow(RuntimeException::class);
});

it('rejects a driver class that does not implement ChatDriver', function () {
    config(['ai-chat.driver' => stdClass::class]);
    app()->forgetInstance(ChatDriver::class);

    expect(fn () => app(ChatDriver::class))->toThrow(RuntimeException::class);
});

it('rejects empty prompts', function () {
    $user = User::query()->create([
        'name' => 'Seba',
        'email' => 'empty@example.test',
    ]);

    expect(fn () => app(ChatDriver::class)->ask(
        $user,
        '   ',
        null,
        [],
        fn () => null,
    ))->toThrow(ValidationException::class);
});
