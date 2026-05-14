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
 *   - @foreach($var) … @endforeach — iterates over an array parameter
 *
 * Inside @foreach blocks:
 *   - {{ $item }}              — scalar item value
 *   - {{ $item.key }}          — associative-array item field
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
     * Process @foreach($var) … @endforeach blocks, including nested ones.
     *
     * Uses a nesting-aware parser instead of a non-greedy regex so that each
     * opening @foreach is matched with its own @endforeach even when loops are
     * nested inside one another.
     *
     * Inside the body:
     *   {{ $item }}       — scalar item
     *   {{ $item.key }}   — field of an associative-array item
     */
    private function processForeach(string $content, array $params): string
    {
        $result   = '';
        $pos      = 0;
        $len      = strlen($content);
        $openTag  = '@foreach(';
        $closeTag = '@endforeach';

        while ($pos < $len) {
            // Find the next @foreach( directive.
            $foreachStart = strpos($content, $openTag, $pos);
            if ($foreachStart === false) {
                $result .= substr($content, $pos);
                break;
            }

            // Append content that precedes this directive.
            $result .= substr($content, $pos, $foreachStart - $pos);

            // Parse the variable name from @foreach($varName).
            $parenOpen  = $foreachStart + strlen($openTag) - 1; // position of '('
            $parenClose = strpos($content, ')', $parenOpen + 1);
            if ($parenClose === false) {
                // Malformed directive — emit literally and move past.
                $result .= $openTag;
                $pos = $foreachStart + strlen($openTag);
                continue;
            }

            $varExpr = trim(substr($content, $parenOpen + 1, $parenClose - $parenOpen - 1));
            if (!preg_match('/^\$(\w+)$/', $varExpr, $varMatch)) {
                // Unrecognised expression — emit literally and move past.
                $result .= substr($content, $foreachStart, $parenClose - $foreachStart + 1);
                $pos = $parenClose + 1;
                continue;
            }
            $varName   = $varMatch[1];
            $bodyStart = $parenClose + 1;

            // Find the matching @endforeach by counting nesting depth.
            $depth     = 1;
            $searchPos = $bodyStart;
            $bodyEnd   = -1;
            $fullEnd   = -1;

            while ($searchPos < $len) {
                $nextOpen  = strpos($content, $openTag, $searchPos);
                $nextClose = strpos($content, $closeTag, $searchPos);

                if ($nextClose === false) {
                    break; // No matching @endforeach — will fall through to error path.
                }

                if ($nextOpen !== false && $nextOpen < $nextClose) {
                    $depth++;
                    $searchPos = $nextOpen + strlen($openTag);
                } else {
                    $depth--;
                    if ($depth === 0) {
                        $bodyEnd = $nextClose;
                        $fullEnd = $nextClose + strlen($closeTag);
                        break;
                    }
                    $searchPos = $nextClose + strlen($closeTag);
                }
            }

            if ($bodyEnd === -1) {
                // No matching @endforeach found — emit the opening tag literally.
                $result .= $openTag;
                $pos = $foreachStart + strlen($openTag);
                continue;
            }

            $body = substr($content, $bodyStart, $bodyEnd - $bodyStart);

            // Expand the foreach block and advance past the closing @endforeach.
            $result .= $this->expandForeachBody($varName, $body, $params);
            $pos = $fullEnd;
        }

        return $result;
    }

    /**
     * Expand a single @foreach body for every element in the named parameter.
     *
     * Nested @foreach blocks are resolved *before* the outer {{ $item }} /
     * {{ $item.key }} placeholders are substituted so that the inner loop's
     * own $item references are consumed first, avoiding naming conflicts.
     */
    private function expandForeachBody(string $varName, string $body, array $params): string
    {
        if (!array_key_exists($varName, $params) || !is_array($params[$varName])) {
            return '';
        }

        $result = '';
        foreach ($params[$varName] as $item) {
            $iterContent = $body;

            if (is_array($item)) {
                // Resolve nested @foreach blocks first.  Item fields are merged
                // into params so that an inner @foreach($fieldName) can iterate
                // over a sub-array that belongs to the current outer item.
                $iterContent = $this->processForeach($iterContent, array_merge($params, $item));

                // Then substitute {{ $item.key }} placeholders for this iteration.
                foreach ($item as $key => $value) {
                    if (!is_scalar($value) && $value !== null) {
                        continue; // Arrays/objects cannot be inlined; null is allowed (renders as '').
                    }
                    $iterContent = str_replace(
                        '{{ $item.' . $key . ' }}',
                        htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                        $iterContent
                    );
                }
                // Remove any unresolved {{ $item.xxx }} placeholders.
                $iterContent = preg_replace('/\{\{\s*\$item\.\w+\s*\}\}/', '', $iterContent) ?? $iterContent;
            } else {
                // Resolve nested @foreach blocks first so that inner {{ $item }}
                // placeholders are consumed before the outer replacement runs.
                $iterContent = $this->processForeach($iterContent, $params);

                // Then substitute the outer {{ $item }} placeholder.
                $iterContent = str_replace(
                    '{{ $item }}',
                    htmlspecialchars((string) $item, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                    $iterContent
                );
            }

            $result .= $iterContent;
        }

        return $result;
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
