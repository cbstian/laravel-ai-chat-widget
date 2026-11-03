<?php

declare(strict_types=1);

namespace Cbstian\AiChat\Contracts;

use Cbstian\AiChat\Dto\SpeechAudio;
use Cbstian\AiChat\Dto\SpeechTranscript;

interface SpeechToTextDriver
{
    /**
     * true: transcribe el navegador. false: el widget sube audio y llama a transcribe().
     */
    public function runsInBrowser(): bool;

    public function transcribe(SpeechAudio $audio): SpeechTranscript;
}
