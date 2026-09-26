<?php

declare(strict_types=1);

use Cbstian\AiChat\Documents\MarkdownDocumentLibrary;
use Cbstian\AiChat\Tools\ListMarkdownDocuments;
use Cbstian\AiChat\Tools\ReadMarkdownDocument;
use Illuminate\Support\Facades\File;
use Laravel\Ai\Tools\Request;

beforeEach(function () {
    $this->root = storage_path('framework/ai-chat-doc-library');
    $this->outside = storage_path('framework/ai-chat-secret.md');

    File::deleteDirectory($this->root);
    File::delete($this->outside);
    File::ensureDirectoryExists($this->root);
    File::put($this->root.'/guide.md', "# Guia\n\nTexto de prueba.");
    File::put($this->outside, 'SECRETO');

    config([
        'ai-chat.documents.paths' => [$this->root],
        'ai-chat.documents.max_bytes' => 200_000,
        'ai-chat.documents.max_files' => 200,
    ]);
});

afterEach(function () {
    File::deleteDirectory($this->root);
    File::delete($this->outside);
});

it('lists and reads markdown inside the configured directory', function () {
    $library = new MarkdownDocumentLibrary;
    $id = 'ai-chat-doc-library/guide.md';

    expect($library->list())->toContain($id);

    $read = (new ReadMarkdownDocument)->handle(new Request(['path' => $id]));

    expect($read)->toContain('# Guia')
        ->and($read)->toContain('Texto de prueba.');

    expect((new ListMarkdownDocuments)->handle(new Request))->toContain($id);
});

it('resolves a document path relative to the application base path', function () {
    config([
        'ai-chat.documents.paths' => ['storage/framework/ai-chat-doc-library'],
    ]);

    expect((new MarkdownDocumentLibrary)->read('ai-chat-doc-library/guide.md'))
        ->toContain('Texto de prueba.');
});

it('rejects traversal, non-markdown files, missing documents, and files outside the root', function () {
    File::put($this->root.'/notes.txt', 'no');

    $library = new MarkdownDocumentLibrary;

    expect($library->read('ai-chat-doc-library/../ai-chat-secret.md'))
        ->toContain('Ruta no permitida')
        ->and($library->read('ai-chat-doc-library/notes.txt'))->toContain('Solo se pueden leer')
        ->and($library->read('ai-chat-doc-library/missing.md'))->toContain('no encontrado')
        ->and($library->list())->not->toContain('SECRETO');

    $link = $this->root.'/secret.md';

    if (@symlink($this->outside, $link)) {
        expect($library->read('ai-chat-doc-library/secret.md'))->not->toContain('SECRETO')
            ->and($library->list())->not->toContain('secret.md');
    }
});

it('refuses documents larger than the configured maximum', function () {
    config(['ai-chat.documents.max_bytes' => 8]);

    expect((new MarkdownDocumentLibrary)->read('ai-chat-doc-library/guide.md'))
        ->toContain('supera el máximo');
});

it('reports an empty library when the configured directory does not exist', function () {
    config(['ai-chat.documents.paths' => [storage_path('framework/ai-chat-missing-docs')]]);

    expect((new MarkdownDocumentLibrary)->list())->toContain('No hay documentos Markdown');
});
