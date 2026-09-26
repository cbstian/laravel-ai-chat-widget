<?php

declare(strict_types=1);

use Cbstian\AiChat\Dto\ChatTurnResult;

it('builds a successful ChatTurnResult', function () {
    $result = new ChatTurnResult(
        conversationId: 'conv_1',
        text: 'hola',
        succeeded: true,
        provider: 'openai',
        model: 'gpt-4o-mini',
    );

    expect($result->conversationId)->toBe('conv_1')
        ->and($result->text)->toBe('hola')
        ->and($result->succeeded)->toBeTrue();
});
