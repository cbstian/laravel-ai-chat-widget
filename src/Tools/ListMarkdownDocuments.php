<?php

declare(strict_types=1);

namespace Cbstian\AiChat\Tools;

use Cbstian\AiChat\Documents\MarkdownDocumentLibrary;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class ListMarkdownDocuments implements Tool
{
    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Lista los documentos Markdown que este chat puede leer (welcome, guías y rutas de ai-chat.documents.paths). Úsala antes de afirmar el contenido de la documentación.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        return (new MarkdownDocumentLibrary)->list();
    }

    /**
     * Get the tool's schema definition.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
