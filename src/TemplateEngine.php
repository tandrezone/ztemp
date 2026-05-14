<?php

declare(strict_types=1);

namespace Tandrezone\Ztemp;

use RuntimeException;

/**
 * Lightweight, secure PHP template engine.
 *
 * Supported directives:
 *   - {{ $variable }}          — HTML-escaped variable output
 *   - @include(path)           — includes another template (path-traversal safe)
 *   - @foreach($var) … @endforeach            — iterates over an array parameter
 *   - @foreach($var as $alias) … @endforeach  — iterates with a custom item alias
 *   - @foreach($var as $k => $v) … @endforeach — iterates with key and value aliases
 *
 * Inside @foreach blocks:
 *   - {{ $item }}              — scalar item value (default alias)
 *   - {{ $item.key }}          — associative-array item field (default alias)
 *   - {{ $alias }}             — scalar item value with custom alias
 *   - {{ $alias.key }}         — associative-array item field with custom alias
 *   - {{ $k }}                 — array key (key => value syntax)
 *   - {{ $v }}                 — scalar item value (key => value syntax)
 *   - {{ $v.key }}             — associative-array item field (key => value syntax)
 */
class TemplateEngine
{
    /** Maximum allowed @include nesting depth (prevents infinite recursion). */
    private const MAX_INCLUDE_DEPTH = 10;

    private string $basePath;

    /**
     * @param string $basePath  Absolute base directory for template resolution.
     *                          All @include paths are resolved relative to this
     *                          directory. Leave empty to use absolute paths.
     */
    public function __construct(string $basePath = '')
    {
        if ($basePath !== '') {
            $real = realpath($basePath);
            if ($real === false) {
                throw new RuntimeException("Base path does not exist: {$basePath}");
            }
            $this->basePath = $real;
        } else {
            $this->basePath = '';
        }
    }

    /**
     * Render a template file with the supplied parameters.
     *
     * @param string               $templatePath  Path to the template (relative to basePath or absolute when basePath is empty).
     * @param array<string, mixed> $params        Key-value parameter map.
     *
     * @return string Rendered output.
     */
    public function render(string $templatePath, array $params = []): string
    {
        return $this->renderInternal($templatePath, $params, 0);
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    private function renderInternal(string $templatePath, array $params, int $depth): string
    {
        if ($depth > self::MAX_INCLUDE_DEPTH) {
            throw new RuntimeException(
                'Maximum @include depth (' . self::MAX_INCLUDE_DEPTH . ') exceeded. ' .
                'Possible circular @include detected.'
            );
        }

        $fullPath = $this->resolvePath($templatePath);
        $content  = file_get_contents($fullPath);

        if ($content === false) {
            throw new RuntimeException("Cannot read template file: {$fullPath}");
        }

        // Order matters: includes first (they may introduce new directives),
        // then foreach, then plain variable substitution.
        $content = $this->processIncludes($content, $params, $depth);
        $content = $this->processForeach($content, $params);
        $content = $this->processVariables($content, $params);

        return $content;
    }

    /**
     * Resolve a template path safely, preventing directory traversal.
     */
    private function resolvePath(string $path): string
    {
        // Reject null bytes — they can be used to truncate paths on some systems.
        if (str_contains($path, "\0")) {
            throw new RuntimeException('Template path contains illegal null byte.');
        }

        // Strip leading directory separators so callers cannot supply absolute paths.
        $path = ltrim($path, '/\\');

        if ($path === '') {
            throw new RuntimeException('Template path must not be empty.');
        }

        $candidate = $this->basePath !== ''
            ? $this->basePath . DIRECTORY_SEPARATOR . $path
            : $path;

        $realPath = realpath($candidate);

        if ($realPath === false) {
            throw new RuntimeException("Template not found: {$path}");
        }

        // Security: the resolved path must stay inside the configured base directory.
        if ($this->basePath !== '') {
            // Use a trailing separator to prevent partial directory-name matches.
            $base = $this->basePath . DIRECTORY_SEPARATOR;
            if (!str_starts_with($realPath . DIRECTORY_SEPARATOR, $base)) {
                throw new RuntimeException(
                    "Path traversal detected — template is outside the base directory: {$path}"
                );
            }
        }

        return $realPath;
    }

    /**
     * Replace @include(path) directives by rendering the referenced template.
     */
    private function processIncludes(string $content, array $params, int $depth): string
    {
        return preg_replace_callback(
            '/@include\(\s*["\']?([^"\'()\s]+)["\']?\s*\)/',
            function (array $matches) use ($params, $depth): string {
                return $this->renderInternal(trim($matches[1]), $params, $depth + 1);
            },
            $content
        ) ?? $content;
    }

    /**
     * Process @foreach blocks in three supported forms:
     *
     *   @foreach($var) … @endforeach
     *   @foreach($var as $alias) … @endforeach
     *   @foreach($var as $key => $val) … @endforeach
     *
     * Inside the body:
     *   {{ $item }}        — scalar item (default alias)
     *   {{ $item.key }}    — associative-array field (default alias)
     *   {{ $alias }}       — scalar item with custom alias
     *   {{ $alias.field }} — associative-array field with custom alias
     *   {{ $key }}         — array key (key => val syntax)
     *   {{ $val }}         — scalar item value (key => val syntax)
     *   {{ $val.field }}   — associative-array field (key => val syntax)
     */
    private function processForeach(string $content, array $params): string
    {
        return preg_replace_callback(
            '/@foreach\(\s*\$(\w+)(?:\s+as\s+\$(\w+)(?:\s*=>\s*\$(\w+))?)?\s*\)(.*?)@endforeach/s',
            function (array $matches) use ($params): string {
                $varName  = $matches[1];
                $aliasKey = $matches[2] !== '' ? $matches[2] : null;
                $valName  = (isset($matches[3]) && $matches[3] !== '') ? $matches[3] : null;
                $body     = $matches[4];

                if (!array_key_exists($varName, $params) || !is_array($params[$varName])) {
                    return '';
                }

                // $isKeyVal: @foreach($var as $k => $v)
                // $itemName: the placeholder used for the current item value
                $isKeyVal = ($aliasKey !== null && $valName !== null);
                $itemName = $isKeyVal ? $valName : ($aliasKey ?? 'item');

                $result = '';
                foreach ($params[$varName] as $key => $item) {
                    $iterContent = $body;

                    if ($isKeyVal) {
                        $iterContent = str_replace(
                            '{{ $' . $aliasKey . ' }}',
                            htmlspecialchars((string) $key, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                            $iterContent
                        );
                    }

                    if (is_array($item)) {
                        foreach ($item as $field => $value) {
                            $iterContent = str_replace(
                                '{{ $' . $itemName . '.' . $field . ' }}',
                                htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                                $iterContent
                            );
                        }
                        // Remove any unresolved {{ $itemName.xxx }} inside this iteration.
                        $iterContent = preg_replace('/\{\{\s*\$' . preg_quote($itemName, '/') . '\.\w+\s*\}\}/', '', $iterContent) ?? $iterContent;
                    } else {
                        $iterContent = str_replace(
                            '{{ $' . $itemName . ' }}',
                            htmlspecialchars((string) $item, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                            $iterContent
                        );
                    }

                    $result .= $iterContent;
                }

                return $result;
            },
            $content
        ) ?? $content;
    }

    /**
     * Replace {{ $variable }} placeholders with HTML-escaped parameter values.
     * Any placeholder that has no matching parameter is removed from the output.
     */
    private function processVariables(string $content, array $params): string
    {
        foreach ($params as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $content = str_replace(
                    '{{ $' . $key . ' }}',
                    htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                    $content
                );
            }
        }

        // Remove any remaining {{ $... }} placeholders (including {{ $item.xxx }}).
        $content = preg_replace('/\{\{\s*\$[\w.]+\s*\}\}/', '', $content) ?? $content;

        return $content;
    }
}
