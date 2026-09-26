<?php

declare(strict_types=1);

namespace Cbstian\AiChat\Documents;

use Illuminate\Support\Facades\File;
use SplFileInfo;
use Symfony\Component\Finder\Finder;

class MarkdownDocumentLibrary
{
    public function list(): string
    {
        $entries = $this->entries();

        if ($entries === []) {
            return 'No hay documentos Markdown en ai-chat.documents.paths. Publica resources/ai/chat/welcome.md con ai-chat:install o define AI_CHAT_DOCUMENT_PATHS.';
        }

        $lines = array_map(
            static fn (array $entry): string => '- '.$entry['id'],
            $entries,
        );

        $body = "Documentos Markdown (pasa la ruta exacta a ReadMarkdownDocument):\n".implode("\n", $lines);
        $maxFiles = $this->maxFiles();

        if (count($entries) >= $maxFiles) {
            $body .= "\n(listado truncado a {$maxFiles} archivos; sube ai-chat.documents.max_files si hace falta)";
        }

        return $body;
    }

    public function read(string $path): string
    {
        $normalized = $this->normalize($path);

        if ($normalized === null) {
            return 'Ruta no permitida. Usa una ruta relativa de ListMarkdownDocuments, sin .. ni rutas absolutas.';
        }

        $extension = strtolower(pathinfo($normalized, PATHINFO_EXTENSION));

        if (! in_array($extension, ['md', 'markdown'], true)) {
            return 'Solo se pueden leer archivos Markdown (.md, .markdown).';
        }

        $match = $this->find($normalized);

        if ($match === null) {
            return 'Documento no encontrado. Llama a ListMarkdownDocuments y usa una ruta de esa lista.';
        }

        $maxBytes = max(1, (int) config('ai-chat.documents.max_bytes', 200_000));
        $size = $match['file']->getSize();

        if ($size > $maxBytes) {
            return "El documento supera el máximo de {$maxBytes} bytes (ai-chat.documents.max_bytes).";
        }

        $realPath = $match['file']->getRealPath();

        if ($realPath === false) {
            return 'No se pudo leer el documento.';
        }

        return $match['id']."\n\n".File::get($realPath);
    }

    /**
     * @return list<array{id: string, file: SplFileInfo}>
     */
    protected function entries(): array
    {
        $entries = [];
        $maxFiles = $this->maxFiles();

        foreach ($this->roots() as $root) {
            $finder = (new Finder)
                ->files()
                ->in($root['root'])
                ->name('/\.(md|markdown)$/i')
                ->exclude(['vendor', 'node_modules'])
                ->ignoreDotFiles(true)
                ->ignoreVCS(true)
                ->depth('< 8')
                ->sortByName();

            foreach ($finder as $file) {
                if (count($entries) >= $maxFiles) {
                    return $entries;
                }

                $realPath = $file->getRealPath();

                if ($realPath === false || ! $this->inside($root['root'], $realPath)) {
                    continue;
                }

                $relative = str_replace('\\', '/', $file->getRelativePathname());
                $entries[] = [
                    'id' => $root['key'].'/'.$relative,
                    'file' => $file,
                ];
            }
        }

        return $entries;
    }

    /**
     * @return array{id: string, file: SplFileInfo}|null
     */
    protected function find(string $normalized): ?array
    {
        $roots = $this->roots();
        [$key, $relative] = $this->split($normalized, $roots);

        if ($relative === '') {
            return null;
        }

        foreach ($roots as $root) {
            if ($key !== null && $root['key'] !== $key) {
                continue;
            }

            $candidate = $root['root'].DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $real = realpath($candidate);

            if ($real === false || ! is_file($real) || ! $this->inside($root['root'], $real)) {
                continue;
            }

            return [
                'id' => $root['key'].'/'.str_replace('\\', '/', $relative),
                'file' => new SplFileInfo($real),
            ];
        }

        return null;
    }

    /**
     * @param  list<array{key: string, root: string}>  $roots
     * @return array{0: ?string, 1: string}
     */
    protected function split(string $normalized, array $roots): array
    {
        $slash = strpos($normalized, '/');

        if ($slash === false) {
            return [null, $normalized];
        }

        $key = substr($normalized, 0, $slash);
        $relative = substr($normalized, $slash + 1);

        foreach ($roots as $root) {
            if ($root['key'] === $key) {
                return [$key, $relative];
            }
        }

        return [null, $normalized];
    }

    protected function normalize(string $path): ?string
    {
        $path = str_replace('\\', '/', trim($path));
        $path = ltrim($path, '/');

        if ($path === '' || str_contains($path, "\0") || str_contains($path, '..') || str_contains($path, ':')) {
            return null;
        }

        return $path;
    }

    /**
     * @return list<array{key: string, root: string}>
     */
    protected function roots(): array
    {
        $configured = config('ai-chat.documents.paths', []);

        if (! is_array($configured)) {
            return [];
        }

        $roots = [];
        $used = [];

        foreach ($configured as $path) {
            if (! is_string($path) || trim($path) === '') {
                continue;
            }

            $real = $this->resolveRoot(trim($path));

            if ($real === null) {
                continue;
            }

            $base = basename($real);
            $key = $base;
            $suffix = 2;

            while (isset($used[$key])) {
                $key = $base.'-'.$suffix;
                $suffix++;
            }

            $used[$key] = true;
            $roots[] = ['key' => $key, 'root' => $real];
        }

        return $roots;
    }

    protected function resolveRoot(string $path): ?string
    {
        $real = realpath($path);

        if ($real === false && ! $this->isAbsolute($path)) {
            $real = realpath(base_path($path));
        }

        if ($real === false || ! is_dir($real)) {
            return null;
        }

        return $real;
    }

    protected function isAbsolute(string $path): bool
    {
        if (str_starts_with($path, '/')) {
            return true;
        }

        return strlen($path) > 2
            && ctype_alpha($path[0])
            && $path[1] === ':'
            && ($path[2] === '\\' || $path[2] === '/');
    }

    protected function inside(string $root, string $file): bool
    {
        return $file === $root || str_starts_with($file, $root.DIRECTORY_SEPARATOR);
    }

    protected function maxFiles(): int
    {
        return max(1, (int) config('ai-chat.documents.max_files', 200));
    }
}
