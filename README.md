# ztemp

A lightweight, **secure** PHP template engine for processing plain HTML files.

## Features

| Directive | Syntax | Description |
|---|---|---|
| Variable output | `{{ $name }}` | Inserts a parameter value (HTML-escaped) |
| Include | `@include(path/to/partial.html)` | Embeds another template |
| Loop | `@foreach($items) … @endforeach` | Iterates over an array parameter |
| Loop (alias) | `@foreach($items as $item) … @endforeach` | Iterates with a custom item alias |
| Loop (key => value) | `@foreach($items as $k => $v) … @endforeach` | Iterates with key and value aliases |

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

Iterate over an array parameter. Three styles are supported.

#### Default style (implicit `$item` alias)

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

#### Custom item alias — `@foreach($items as $alias)`

```html
<!-- templates/list.html -->
<ul>
@foreach($items as $fruit)
  <li>{{ $fruit }}</li>
@endforeach
</ul>
```

#### Key => value aliases — `@foreach($items as $key => $val)`

Use this form to access the array key alongside the value, or to iterate
over associative arrays with named fields.

```html
<!-- templates/attributes.html -->
<dl>
@foreach($attributes as $k => $v)
  <dt>{{ $k }}</dt>
  <dd>{{ $v }}</dd>
@endforeach
</dl>
```

```php
$engine->render('attributes.html', [
    'attributes' => ['color' => 'red', 'size' => 'large'],
]);
```

#### Associative / object array (default alias)

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

The same works with a custom alias or key=>value syntax:

```html
@foreach($users as $user)
  <p>{{ $user.name }}</p>
@endforeach

@foreach($users as $idx => $user)
  <p>{{ $idx }}: {{ $user.name }}</p>
@endforeach
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
