<?php

declare(strict_types=1);

use Illuminate\Validation\ValidationException;
use Cbstian\AiChat\Contracts\ChatDriver;
use Cbstian\AiChat\Tests\Fixtures\DenyAllChatAccess;
use Cbstian\AiChat\Tests\Fixtures\User;

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
    app()->forgetInstance(\Cbstian\AiChat\Contracts\ResolvesChatAccess::class);
    app()->forgetInstance(ChatDriver::class);

    $user = User::query()->create([
        'name' => 'Seba',
        'email' => 'deny@example.test',
    ]);

    expect(app(ChatDriver::class)->canAccess($user))->toBeFalse();
});

it('rejects ask when access is denied', function () {
    config(['ai-chat.access' => DenyAllChatAccess::class]);
    app()->forgetInstance(\Cbstian\AiChat\Contracts\ResolvesChatAccess::class);
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
