<?php

declare(strict_types=1);

namespace Cbstian\AiChat\Tests\Fixtures;

use Cbstian\AiChat\Livewire\AiChatWidget;

class RecordingAiChatWidget extends AiChatWidget
{
    /** @var list<array{content: string, replace: bool, to: mixed}> */
    public array $streamed = [];

    public function stream($content = null, $replace = false, $name = null, $el = null, $ref = null, $to = null): mixed
    {
        $this->streamed[] = [
            'content' => is_string($content) ? $content : '',
            'replace' => (bool) $replace,
            'to' => $to,
        ];

        return null;
    }
}
