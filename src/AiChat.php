<?php

declare(strict_types=1);

namespace Cbstian\AiChat;

use Cbstian\AiChat\Contracts\ChatDriver;

class AiChat
{
    public function driver(): ChatDriver
    {
        return app(ChatDriver::class);
    }
}
