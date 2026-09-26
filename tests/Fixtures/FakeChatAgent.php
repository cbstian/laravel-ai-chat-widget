<?php

declare(strict_types=1);

namespace Cbstian\AiChat\Tests\Fixtures;

use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Promptable;
use Stringable;

class FakeChatAgent implements Agent, Conversational
{
    use Promptable;
    use RemembersConversations;

    public function instructions(): Stringable|string
    {
        return 'Eres un agente de prueba para el paquete ai-chat.';
    }
}
