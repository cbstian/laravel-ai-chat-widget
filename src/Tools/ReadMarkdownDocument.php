<?php

declare(strict_types=1);

namespace Cbstian\AiChat\Tools;

use Cbstian\AiChat\Documents\MarkdownDocumentLibrary;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class ReadMarkdownDocument implements Tool
{
    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Lee un documento Markdown del proyecto. El argumento path debe ser una ruta exacta devuelta por ListMarkdownDocuments.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        return (new MarkdownDocumentLibrary)->read(trim((string) $request->string('path', '')));
    }

    /**
     * Get the tool's schema definition.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'path' => $schema->string()->required(),
        ];
    }
}
