<?php

declare(strict_types=1);

namespace Cbstian\AiChat\Speech;

use Cbstian\AiChat\Dto\SpeechAudio;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

final class SpeechUpload
{
    /** @var list<string> */
    private const array MIME_TYPES = [
        'audio/webm',
        'video/webm',
        'audio/ogg',
        'audio/opus',
        'audio/mp4',
        'audio/mpeg',
        'audio/mp3',
        'audio/wav',
        'audio/wave',
        'audio/x-wav',
        'audio/x-m4a',
        'audio/m4a',
        'audio/aac',
        'audio/flac',
    ];

    public function prepare(TemporaryUploadedFile $file, int $maxKilobytes): SpeechAudio
    {
        $mime = $this->acceptedMime($file);

        if ($mime === null) {
            throw new VoiceTranscriptionException('El audio no se puede transcribir.');
        }

        $bytes = $file->getSize();
        $maxBytes = max(1, $maxKilobytes) * 1024;

        if ($bytes < 1 || $bytes > $maxBytes) {
            throw new VoiceTranscriptionException(
                $bytes > $maxBytes
                    ? 'El audio supera el tamaño permitido.'
                    : 'El audio no se puede transcribir.',
            );
        }

        $path = $file->getRealPath();

        if ($path === '' || ! is_file($path)) {
            throw new VoiceTranscriptionException('No se pudo transcribir.');
        }

        return new SpeechAudio(
            path: $path,
            mimeType: $mime === 'video/webm' ? 'audio/webm' : $mime,
            filename: $this->filename($mime),
            bytes: $bytes,
        );
    }

    private function acceptedMime(TemporaryUploadedFile $file): ?string
    {
        $detected = $this->normalize((string) $file->getMimeType());
        $meta = $file->metaFileData();
        $declared = $this->normalize(is_array($meta) && is_string($meta['type'] ?? null) ? $meta['type'] : '');

        if ($this->rejected($detected) || $this->rejected($declared)) {
            return null;
        }

        if ($this->allowed($declared)) {
            return $declared;
        }

        if ($this->allowed($detected)) {
            return $detected;
        }

        if ($this->opaque($detected) && $this->opaque($declared)) {
            return $this->mimeFromName($file->getClientOriginalName());
        }

        return null;
    }

    private function rejected(string $mime): bool
    {
        return $mime !== '' && ! $this->opaque($mime) && ! $this->allowed($mime);
    }

    private function opaque(string $mime): bool
    {
        return $mime === '' || $mime === 'application/octet-stream' || $mime === 'application/x-empty';
    }

    private function allowed(string $mime): bool
    {
        return in_array($mime, self::MIME_TYPES, true);
    }

    private function normalize(string $mime): string
    {
        $base = strtolower(trim(explode(';', $mime, 2)[0]));

        return $base;
    }

    private function mimeFromName(string $filename): ?string
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return match ($extension) {
            'webm' => 'audio/webm',
            'mp4', 'm4a' => 'audio/mp4',
            'mp3' => 'audio/mpeg',
            'wav' => 'audio/wav',
            'ogg', 'opus' => 'audio/ogg',
            'flac' => 'audio/flac',
            'aac' => 'audio/aac',
            default => null,
        };
    }

    private function filename(string $mime): string
    {
        return match ($mime) {
            'audio/mp4', 'audio/x-m4a', 'audio/m4a', 'audio/aac' => 'audio.mp4',
            'audio/mpeg', 'audio/mp3' => 'audio.mp3',
            'audio/wav', 'audio/wave', 'audio/x-wav' => 'audio.wav',
            'audio/ogg', 'audio/opus' => 'audio.ogg',
            'audio/flac' => 'audio.flac',
            default => 'audio.webm',
        };
    }
}
