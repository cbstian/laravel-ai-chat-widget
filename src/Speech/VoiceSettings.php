<?php

declare(strict_types=1);

namespace Cbstian\AiChat\Speech;

use Cbstian\AiChat\Contracts\SpeechToTextDriver;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\RateLimiter;

final class VoiceSettings
{
    public function __construct(
        private readonly FilamentPanelId $panels,
    ) {}

    public function enabled(): bool
    {
        return VoiceValues::bool(data_get(config('ai-chat.voice'), 'enabled', false), false);
    }

    public function autoSubmit(): bool
    {
        return VoiceValues::bool(data_get(config('ai-chat.voice'), 'auto_submit', false), false);
    }

    public function allowsCurrentPanel(): bool
    {
        $configured = data_get(config('ai-chat.voice'), 'panels');

        if ($configured === null) {
            return true;
        }

        if (! is_array($configured)) {
            return true;
        }

        $current = $this->panels->current();

        if ($current === null) {
            return true;
        }

        $ids = [];

        foreach ($configured as $id) {
            if (is_string($id) && $id !== '') {
                $ids[] = $id;
            }
        }

        return in_array($current, $ids, true);
    }

    public function maxSeconds(): int
    {
        $seconds = VoiceValues::int(data_get(config('ai-chat.voice'), 'max_seconds', 60), 60);

        return $seconds > 0 ? $seconds : 60;
    }

    public function maxKilobytes(): int
    {
        $kilobytes = VoiceValues::int(data_get(config('ai-chat.voice'), 'max_kilobytes', 2048), 2048);

        return $kilobytes > 0 ? $kilobytes : 2048;
    }

    public function consumeRateLimit(Authenticatable $user): void
    {
        $perMinute = VoiceValues::int(data_get(config('ai-chat.voice'), 'rate_limit_per_minute', 10), 10);

        if ($perMinute < 1) {
            $perMinute = 10;
        }

        $identifier = $user->getAuthIdentifier();

        if (! is_scalar($identifier) || (string) $identifier === '') {
            throw new VoiceTranscriptionException('No se pudo transcribir.');
        }

        $key = 'ai-chat:voice:'.$identifier;

        if (RateLimiter::tooManyAttempts($key, $perMinute)) {
            throw new VoiceTranscriptionException('Has alcanzado el límite de transcripciones por minuto.');
        }

        RateLimiter::hit($key, 60);
    }

    /**
     * @return array{
     *     mode: string,
     *     language: string,
     *     max_seconds: int,
     *     auto_submit: bool,
     *     interim: bool,
     *     continuous: bool,
     *     mimes: list<string>
     * }
     */
    public function clientPayload(SpeechToTextDriver $driver): array
    {
        $browser = $driver->runsInBrowser();

        return [
            'mode' => $browser ? 'browser' : 'upload',
            'language' => $browser ? VoiceLanguage::configured() : '',
            'max_seconds' => $this->maxSeconds(),
            'auto_submit' => $this->autoSubmit(),
            'interim' => $browser && VoiceValues::bool(data_get(config('ai-chat.voice'), 'browser.interim_results', true), true),
            'continuous' => $browser && VoiceValues::bool(data_get(config('ai-chat.voice'), 'browser.continuous', false), false),
            'mimes' => $browser ? [] : ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4'],
        ];
    }
}
