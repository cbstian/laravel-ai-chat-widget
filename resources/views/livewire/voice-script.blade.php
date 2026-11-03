<script>
    if (typeof window.pcAiChatVoice !== 'function') {
        window.pcAiChatVoice = function (settings) {
            return {
                settings: settings,
                status: 'idle',
                message: '',
                recognition: null,
                recorder: null,
                stream: null,
                chunks: [],
                stopTimer: null,
                prefix: '',
                init() {
                    try {
                        window.localStorage.removeItem('pc-ai-chat-voice');
                    } catch (error) {}
                },
                isSupported() {
                    if (settings.mode === 'browser') {
                        return !!(window.SpeechRecognition || window.webkitSpeechRecognition);
                    }

                    return typeof window.MediaRecorder === 'function'
                        && !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia);
                },
                label() {
                    if (this.status === 'requesting') {
                        return 'Permiso…';
                    }

                    if (this.status === 'recording') {
                        return settings.mode === 'browser' ? 'Escuchando…' : 'Grabando…';
                    }

                    if (this.status === 'transcribing') {
                        return 'Transcribiendo…';
                    }

                    return 'Dictar';
                },
                toggle() {
                    if (this.status === 'transcribing') {
                        return;
                    }

                    if (this.status === 'recording' || this.status === 'requesting') {
                        this.stop();

                        return;
                    }

                    this.message = '';

                    if (settings.mode === 'browser') {
                        this.listen();

                        return;
                    }

                    this.record();
                },
                listen() {
                    const Ctor = window.SpeechRecognition || window.webkitSpeechRecognition;

                    if (!Ctor) {
                        this.fail('Este navegador no puede dictar.');

                        return;
                    }

                    const recognition = new Ctor();
                    recognition.lang = settings.language || document.documentElement.lang || '';
                    recognition.interimResults = !!settings.interim;
                    recognition.continuous = !!settings.continuous;
                    this.prefix = this.currentText();
                    this.status = 'requesting';

                    recognition.onstart = () => {
                        this.status = 'recording';
                    };

                    recognition.onresult = (event) => {
                        let spoken = '';

                        for (let index = 0; index < event.results.length; index++) {
                            spoken += event.results[index][0].transcript;
                        }

                        this.write(this.join(this.prefix, spoken));
                    };

                    recognition.onerror = (event) => {
                        if (event.error === 'not-allowed' || event.error === 'service-not-allowed') {
                            this.fail('El navegador bloqueó el micrófono.');

                            return;
                        }

                        if (event.error === 'aborted' || event.error === 'no-speech') {
                            this.status = 'idle';

                            return;
                        }

                        this.fail('No se pudo dictar.');
                    };

                    recognition.onend = () => {
                        const failed = this.status === 'error';
                        const text = this.currentText();
                        this.recognition = null;
                        this.clearTimer();

                        if (failed) {
                            return;
                        }

                        this.commit(text, !!settings.auto_submit && text.trim() !== '' && text.trim() !== this.prefix.trim());
                    };

                    this.recognition = recognition;
                    this.armTimer();

                    try {
                        recognition.start();
                    } catch (error) {
                        this.fail('No se pudo dictar.');
                    }
                },
                async record() {
                    if (!this.isSupported()) {
                        this.fail('Este navegador no puede grabar audio.');

                        return;
                    }

                    this.status = 'requesting';
                    let stream;

                    try {
                        stream = await navigator.mediaDevices.getUserMedia({ audio: true });
                    } catch (error) {
                        this.fail('El navegador bloqueó el micrófono.');

                        return;
                    }

                    const mime = (settings.mimes || []).find((type) => {
                        try {
                            return window.MediaRecorder.isTypeSupported(type);
                        } catch (error) {
                            return false;
                        }
                    }) || '';

                    let recorder;

                    try {
                        recorder = mime === '' ? new window.MediaRecorder(stream) : new window.MediaRecorder(stream, { mimeType: mime });
                    } catch (error) {
                        stream.getTracks().forEach((track) => track.stop());
                        this.fail('No se pudo grabar.');

                        return;
                    }

                    this.stream = stream;
                    this.recorder = recorder;
                    this.chunks = [];

                    recorder.ondataavailable = (event) => {
                        if (event.data && event.data.size > 0) {
                            this.chunks.push(event.data);
                        }
                    };

                    recorder.onstop = () => {
                        this.clearTimer();
                        stream.getTracks().forEach((track) => track.stop());
                        const type = recorder.mimeType || mime || 'audio/webm';
                        const blob = new Blob(this.chunks, { type: type });
                        this.chunks = [];
                        this.recorder = null;
                        this.stream = null;

                        if (!blob.size) {
                            this.status = 'idle';

                            return;
                        }

                        this.upload(blob, type);
                    };

                    recorder.start();
                    this.status = 'recording';
                    this.armTimer();
                },
                upload(blob, mime) {
                    const base = String(mime).split(';')[0] || 'audio/webm';
                    const extension = base.indexOf('mp4') !== -1 || base.indexOf('m4a') !== -1 ? 'mp4' : 'webm';
                    const file = new File([blob], 'dictado.' + extension, { type: base });
                    this.status = 'transcribing';

                    this.$wire.upload('audio', file, () => {
                        Promise.resolve(this.$wire.transcribe()).then(() => {
                            this.status = 'idle';
                            this.focusInput();
                        }).catch(() => {
                            this.fail('No se pudo transcribir.');
                        });
                    }, () => {
                        this.fail('No se pudo subir el audio.');
                    });
                },
                stop() {
                    if (this.recognition) {
                        try {
                            this.recognition.stop();
                        } catch (error) {
                            this.recognition = null;
                            this.status = 'idle';
                        }
                    }

                    if (this.recorder && this.recorder.state !== 'inactive') {
                        this.recorder.stop();
                    }
                },
                armTimer() {
                    this.clearTimer();
                    const seconds = Number(settings.max_seconds) || 60;
                    this.stopTimer = window.setTimeout(() => this.stop(), seconds * 1000);
                },
                clearTimer() {
                    if (this.stopTimer) {
                        window.clearTimeout(this.stopTimer);
                        this.stopTimer = null;
                    }
                },
                currentText() {
                    return this.$refs.input ? this.$refs.input.value : (this.$wire.prompt || '');
                },
                write(text) {
                    if (this.$refs.input) {
                        this.$refs.input.value = text;
                    }
                },
                commit(text, andSubmit) {
                    this.write(text);
                    const finish = () => {
                        this.status = 'idle';
                        this.focusInput();

                        if (andSubmit) {
                            this.$wire.submit();
                        }
                    };
                    const pending = this.$wire.set('prompt', text);

                    if (pending && typeof pending.then === 'function') {
                        pending.then(finish).catch(() => this.fail('No se pudo dictar.'));

                        return;
                    }

                    finish();
                },
                join(prefix, spoken) {
                    const base = String(prefix || '').trim();
                    const next = String(spoken || '').trim();

                    if (base === '') {
                        return next;
                    }

                    if (next === '') {
                        return base;
                    }

                    return base + ' ' + next;
                },
                focusInput() {
                    this.$nextTick(() => {
                        if (this.$refs.input) {
                            this.$refs.input.focus();
                        }
                    });
                },
                fail(text) {
                    this.status = 'error';
                    this.message = text;
                    this.clearTimer();

                    if (this.stream) {
                        this.stream.getTracks().forEach((track) => track.stop());
                        this.stream = null;
                    }
                },
            };
        };
    }
</script>
