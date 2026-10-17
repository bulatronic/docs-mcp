<?php

declare(strict_types=1);

$finder = new PhpCsFixer\Finder()
    ->files()
    ->name('*.php')
    ->in([
        __DIR__.'/src',
        __DIR__.'/tests',
        __DIR__.'/public',
    ]);

return new PhpCsFixer\Config()
    ->setRiskyAllowed(true)
    ->setRules([
        '@PER-CS2.0' => true,
        'declare_strict_types' => true,
        'ordered_imports' => [
            'sort_algorithm' => 'alpha',
            'imports_order' => [
                'class',
                'function',
                'const',
            ],
        ],
        'no_unused_imports' => true,
        'single_quote' => true,
        'trailing_comma_in_multiline' => [
            'elements' => ['arguments', 'arrays', 'match', 'parameters'],
        ],
    ])
    ->setFinder($finder)
    ->setCacheFile('/tmp/docs-mcp-php-cs-fixer.cache');
