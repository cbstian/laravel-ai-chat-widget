<?php

declare(strict_types=1);

namespace Cbstian\AiChat\Http\Controllers;

use Cbstian\AiChat\Support\Assets;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Sirve resources/js/voice.js. La URL lleva ?id=<hash>; con ese hash el archivo
 * se cachea un año. Sin hash, o con uno viejo, se cachea poco.
 */
class VoiceScriptController
{
    public function __invoke(Request $request): BinaryFileResponse
    {
        $path = Assets::voiceScriptPath();

        abort_unless(is_file($path), 404);

        $versioned = $request->query('id') === Assets::voiceScriptVersion();

        $response = new BinaryFileResponse($path, 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
        ], true, null, true, true);

        if ($versioned) {
            $response->setPublic();
            $response->setMaxAge(31536000);
            $response->setImmutable();
        } else {
            $response->setPublic();
            $response->setMaxAge(300);
        }

        $response->isNotModified($request);

        return $response;
    }
}
