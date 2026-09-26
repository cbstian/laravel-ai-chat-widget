<?php

declare(strict_types=1);

namespace Cbstian\AiChat\Livewire;

use Cbstian\AiChat\Contracts\ChatDriver;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolCall;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

class AiChatWidget extends Component
{
    public bool $open = false;

    public bool $expanded = false;

    public string $prompt = '';

    public string $pendingQuestion = '';

    public string $streamingAnswer = '';

    public ?string $error = null;

    public ?string $conversationId = null;

    /** @var list<array{role: string, content: string}> */
    public array $messages = [];

    /** @var array<string, mixed> */
    #[Locked]
    public array $context = [];

    /**
     * @param  array<string, mixed>  $context
     */
    public function mount(array $context = []): void
    {
        $this->context = $context;

        $driver = app(ChatDriver::class);
        $user = Auth::user();

        if (! $driver->canAccess($user)) {
            return;
        }

        $this->messages = $driver->messages($user, $this->conversationId);
    }

    public function toggle(): void
    {
        if (! $this->canAccess()) {
            return;
        }

        $this->open = ! $this->open;
        $this->error = null;

        if ($this->open && $this->messages === []) {
            $this->messages = app(ChatDriver::class)->messages(Auth::user(), $this->conversationId);
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

    public function welcomeHtml(): string
    {
        $path = config('ai-chat.welcome_markdown');

        $markdown = (is_string($path) && File::exists($path))
            ? File::get($path)
            : (string) config('ai-chat.welcome_markdown_inline', '');

        return (string) str($markdown)->markdown();
    }

    public function render(): View
    {
        return view('ai-chat::livewire.widget', [
            'title' => (string) config('ai-chat.title'),
            'subtitle' => (string) config('ai-chat.subtitle'),
            'colors' => (array) config('ai-chat.colors', []),
            'accessible' => $this->canAccess(),
        ]);
    }

    protected function canAccess(): bool
    {
        return app(ChatDriver::class)->canAccess(Auth::user());
    }

    protected function toolLabel(string $name): string
    {
        $labels = (array) config('ai-chat.tool_labels', []);

        return $labels[$name] ?? (string) config('ai-chat.default_tool_label', 'Consultando…');
    }
}
