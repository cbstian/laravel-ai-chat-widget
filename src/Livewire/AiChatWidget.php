<?php

declare(strict_types=1);

namespace Cbstian\AiChat\Livewire;

use Cbstian\AiChat\Contracts\ChatDriver;
use Cbstian\AiChat\Speech\SpeechToTextManager;
use Cbstian\AiChat\Speech\SpeechUpload;
use Cbstian\AiChat\Speech\VoiceSettings;
use Cbstian\AiChat\Speech\VoiceTranscriptionException;
use DOMDocument;
use DOMElement;
use DOMNode;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolCall;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Throwable;

class AiChatWidget extends Component
{
    use WithFileUploads;

    public bool $open = false;

    public bool $expanded = false;

    public string $prompt = '';

    public string $pendingQuestion = '';

    public string $streamingAnswer = '';

    public ?string $error = null;

    #[Locked]
    public ?string $conversationId = null;

    /** @var list<array{role: string, content: string}> */
    public array $messages = [];

    /** @var array<string, mixed> */
    #[Locked]
    public array $context = [];

    /**
     * La página solo puede apagar el dictado. true no enciende la función si la config está en false.
     */
    #[Locked]
    public bool $voice = true;

    public ?TemporaryUploadedFile $audio = null;

    /**
     * @param  array<string, mixed>  $context
     */
    public function mount(array $context = [], mixed $voice = true): void
    {
        $this->context = $context;
        $this->voice = $voice !== false && $voice !== 0 && $voice !== '0' && $voice !== 'false';

        $driver = app(ChatDriver::class);
        $user = Auth::user();

        if (! $user instanceof Authenticatable || ! $driver->canAccess($user)) {
            return;
        }

        $this->loadMessages($user);
    }

    public function toggle(): void
    {
        if (! $this->canAccess()) {
            return;
        }

        $this->open = ! $this->open;
        $this->error = null;

        if ($this->open && $this->messages === [] && Auth::user() instanceof Authenticatable) {
            $this->loadMessages(Auth::user());
        }

        if (! $this->open) {
            $this->expanded = false;
        }
    }

    public function toggleExpanded(): void
    {
        $this->expanded = ! $this->expanded;
    }

    public function submit(): void
    {
        if (! $this->canAccess()) {
            return;
        }

        $this->validate([
            'prompt' => ['required', 'string', 'max:'.(int) config('ai-chat.max_prompt_length', 8000)],
        ]);

        $this->pendingQuestion = trim($this->prompt);
        $this->prompt = '';
        $this->streamingAnswer = '';
        $this->error = null;
        $this->open = true;

        $this->js('$wire.ask()');
    }

    public function ask(): void
    {
        if (! $this->canAccess() || $this->pendingQuestion === '') {
            return;
        }

        $driver = app(ChatDriver::class);
        $user = Auth::user();
        $startedText = false;

        try {
            $result = $driver->ask(
                $user,
                $this->pendingQuestion,
                $this->conversationId,
                $this->context,
                function (object $event) use (&$startedText): void {
                    if ($event instanceof ToolCall) {
                        $label = $this->toolLabel($event->toolCall->name);
                        $this->stream(to: 'streamingAnswer', content: e($label), replace: true);
                        $startedText = false;
                    }

                    if ($event instanceof StreamStart && $startedText) {
                        $this->stream(to: 'streamingAnswer', content: e("\n\n"), replace: false);
                    }

                    if ($event instanceof TextDelta) {
                        $this->stream(
                            to: 'streamingAnswer',
                            content: e($event->delta),
                            replace: ! $startedText,
                        );
                        $startedText = true;
                    }
                },
            );

            $this->conversationId = $result->conversationId;
            $this->messages = $driver->messages($user, $this->conversationId);
            $this->pendingQuestion = '';
            $this->streamingAnswer = '';
        } catch (Throwable $exception) {
            report($exception);
            $this->error = $exception->getMessage() !== ''
                ? $exception->getMessage()
                : 'No se pudo completar la respuesta.';
        }
    }

    public function transcribe(): void
    {
        try {
            $this->fillPromptFromAudio();
        } finally {
            $this->discardAudio();
        }
    }

    public function newConversation(): void
    {
        if (! $this->canAccess()) {
            return;
        }

        app(ChatDriver::class)->startNew(Auth::user(), $this->context);
        $this->conversationId = null;
        $this->messages = [];
        $this->pendingQuestion = '';
        $this->streamingAnswer = '';
        $this->error = null;
        $this->prompt = '';
    }

    protected function loadMessages(Authenticatable $user): void
    {
        $driver = app(ChatDriver::class);

        if (blank($this->conversationId)) {
            $this->conversationId = $driver->activeConversationId($user);
        }

        $this->messages = $driver->messages($user, $this->conversationId);
    }

    public function welcomeHtml(): string
    {
        $path = config('ai-chat.welcome_markdown');

        $markdown = (is_string($path) && File::exists($path))
            ? File::get($path)
            : (string) config('ai-chat.welcome_markdown_inline', '');

        return $this->renderMarkdown($markdown);
    }

    public function renderMarkdown(string $markdown): string
    {
        $html = (string) str($markdown)->markdown([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
        ]);

        return $this->decorateMarkdown($html);
    }

    private function decorateMarkdown(string $html): string
    {
        $document = new DOMDocument;
        $internalErrors = libxml_use_internal_errors(true);

        $loaded = $document->loadHTML(
            '<?xml encoding="UTF-8"><div id="pc-ai-chat-md">'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );

        libxml_clear_errors();
        libxml_use_internal_errors($internalErrors);

        $root = $loaded ? $document->getElementById('pc-ai-chat-md') : null;

        if (! $root instanceof DOMElement) {
            return '<div class="pc-ai-chat__md">'.$html.'</div>';
        }

        $this->openExternalMarkdownLinks($root);
        $this->wrapMarkdownTables($document, $root);

        $root->setAttribute('class', 'pc-ai-chat__md');
        $root->removeAttribute('id');

        $rendered = $document->saveHTML($root);

        return is_string($rendered) ? $rendered : '<div class="pc-ai-chat__md">'.$html.'</div>';
    }

    private function openExternalMarkdownLinks(DOMElement $root): void
    {
        foreach ($root->getElementsByTagName('a') as $anchor) {
            if (preg_match('/\Ahttps?:\/\//i', $anchor->getAttribute('href')) !== 1) {
                continue;
            }

            $anchor->setAttribute('target', '_blank');
            $anchor->setAttribute('rel', 'noopener noreferrer');
        }
    }

    private function wrapMarkdownTables(DOMDocument $document, DOMElement $root): void
    {
        $tables = [];

        foreach ($root->getElementsByTagName('table') as $table) {
            $tables[] = $table;
        }

        foreach ($tables as $table) {
            $parent = $table->parentNode;

            if (! $parent instanceof DOMNode) {
                continue;
            }

            if ($parent instanceof DOMElement && str_contains($parent->getAttribute('class'), 'pc-ai-chat__table')) {
                continue;
            }

            $wrapper = $document->createElement('div');
            $wrapper->setAttribute('class', 'pc-ai-chat__table');
            $parent->insertBefore($wrapper, $table);
            $wrapper->appendChild($table);
        }
    }

    public function render(): View
    {
        $accessible = $this->canAccess();
        $voice = $this->voicePresentation($accessible);

        return view('ai-chat::livewire.widget', [
            'title' => (string) config('ai-chat.title'),
            'subtitle' => (string) config('ai-chat.subtitle'),
            'colors' => (array) config('ai-chat.colors', []),
            'userName' => $this->userDisplayName(),
            'accessible' => $accessible,
            'voiceUi' => $voice['payload'],
            'voiceNotice' => $voice['notice'],
        ]);
    }

    /**
     * @return array{payload: array<string, mixed>|null, notice: string|null}
     */
    private function voicePresentation(bool $accessible): array
    {
        if (! $accessible || ! $this->dictationAllowed()) {
            return ['payload' => null, 'notice' => null];
        }

        try {
            $driver = app(SpeechToTextManager::class)->driver();
        } catch (InvalidArgumentException) {
            return [
                'payload' => null,
                'notice' => 'El dictado por voz no está disponible con el driver configurado.',
            ];
        }

        return [
            'payload' => app(VoiceSettings::class)->clientPayload($driver),
            'notice' => null,
        ];
    }

    private function dictationAllowed(): bool
    {
        if (! $this->voice) {
            return false;
        }

        $settings = app(VoiceSettings::class);

        return $settings->enabled() && $settings->allowsCurrentPanel();
    }

    private function fillPromptFromAudio(): void
    {
        $user = Auth::user();

        if (! $user instanceof Authenticatable || ! $this->canAccess() || ! $this->dictationAllowed()) {
            return;
        }

        try {
            $driver = app(SpeechToTextManager::class)->driver();
        } catch (InvalidArgumentException) {
            $this->error = 'El dictado por voz no está disponible con el driver configurado.';

            return;
        }

        if ($driver->runsInBrowser() || ! $this->audio instanceof TemporaryUploadedFile) {
            return;
        }

        try {
            $settings = app(VoiceSettings::class);
            $speech = app(SpeechUpload::class)->prepare($this->audio, $settings->maxKilobytes());
            $settings->consumeRateLimit($user);
            $transcript = $driver->transcribe($speech);
        } catch (VoiceTranscriptionException $exception) {
            $this->error = $exception->getMessage();

            return;
        } catch (Throwable $exception) {
            report($exception);
            $this->error = 'No se pudo transcribir.';

            return;
        }

        $text = trim($transcript->text);

        if ($text === '') {
            $this->error = 'No se reconoció ninguna palabra.';

            return;
        }

        $current = trim($this->prompt);
        $this->prompt = $current === '' ? $text : $current.' '.$text;
        $this->error = null;

        if (app(VoiceSettings::class)->autoSubmit()) {
            $this->submit();
        }
    }

    private function discardAudio(): void
    {
        $audio = $this->audio;

        if ($audio instanceof TemporaryUploadedFile) {
            try {
                $audio->delete();
            } catch (Throwable) {
                // El temporal ya no está.
            }
        }

        $this->audio = null;
    }

    protected function canAccess(): bool
    {
        return app(ChatDriver::class)->canAccess(Auth::user());
    }

    protected function userDisplayName(): string
    {
        $name = data_get(Auth::user(), 'name');

        return is_string($name) ? trim($name) : '';
    }

    protected function toolLabel(string $name): string
    {
        $labels = (array) config('ai-chat.tool_labels', []);

        return $labels[$name] ?? (string) config('ai-chat.default_tool_label', 'Consultando…');
    }
}
