<?php

declare(strict_types=1);

namespace Cbstian\AiChat\Support;

use Illuminate\Foundation\Vite;
use Illuminate\Support\Facades\Vite as ViteFacade;

/**
 * CSS y JS del widget. El JS del dictado se sirve como archivo externo para que
 * una Content-Security-Policy sin 'unsafe-inline' no lo bloquee.
 */
final class Assets
{
    public static function stylesPath(): string
    {
        return dirname(__DIR__, 2).'/resources/css/ai-chat.css';
    }

    public static function voiceScriptPath(): string
    {
        return dirname(__DIR__, 2).'/resources/js/voice.js';
    }

    /**
     * Cambia cuando cambia el archivo, así el navegador no usa una copia vieja.
     */
    public static function voiceScriptVersion(): string
    {
        $path = self::voiceScriptPath();

        return is_file($path) ? substr((string) md5_file($path), 0, 12) : '0';
    }

    public static function voiceScriptUrl(): string
    {
        return route('ai-chat.voice-script', ['id' => self::voiceScriptVersion()]);
    }

    /**
     * Nonce de la CSP de la app, si usa Vite::useCspNonce(). Livewire lee el mismo.
     */
    public static function nonce(): ?string
    {
        if (! class_exists(Vite::class)) {
            return null;
        }

        $nonce = ViteFacade::cspNonce();

        return is_string($nonce) && $nonce !== '' ? $nonce : null;
    }

    public static function voiceScriptTag(): string
    {
        return '<script src="'.e(self::voiceScriptUrl()).'"'.self::nonceAttribute().'></script>';
    }

    public static function styles(): string
    {
        $path = self::stylesPath();
        $css = is_file($path) ? (string) file_get_contents($path) : '';

        return '<style'.self::nonceAttribute().'>'.$css.'</style>';
    }

    private static function nonceAttribute(): string
    {
        $nonce = self::nonce();

        return $nonce === null ? '' : ' nonce="'.e($nonce).'"';
    }
}
