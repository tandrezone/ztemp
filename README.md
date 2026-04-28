# ztemp

A lightweight, **secure** PHP template engine for processing plain HTML files.

## Features

| Directive | Syntax | Description |
|---|---|---|
| Variable output | `{{ $name }}` | Inserts a parameter value (HTML-escaped) |
| Include | `@include(path/to/partial.html)` | Embeds another template |
| Loop | `@foreach($items) … @endforeach` | Iterates over an array parameter |

### Security built-in

* All variable output is **HTML-escaped** (`htmlspecialchars`) — no raw HTML injection.
* `@include` is **path-traversal safe** — files outside the configured base directory are rejected.
* Circular `@include` chains are detected and stopped (max depth: 10).
* Null bytes in template paths are rejected.

---

## Requirements

* PHP **8.1** or higher

---

## Installation

```bash
composer require tandrezone/ztemp
```

---

## Quick Start

```php
<?php

use Tandrezone\Ztemp\TemplateEngine;

$engine = new TemplateEngine(__DIR__ . '/templates');

$html = $engine->render('page.html', [
    'title' => 'Hello World',
    'name'  => 'Alice',
]);

echo $html;
```

---

## Template Syntax

### Variables — `{{ $name }}`

Use double curly braces to output a parameter. The value is automatically HTML-escaped.

```html
<!-- templates/greeting.html -->
<p>Hello, {{ $name }}!</p>
<p>You have {{ $count }} messages.</p>
```

```php
$engine->render('greeting.html', [
    'name'  => 'Bob',
    'count' => 3,
]);
// → <p>Hello, Bob!</p>
//   <p>You have 3 messages.</p>
```

Missing variables are silently removed from the output (no placeholder leaks).

---

### Include — `@include(path)`

Embed one template inside another. The path is relative to the engine's base directory.

```html
<!-- templates/layout.html -->
<!DOCTYPE html>
<html>
<head><title>{{ $title }}</title></head>
<body>
@include(header.html)
<main>{{ $body }}</main>
</body>
</html>
```

```html
<!-- templates/header.html -->
<header><h1>{{ $title }}</h1></header>
```

All parameters are automatically forwarded to included templates.

**Security note:** Paths containing `../` or absolute paths (e.g. `/etc/passwd`) that resolve outside the base directory will throw a `RuntimeException`.

---

### Foreach — `@foreach($param) … @endforeach`

Iterate over an array parameter. Two styles are supported.

#### Scalar array

```html
<!-- templates/list.html -->
<ul>
@foreach($items)
  <li>{{ $item }}</li>
@endforeach
</ul>
```

```php
$engine->render('list.html', [
    'items' => ['apple', 'banana', 'cherry'],
]);
```

#### Associative / object array

```html
<!-- templates/users.html -->
<table>
@foreach($users)
  <tr>
    <td>{{ $item.name }}</td>
    <td>{{ $item.email }}</td>
  </tr>
@endforeach
</table>
```

```php
$engine->render('users.html', [
    'users' => [
        ['name' => 'Alice', 'email' => 'alice@example.com'],
        ['name' => 'Bob',   'email' => 'bob@example.com'],
    ],
]);
```

If the referenced variable does not exist or is not an array the block is removed from the output.

---

## API Reference

### `TemplateEngine::__construct(string $basePath = '')`

| Parameter | Description |
|---|---|
| `$basePath` | Absolute path to the directory that contains your templates. When empty, template paths are treated as-is (absolute paths allowed). |

Throws `RuntimeException` if the supplied base path does not exist.

### `TemplateEngine::render(string $templatePath, array $params = []): string`

| Parameter | Description |
|---|---|
| `$templatePath` | Path to the template file, **relative to `$basePath`**. |
| `$params` | Associative array of parameters available inside the template. |

Returns the fully rendered string. Throws `RuntimeException` on file-not-found, path traversal, or excessive include depth.

---

## Example

See [`examples/index.php`](examples/index.php) for a complete working demo:

```bash
php examples/index.php
```

---

## Running Tests

```bash
composer install
./vendor/bin/phpunit
```

---

## Security Considerations

### What is safe

* Variable values are always HTML-escaped — you cannot inject raw HTML or JavaScript through `{{ $var }}`.
* `@include` resolves paths against the configured base directory and rejects any path that resolves outside it.

### What to watch out for

* Do **not** put untrusted content directly in template *files* — only pass untrusted data through the `$params` array.
* The `@foreach` body is part of the template (trusted), not the data. Only `{{ $item }}` / `{{ $item.key }}` placeholders inside the body are data-driven and escaped.

---

## License

MIT
