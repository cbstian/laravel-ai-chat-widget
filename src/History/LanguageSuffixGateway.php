<?php

declare(strict_types=1);

namespace Cbstian\AiChat\History;

use Generator;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Gateway\StepTextGateway;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Streaming\Events\StreamEvent;

/**
 * Añade el idioma al final de las instrucciones que el loop ya resolvió del agente.
 *
 * AgentPrompt::agent es readonly y el SDK persiste `$agent::class` en la fila.
 * Envolver el agente cambiaría esa clase. El gateway es el último punto que
 * todavía recibe el string, sin forkear laravel/ai.
 */
final class LanguageSuffixGateway implements StepTextGateway
{
    public function __construct(
        private readonly StepTextGateway $inner,
        private readonly string $language,
        private readonly HistorySettings $settings,
    ) {}

    /**
     * @param  Message[]  $messages
     * @param  Tool[]  $tools
     * @param  array<string, Type>|null  $schema
     */
    public function generateTextStep(
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
        StepContext $stepContext,
    ): StepResponse {
        return $this->inner->generateTextStep(
            $provider,
            $model,
            $this->withLanguage($instructions),
            $messages,
            $tools,
            $schema,
            $options,
            $timeout,
            $stepContext,
        );
    }

    /**
     * @param  Message[]  $messages
     * @param  Tool[]  $tools
     * @param  array<string, Type>|null  $schema
     * @return Generator<int, StreamEvent, mixed, StepResponse|null>
     */
    public function generateStreamStep(
        string $invocationId,
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
        StepContext $stepContext,
    ): Generator {
        return $this->inner->generateStreamStep(
            $invocationId,
            $provider,
            $model,
            $this->withLanguage($instructions),
            $messages,
            $tools,
            $schema,
            $options,
            $timeout,
            $stepContext,
        );
    }

    public function withLanguage(?string $instructions): string
    {
        $line = $this->settings->languageLine($this->language);
        $base = rtrim((string) $instructions);

        if ($base === '') {
            return $line;
        }

        if (str_ends_with($base, $line)) {
            return $base;
        }

        return $base."\n\n".$line;
    }
}
