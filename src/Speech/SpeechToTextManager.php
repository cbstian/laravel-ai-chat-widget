<?php

declare(strict_types=1);

namespace Cbstian\AiChat\Speech;

use Cbstian\AiChat\Contracts\SpeechToTextDriver;
use Illuminate\Support\Manager;
use InvalidArgumentException;

class SpeechToTextManager extends Manager
{
    public function getDefaultDriver(): string
    {
        $driver = data_get(config('ai-chat.voice'), 'driver', 'browser');

        if (! is_string($driver) || trim($driver) === '') {
            return 'browser';
        }

        return trim($driver);
    }

    public function driver(mixed $driver = null): SpeechToTextDriver
    {
        $resolved = parent::driver($driver);

        if (! $resolved instanceof SpeechToTextDriver) {
            throw new InvalidArgumentException('El driver de voz no implementa '.SpeechToTextDriver::class.'.');
        }

        return $resolved;
    }

    protected function createBrowserDriver(): SpeechToTextDriver
    {
        return new BrowserSpeechToTextDriver;
    }

    protected function createLaravelAiDriver(): SpeechToTextDriver
    {
        return new LaravelAiSpeechToTextDriver;
    }

    protected function createXaiDriver(): SpeechToTextDriver
    {
        return new XaiSpeechToTextDriver;
    }
}
