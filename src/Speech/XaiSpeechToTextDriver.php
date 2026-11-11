<?php

declare(strict_types=1);

namespace Cbstian\AiChat\Speech;

use Cbstian\AiChat\Contracts\SpeechToTextDriver;
use Cbstian\AiChat\Dto\SpeechAudio;
use Cbstian\AiChat\Dto\SpeechTranscript;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

final class XaiSpeechToTextDriver implements SpeechToTextDriver
{
    public function runsInBrowser(): bool
    {
        return false;
    }

    public function transcribe(SpeechAudio $audio): SpeechTranscript
    {
        $key = $this->apiKey();

        if ($key === '') {
            throw new VoiceTranscriptionException('No se pudo transcribir. Revisa la configuración del driver de voz.');
        }

        $contents = file_get_contents($audio->path);

        if ($contents === false) {
            throw new VoiceTranscriptionException('No se pudo transcribir.');
        }

        try {
            $response = Http::withToken($key)
                ->timeout($this->timeout())
                ->acceptJson()
                ->attach($this->parts($audio, $contents))
                ->post($this->endpoint());
        } catch (ConnectionException) {
            throw new VoiceTranscriptionException('No se pudo transcribir.');
        } catch (Throwable) {
            throw new VoiceTranscriptionException('No se pudo transcribir.');
        }

        if ($response->status() === 429) {
            throw new VoiceTranscriptionException('Demasiadas transcripciones.');
        }

        if ($response->failed()) {
            throw new VoiceTranscriptionException('No se pudo transcribir.');
        }

        $payload = $response->json();
        $text = is_array($payload) ? ($payload['text'] ?? null) : null;

        if (! is_string($text)) {
            throw new VoiceTranscriptionException('No se pudo transcribir.');
        }

        $language = is_string($payload['language'] ?? null) ? $payload['language'] : null;
        $duration = is_numeric($payload['duration'] ?? null) ? (float) $payload['duration'] : null;

        return new SpeechTranscript($text, $language, $duration);
    }

    /**
     * @return list<array{name: string, contents: string, filename?: string, headers?: array<string, string>}>
     */
    private function parts(SpeechAudio $audio, string $contents): array
    {
        $parts = [
            ['name' => 'model', 'contents' => $this->model()],
        ];

        $language = VoiceLanguage::forXai();

        if ($language !== null) {
            $parts[] = ['name' => 'language', 'contents' => $language];
        }

        $format = VoiceValues::bool(data_get(config('ai-chat.voice'), 'xai.format', true), true);

        if ($format && $language !== null) {
            $parts[] = ['name' => 'format', 'contents' => 'true'];
        }

        $filler = VoiceValues::bool(data_get(config('ai-chat.voice'), 'xai.filler_words', false), false);
        $parts[] = ['name' => 'filler_words', 'contents' => $filler ? 'true' : 'false'];

        foreach ($this->keyterms() as $term) {
            $parts[] = ['name' => 'keyterm', 'contents' => $term];
        }

        $parts[] = [
            'name' => 'file',
            'contents' => $contents,
            'filename' => $audio->filename,
            'headers' => ['Content-Type' => $audio->mimeType],
        ];

        return $parts;
    }

    private function apiKey(): string
    {
        $key = data_get(config('ai-chat.voice'), 'xai.key');

        if (is_string($key) && trim($key) !== '') {
            return trim($key);
        }

        $fallback = config('ai.providers.xai.key');

        if (is_string($fallback) && trim($fallback) !== '') {
            return trim($fallback);
        }

        return '';
    }

    private function endpoint(): string
    {
        $url = data_get(config('ai-chat.voice'), 'xai.url', 'https://api.x.ai/v1/stt');

        if (! is_string($url) || preg_match('#\Ahttps://#', trim($url)) !== 1) {
            return 'https://api.x.ai/v1/stt';
        }

        return trim($url);
    }

    private function model(): string
    {
        return VoiceValues::string(
            data_get(config('ai-chat.voice'), 'xai.model'),
            'grok-voice-transcribe-2.0',
        );
    }

    private function timeout(): int
    {
        $timeout = VoiceValues::int(data_get(config('ai-chat.voice'), 'xai.timeout', 30), 30);

        return $timeout > 0 ? $timeout : 30;
    }

    /**
     * @return list<string>
     */
    private function keyterms(): array
    {
        $terms = data_get(config('ai-chat.voice'), 'xai.keyterms', []);

        if (! is_array($terms)) {
            return [];
        }

        $clean = [];

        foreach ($terms as $term) {
            if (! is_string($term)) {
                continue;
            }

            $term = trim($term);

            if ($term === '') {
                continue;
            }

            $clean[] = mb_substr($term, 0, 50);

            if (count($clean) === 100) {
                break;
            }
        }

        return $clean;
    }
}
