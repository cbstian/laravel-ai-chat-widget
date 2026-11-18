{{--
    El dictado vive en resources/js/voice.js y se carga como <script src> externo.
    @assets lo deja en el <head> una sola vez por página (también con wire:navigate),
    antes de que Alpine evalúe x-data="pcAiChatVoice(...)". Sin JS inline: una CSP
    sin 'unsafe-inline' no lo bloquea y no hace falta nonce ni publicar vistas.
--}}
@assets
{!! \Cbstian\AiChat\Support\Assets::voiceScriptTag() !!}
@endassets
