<?php

declare(strict_types=1);

namespace Cbstian\AiChat\Speech;

use Cbstian\AiChat\Contracts\SpeechToTextDriver;
use Cbstian\AiChat\Dto\SpeechAudio;
use Cbstian\AiChat\Dto\SpeechTranscript;
use InvalidArgumentException;
use Laravel\Ai\Ai;
use Laravel\Ai\Transcription;
use LogicException;
use Throwable;

final class LaravelAiSpeechToTextDriver implements SpeechToTextDriver
{
    public function runsInBrowser(): bool
    {
        return false;
    }

    public function transcribe(SpeechAudio $audio): SpeechTranscript
    {
        $provider = $this->provider();
        $model = data_get(config('ai-chat.voice'), 'laravel-ai.model');
        $model = is_string($model) && trim($model) !== '' ? trim($model) : null;
        $diarize = VoiceValues::bool(data_get(config('ai-chat.voice'), 'laravel-ai.diarize', false), false);

        $pending = Transcription::fromPath($audio->path, $audio->mimeType);
        $language = VoiceLanguage::forLaravelAi();

        if ($language !== null) {
            $pending = $pending->language($language);
        }

        if ($diarize) {
            $pending = $pending->diarize();
        }

        try {
            $response = $pending->generate($provider, $model);
        } catch (Throwable) {
            throw new VoiceTranscriptionException('No se pudo transcribir.');
        }

        return new SpeechTranscript($response->text);
    }

    private function provider(): string
    {
        $configured = data_get(config('ai-chat.voice'), 'laravel-ai.provider');
        $configured = is_string($configured) ? trim($configured) : '';

        if ($configured === '') {
            $fallback = config('ai.default');
            $fallback = is_string($fallback) ? trim($fallback) : '';

            if ($fallback === '' || ! $this->supportsTranscription($fallback)) {
                throw new VoiceTranscriptionException('Configura voice.laravel-ai.provider con un provider que transcriba audio.');
            }

            return $fallback;
        }

        if (! $this->supportsTranscription($configured)) {
            throw new VoiceTranscriptionException('El provider ['.$configured.'] no transcribe audio.');
        }

        return $configured;
    }

    private function supportsTranscription(string $provider): bool
    {
        try {
            Ai::transcriptionProvider($provider);

            return true;
        } catch (LogicException|InvalidArgumentException) {
            return false;
        }
    }
}
