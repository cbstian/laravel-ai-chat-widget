<div>
@if ($accessible)
<div
    class="pc-ai-chat"
    style="
        --pc-ai-primary: {{ $colors['primary'] ?? '#4A4D46' }};
        --pc-ai-header: {{ $colors['header'] ?? ($colors['primary'] ?? '#4A4D46') }};
        --pc-ai-header-text: {{ $colors['header_text'] ?? '#ffffff' }};
        --pc-ai-user-bubble: {{ $colors['user_bubble'] ?? ($colors['primary'] ?? '#4A4D46') }};
        --pc-ai-assistant-bubble: {{ $colors['assistant_bubble'] ?? '#ffffff' }};
        --pc-ai-fab: {{ $colors['fab'] ?? ($colors['primary'] ?? '#4A4D46') }};
        --pc-ai-panel-bg: {{ $colors['panel_bg'] ?? '#f9fafb' }};
    "
>
    @if (is_array($voiceUi))
        @include('ai-chat::livewire.voice-script')
    @endif

    @if ($open)
        <div class="pc-ai-chat__panel {{ $expanded ? 'pc-ai-chat__panel--expanded' : 'pc-ai-chat__panel--compact' }}" wire:key="pc-ai-panel-{{ $expanded ? 'exp' : 'cmp' }}">
            <header class="pc-ai-chat__header">
                <div>
                    <h2>{{ $title }}</h2>
                    @if ($subtitle !== '')
                        <p>{{ $subtitle }}</p>
                    @endif
                </div>
                <div class="pc-ai-chat__header-actions">
                    <button type="button" class="pc-ai-chat__icon-btn" wire:click="newConversation" title="Nueva conversación">＋</button>
                    <button type="button" class="pc-ai-chat__icon-btn" wire:click="toggleExpanded" title="{{ $expanded ? 'Compactar' : 'Expandir' }}">
                        @if ($expanded)
                            <svg class="pc-ai-chat__glyph" viewBox="0 0 24 24" aria-hidden="true">
                                <path d="M9 4v5H4M15 4v5h5M9 20v-5H4M15 20v-5h5" />
                            </svg>
                        @else
                            <svg class="pc-ai-chat__glyph" viewBox="0 0 24 24" aria-hidden="true">
                                <path d="M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5" />
                            </svg>
                        @endif
                    </button>
                    <button type="button" class="pc-ai-chat__icon-btn" wire:click="toggle" title="Cerrar">✕</button>
                </div>
            </header>

            @if ($error)
                <div class="pc-ai-chat__error">{{ $error }}</div>
            @endif

            <div
                class="pc-ai-chat__messages"
                x-data
                x-init="
                    const scroll = () => { $el.scrollTop = $el.scrollHeight };
                    scroll();
                    new MutationObserver(scroll).observe($el, { childList: true, subtree: true, characterData: true });
                "
            >
                @forelse ($messages as $message)
                    @if ($message['role'] === 'user')
                        <div class="pc-ai-chat__entry pc-ai-chat__entry--user">
                            @if ($userName !== '')
                                <span class="pc-ai-chat__author">{{ $userName }}</span>
                            @endif
                            <div class="pc-ai-chat__bubble pc-ai-chat__bubble--user">{{ $message['content'] }}</div>
                        </div>
                    @else
                        <div class="pc-ai-chat__bubble pc-ai-chat__bubble--assistant">
                            {!! $this->renderMarkdown((string) $message['content']) !!}
                        </div>
                    @endif
                @empty
                    @if ($pendingQuestion === '')
                        <div class="pc-ai-chat__bubble pc-ai-chat__bubble--assistant">
                            {!! $this->welcomeHtml() !!}
                        </div>
                    @endif
                @endforelse

                @if ($pendingQuestion !== '')
                    <div class="pc-ai-chat__entry pc-ai-chat__entry--user">
                        @if ($userName !== '')
                            <span class="pc-ai-chat__author">{{ $userName }}</span>
                        @endif
                        <div class="pc-ai-chat__bubble pc-ai-chat__bubble--user">{{ $pendingQuestion }}</div>
                    </div>
                    <div class="pc-ai-chat__bubble pc-ai-chat__bubble--assistant">
                        <span class="pc-ai-chat__stream" wire:stream="streamingAnswer">{{ $streamingAnswer !== '' ? $streamingAnswer : '…' }}</span>
                    </div>
                @endif
            </div>

            @if (is_string($voiceNotice) && $voiceNotice !== '')
                <p class="pc-ai-chat__voice-note">{{ $voiceNotice }}</p>
            @endif

            <form
                class="pc-ai-chat__form"
                wire:submit="submit"
                @if (is_array($voiceUi)) x-data="pcAiChatVoice(@js($voiceUi))" @endif
            >
                <textarea
                    class="pc-ai-chat__input"
                    rows="2"
                    wire:model="prompt"
                    x-ref="input"
                    x-on:keydown.enter="if ($event.shiftKey) return; $event.preventDefault(); @if (is_array($voiceUi)) if (status === 'requesting' || status === 'recording' || status === 'transcribing') return; @endif $wire.submit()"
                    placeholder="Escribe un mensaje… (Enter envía)"
                ></textarea>
                @if (is_array($voiceUi))
                    <p class="pc-ai-chat__voice-status" x-show="message !== ''" x-text="message"></p>
                    <button
                        type="button"
                        class="pc-ai-chat__mic"
                        x-cloak
                        x-show="isSupported()"
                        x-on:click="toggle()"
                        x-bind:class="{ 'pc-ai-chat__mic--recording': status === 'recording', 'pc-ai-chat__mic--busy': status === 'transcribing' || status === 'requesting' }"
                        x-bind:disabled="status === 'transcribing'"
                        x-bind:aria-label="label()"
                        x-bind:title="label()"
                        x-bind:aria-pressed="status === 'recording'"
                        x-bind:aria-busy="status === 'transcribing' || status === 'requesting'"
                    >
                        <svg class="pc-ai-chat__glyph pc-ai-chat__mic-icon" viewBox="0 0 24 24" aria-hidden="true">
                            <rect x="9" y="3" width="6" height="11" rx="3" />
                            <path d="M6 11a6 6 0 0 0 12 0M12 17v4M8 21h8" />
                        </svg>
                        <span class="pc-ai-chat__mic-spin" x-show="status === 'transcribing' || status === 'requesting'" aria-hidden="true"></span>
                    </button>
                    <span class="pc-ai-chat__sr" aria-live="polite" x-text="label()"></span>
                @endif
                <button
                    type="submit"
                    class="pc-ai-chat__send"
                    title="Enviar"
                    @if (is_array($voiceUi)) x-bind:disabled="status === 'requesting' || status === 'recording' || status === 'transcribing'" @endif
                >➤</button>
            </form>
        </div>
    @endif

    <button type="button" class="pc-ai-chat__fab" wire:click="toggle" title="{{ $title }}">
        💬
    </button>
</div>
@endif
</div>
