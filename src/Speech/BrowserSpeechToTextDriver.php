<?php

declare(strict_types=1);

namespace Cbstian\AiChat\Speech;

use Cbstian\AiChat\Contracts\SpeechToTextDriver;
use Cbstian\AiChat\Dto\SpeechAudio;
use Cbstian\AiChat\Dto\SpeechTranscript;

final class BrowserSpeechToTextDriver implements SpeechToTextDriver
{
    public function runsInBrowser(): bool
    {
        return true;
    }

    public function transcribe(SpeechAudio $audio): SpeechTranscript
    {
        throw new VoiceTranscriptionException('El driver browser transcribe en el navegador.');
    }
}
