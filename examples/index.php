<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Tandrezone\Ztemp\TemplateEngine;

// Point the engine at the examples/templates directory.
$engine = new TemplateEngine(__DIR__ . '/templates');

$params = [
    'title'    => 'My Online Store',
    'subtitle' => 'Featured Products',
    'username' => 'Alice',
    'footer'   => '© 2024 My Online Store',
    'products' => [
        [
            'name'        => 'Laptop Pro 15"',
            'price'       => '1 299.00',
            'description' => 'Powerful laptop with M3 chip.',
        ],
        [
            'name'        => 'Mechanical Keyboard',
            'price'       => '149.99',
            'description' => 'Tactile switches, RGB lighting.',
        ],
        [
            'name'        => '<script>alert("xss")</script>',   // this will be safely escaped
            'price'       => '0.00',
            'description' => 'An attempted XSS payload — rendered safely.',
        ],
    ],
];

$output = $engine->render('layout.html', $params);

echo $output;
