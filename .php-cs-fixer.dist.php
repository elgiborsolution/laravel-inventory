<?php

$finder = PhpCsFixer\Finder::create()
    ->in([
        __DIR__ . '/config',
        __DIR__ . '/packages/library/config',
        __DIR__ . '/packages/library/src',
        __DIR__ . '/packages/library/database/migrations',
        __DIR__ . '/packages/automotive/config',
        __DIR__ . '/packages/automotive/src',
        __DIR__ . '/database/migrations',
        __DIR__ . '/packages/asset/config',
        __DIR__ . '/packages/asset/database/migrations',
        __DIR__ . '/packages/asset/src',
        __DIR__ . '/packages/food/config',
        __DIR__ . '/packages/food/database/migrations',
        __DIR__ . '/packages/food/src',
        __DIR__ . '/packages/healthcare/config',
        __DIR__ . '/packages/healthcare/database/migrations',
        __DIR__ . '/packages/healthcare/src',
        __DIR__ . '/packages/manufacturing/config',
        __DIR__ . '/packages/manufacturing/database/migrations',
        __DIR__ . '/packages/manufacturing/src',
        __DIR__ . '/packages/project/database/migrations',
        __DIR__ . '/packages/project/src',
        __DIR__ . '/packages/retail/config',
        __DIR__ . '/packages/retail/database/migrations',
        __DIR__ . '/packages/retail/src',
        __DIR__ . '/packages/wms/config',
        __DIR__ . '/packages/wms/database/migrations',
        __DIR__ . '/packages/wms/src',
        __DIR__ . '/src',
        __DIR__ . '/tests',
        __DIR__ . '/tools',
    ])
    ->append([__FILE__]);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setLineEnding(PHP_EOL)
    ->setRules([
        '@PER-CS2.0' => true,
        'array_syntax' => ['syntax' => 'short'],
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'single_quote' => true,
    ])
    ->setFinder($finder);
