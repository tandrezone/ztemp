<?php

declare(strict_types=1);

namespace Tandrezone\Ztemp\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tandrezone\Ztemp\TemplateEngine;

class TemplateEngineTest extends TestCase
{
    private string $templateDir;
    private TemplateEngine $engine;

    protected function setUp(): void
    {
        $this->templateDir = __DIR__ . '/templates';
        $this->engine      = new TemplateEngine($this->templateDir);
    }

    // -------------------------------------------------------------------------
    // Basic variable substitution
    // -------------------------------------------------------------------------

    public function testRendersSimpleVariable(): void
    {
        $output = $this->engine->render('basic.html', ['name' => 'World']);
        $this->assertStringContainsString('Hello, World!', $output);
    }

    public function testMultipleVariables(): void
    {
        $output = $this->engine->render('combined.html', [
            'title'  => 'My Title',
            'items'  => ['a', 'b'],
            'footer' => 'Bye',
        ]);
        $this->assertStringContainsString('My Title', $output);
        $this->assertStringContainsString('Bye', $output);
    }

    public function testMissingVariableIsRemovedFromOutput(): void
    {
        $output = $this->engine->render('missing_var.html', []);
        $this->assertStringNotContainsString('{{ $missing }}', $output);
        $this->assertStringContainsString('<p>Missing: </p>', $output);
    }

    // -------------------------------------------------------------------------
    // XSS / HTML escaping
    // -------------------------------------------------------------------------

    public function testVariablesAreHtmlEscaped(): void
    {
        $xss    = '<script>alert("xss")</script>';
        $output = $this->engine->render('xss.html', ['input' => $xss]);
        $this->assertStringNotContainsString('<script>', $output);
        $this->assertStringContainsString('&lt;script&gt;', $output);
    }

    public function testSpecialCharactersAreEscaped(): void
    {
        $output = $this->engine->render('basic.html', ['name' => '"O\'Reilly" & <friends>']);
        $this->assertStringContainsString('&quot;O&#039;Reilly&quot; &amp; &lt;friends&gt;', $output);
    }

    // -------------------------------------------------------------------------
    // @include directive
    // -------------------------------------------------------------------------

    public function testIncludeRendersPartialTemplate(): void
    {
        $output = $this->engine->render('include_main.html', ['value' => 'included!']);
        $this->assertStringContainsString('<span>included!</span>', $output);
    }

    public function testIncludePassesParametersToPartial(): void
    {
        $output = $this->engine->render('include_main.html', ['value' => 'passed value']);
        $this->assertStringContainsString('passed value', $output);
    }

    public function testNestedInclude(): void
    {
        $output = $this->engine->render('nested_a.html', ['nested' => 'deep']);
        $this->assertStringContainsString('B=deep', $output);
        $this->assertStringContainsString('A:', $output);
    }

    public function testIncludeEscapesVariablesToo(): void
    {
        $output = $this->engine->render('include_main.html', ['value' => '<b>bold</b>']);
        $this->assertStringNotContainsString('<b>', $output);
        $this->assertStringContainsString('&lt;b&gt;', $output);
    }

    // -------------------------------------------------------------------------
    // @foreach directive
    // -------------------------------------------------------------------------

    public function testForeachWithScalarItems(): void
    {
        $output = $this->engine->render('foreach_scalar.html', [
            'items' => ['apple', 'banana', 'cherry'],
        ]);
        $this->assertStringContainsString('<li>apple</li>', $output);
        $this->assertStringContainsString('<li>banana</li>', $output);
        $this->assertStringContainsString('<li>cherry</li>', $output);
    }

    public function testForeachWithAssociativeItems(): void
    {
        $output = $this->engine->render('foreach_assoc.html', [
            'users' => [
                ['name' => 'Alice', 'age' => 30],
                ['name' => 'Bob',   'age' => 25],
            ],
        ]);
        $this->assertStringContainsString('<li>Alice (30)</li>', $output);
        $this->assertStringContainsString('<li>Bob (25)</li>', $output);
    }

    public function testForeachEscapesItemValues(): void
    {
        $output = $this->engine->render('foreach_scalar.html', [
            'items' => ['<script>alert(1)</script>'],
        ]);
        $this->assertStringNotContainsString('<script>', $output);
        $this->assertStringContainsString('&lt;script&gt;', $output);
    }

    public function testForeachEscapesAssocItemValues(): void
    {
        $output = $this->engine->render('foreach_assoc.html', [
            'users' => [
                ['name' => '<b>Hacker</b>', 'age' => 99],
            ],
        ]);
        $this->assertStringNotContainsString('<b>', $output);
        $this->assertStringContainsString('&lt;b&gt;', $output);
    }

    public function testForeachWithMissingVariableProducesEmptyOutput(): void
    {
        $output = $this->engine->render('foreach_scalar.html', []);
        $this->assertStringNotContainsString('{{ $item }}', $output);
        $this->assertStringNotContainsString('@foreach', $output);
    }

    public function testForeachWithNonArrayVariableProducesEmptyOutput(): void
    {
        $output = $this->engine->render('foreach_scalar.html', ['items' => 'not-an-array']);
        $this->assertStringNotContainsString('@foreach', $output);
        $this->assertStringNotContainsString('{{ $item }}', $output);
    }

    public function testForeachWithEmptyArrayProducesNoItems(): void
    {
        $output = $this->engine->render('foreach_scalar.html', ['items' => []]);
        $this->assertStringNotContainsString('<li>', $output);
    }

    public function testForeachAndVariablesTogether(): void
    {
        $output = $this->engine->render('combined.html', [
            'title'  => 'Shopping List',
            'items'  => ['milk', 'eggs'],
            'footer' => 'Done',
        ]);
        $this->assertStringContainsString('Shopping List', $output);
        $this->assertStringContainsString('<span>milk</span>', $output);
        $this->assertStringContainsString('<span>eggs</span>', $output);
        $this->assertStringContainsString('Done', $output);
    }

    // -------------------------------------------------------------------------
    // Nested @foreach
    // -------------------------------------------------------------------------

    public function testNestedForeachOuterAssocInnerScalar(): void
    {
        $output = $this->engine->render('foreach_nested.html', [
            'categories' => [
                ['name' => 'Fruits'],
                ['name' => 'Veggies'],
            ],
            'tags' => ['organic', 'fresh'],
        ]);
        $this->assertStringContainsString('<h2>Fruits</h2>', $output);
        $this->assertStringContainsString('<h2>Veggies</h2>', $output);
        // Inner tags rendered for each outer category
        $this->assertSame(2, substr_count($output, '<li>organic</li>'));
        $this->assertSame(2, substr_count($output, '<li>fresh</li>'));
    }

    public function testNestedForeachTwoScalarLists(): void
    {
        $fixture = $this->templateDir . '/foreach_nested_scalar.html';
        file_put_contents(
            $fixture,
            "@foreach(\$outer)\nouter={{ \$item }}\n@foreach(\$inner)\ninner={{ \$item }}\n@endforeach\n@endforeach\n"
        );

        try {
            $output = $this->engine->render('foreach_nested_scalar.html', [
                'outer' => ['A', 'B'],
                'inner' => ['x', 'y'],
            ]);
            // Both outer items present
            $this->assertStringContainsString('outer=A', $output);
            $this->assertStringContainsString('outer=B', $output);
            // Inner items appear once per outer iteration
            $this->assertSame(2, substr_count($output, 'inner=x'));
            $this->assertSame(2, substr_count($output, 'inner=y'));
        } finally {
            @unlink($fixture);
        }
    }

    public function testNestedForeachWithSubArrayField(): void
    {
        // The inner @foreach iterates over a field of each outer assoc item.
        // Because the outer item's fields are merged into params before the
        // inner loop runs, @foreach($skills) resolves correctly.
        $fixture = $this->templateDir . '/foreach_nested_subfield.html';
        file_put_contents(
            $fixture,
            "@foreach(\$users)\n{{ \$item.name }}:\n@foreach(\$skills)\n- {{ \$item }}\n@endforeach\n@endforeach\n"
        );

        try {
            $output = $this->engine->render('foreach_nested_subfield.html', [
                'users' => [
                    ['name' => 'Alice', 'skills' => ['PHP', 'JS']],
                    ['name' => 'Bob',   'skills' => ['Python']],
                ],
            ]);
            $this->assertStringContainsString('Alice:', $output);
            $this->assertStringContainsString('Bob:', $output);
            $this->assertStringContainsString('- PHP', $output);
            $this->assertStringContainsString('- JS', $output);
            $this->assertStringContainsString('- Python', $output);
        } finally {
            @unlink($fixture);
        }
    }

    // -------------------------------------------------------------------------
    // Security: path traversal
    // -------------------------------------------------------------------------

    public function testPathTraversalIsBlocked(): void
    {
        $this->expectException(RuntimeException::class);
        // Attempt to escape the template directory
        $this->engine->render('../../composer.json');
    }

    public function testAbsolutePathInIncludeIsBlocked(): void
    {
        // Create a template that tries to include an absolute path
        $malicious = sys_get_temp_dir() . '/ztemp_test_traversal_' . getmypid() . '.html';
        file_put_contents($malicious, '@include(/etc/passwd)');

        // Copy it into the template dir so we can render it
        $fixture = $this->templateDir . '/traversal_abs.html';
        file_put_contents($fixture, '@include(/etc/passwd)');

        try {
            $this->expectException(RuntimeException::class);
            $this->engine->render('traversal_abs.html');
        } finally {
            @unlink($fixture);
            @unlink($malicious);
        }
    }

    public function testNullByteInPathIsBlocked(): void
    {
        $this->expectException(RuntimeException::class);
        $this->engine->render("basic.html\0.evil");
    }

    // -------------------------------------------------------------------------
    // Security: circular @include / recursion depth
    // -------------------------------------------------------------------------

    public function testCircularIncludeThrowsException(): void
    {
        $dirA = $this->templateDir . '/circular_a.html';
        $dirB = $this->templateDir . '/circular_b.html';

        file_put_contents($dirA, '@include(circular_b.html)');
        file_put_contents($dirB, '@include(circular_a.html)');

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessageMatches('/depth|circular/i');
            $this->engine->render('circular_a.html');
        } finally {
            @unlink($dirA);
            @unlink($dirB);
        }
    }

    // -------------------------------------------------------------------------
    // Error handling
    // -------------------------------------------------------------------------

    public function testNonExistentTemplateThrowsException(): void
    {
        $this->expectException(RuntimeException::class);
        $this->engine->render('does_not_exist.html');
    }

    public function testInvalidBasePathThrowsException(): void
    {
        $this->expectException(RuntimeException::class);
        new TemplateEngine('/this/path/does/not/exist');
    }

    // -------------------------------------------------------------------------
    // Edge cases
    // -------------------------------------------------------------------------

    public function testTemplateWithNoDirectivesIsReturnedAsIs(): void
    {
        $fixture = $this->templateDir . '/plain.html';
        file_put_contents($fixture, '<p>No directives here.</p>');

        try {
            $output = $this->engine->render('plain.html');
            $this->assertSame('<p>No directives here.</p>', $output);
        } finally {
            @unlink($fixture);
        }
    }

    public function testEmptyParametersArrayLeavesNoPlaceholders(): void
    {
        $output = $this->engine->render('basic.html', []);
        $this->assertStringNotContainsString('{{ $', $output);
    }

    public function testIntegerParameterIsRendered(): void
    {
        $output = $this->engine->render('basic.html', ['name' => 42]);
        $this->assertStringContainsString('Hello, 42!', $output);
    }

    public function testNullParameterRendersAsEmpty(): void
    {
        $output = $this->engine->render('basic.html', ['name' => null]);
        $this->assertStringContainsString('Hello, !', $output);
    }
}
