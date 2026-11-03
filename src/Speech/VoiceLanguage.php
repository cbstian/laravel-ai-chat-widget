<?php

declare(strict_types=1);

namespace Cbstian\AiChat\Speech;

final class VoiceLanguage
{
    public static function configured(): string
    {
        $language = data_get(config('ai-chat.voice'), 'language', 'es-CL');

        if (! is_string($language)) {
            return 'es-CL';
        }

        return trim($language);
    }

    public static function forLaravelAi(): ?string
    {
        $language = self::normalized();

        if ($language === null) {
            return null;
        }

        $primary = explode('-', $language)[0];

        return $primary !== '' ? $primary : null;
    }

    public static function forXai(): ?string
    {
        $language = self::normalized();

        if ($language === null) {
            return null;
        }

        if ($language === 'es-es') {
            return 'es-ES';
        }

        if ($language === 'es' || str_starts_with($language, 'es-')) {
            return 'es';
        }

        $primary = explode('-', $language)[0];

        return $primary !== '' ? $primary : null;
    }

    private static function normalized(): ?string
    {
        $language = strtolower(str_replace('_', '-', self::configured()));

        return $language === '' ? null : $language;
    }
}
