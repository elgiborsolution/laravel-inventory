<?php

function inventoryPhpFiles(string $directory): array
{
    if (! is_dir($directory)) {
        return [];
    }

    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    return $files;
}

it('prevents Core from importing vertical namespaces', function (): void {
    $verticalNamespaces = [
        'ESolution\\InventoryRetail\\',
        'ESolution\\InventoryWms\\',
        'ESolution\\InventoryManufacturing\\',
        'ESolution\\InventoryHealthcare\\',
        'ESolution\\InventoryFood\\',
        'ESolution\\InventoryAsset\\',
        'ESolution\\InventoryProject\\',
        'ESolution\\InventoryAutomotive\\',
        'ESolution\\InventoryLibrary\\',
    ];

    foreach (inventoryPhpFiles(dirname(__DIR__, 2) . '/src') as $file) {
        $source = file_get_contents($file);

        foreach ($verticalNamespaces as $namespace) {
            expect($source)->not->toContain($namespace, "Core file {$file} imports {$namespace}");
        }
    }
});

it('prevents Core migrations from owning vertical or external tables', function (): void {
    $forbiddenPrefixes = ['invr_', 'invw_', 'invm_', 'invh_', 'invf_', 'inva_', 'invp_', 'invat_', 'invl_', 'acc_', 'approval_'];

    foreach (inventoryPhpFiles(dirname(__DIR__, 2) . '/database/migrations') as $file) {
        $source = file_get_contents($file);

        foreach ($forbiddenPrefixes as $prefix) {
            expect($source)->not->toContain("Schema::create('{$prefix}", "Core migration {$file} owns {$prefix} tables");
        }
    }
});

it('prevents Core Composer dependencies on vertical packages', function (): void {
    $composer = json_decode(file_get_contents(dirname(__DIR__, 2) . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
    $dependencies = array_keys(($composer['require'] ?? []) + ($composer['require-dev'] ?? []));

    foreach ($dependencies as $dependency) {
        expect($dependency)->not->toStartWith('elgibor-solution/laravel-inventory-');
    }
});

it('keeps the Retail package dependent on Core only and inside its namespace and table prefix', function (): void {
    $root = dirname(__DIR__, 2);
    $retailRoot = $root . '/packages/retail';
    $composer = json_decode(file_get_contents($retailRoot . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
    $dependencies = array_keys($composer['require'] ?? []);

    expect($dependencies)->toContain('elgibor-solution/laravel-inventory');
    foreach ($dependencies as $dependency) {
        if ($dependency !== 'elgibor-solution/laravel-inventory') {
            expect($dependency)->not->toStartWith('elgibor-solution/laravel-inventory-');
        }
    }

    $siblingNamespaces = [
        'ESolution\\InventoryWms\\',
        'ESolution\\InventoryManufacturing\\',
        'ESolution\\InventoryHealthcare\\',
        'ESolution\\InventoryFood\\',
        'ESolution\\InventoryAsset\\',
        'ESolution\\InventoryProject\\',
        'ESolution\\InventoryAutomotive\\',
        'ESolution\\InventoryLibrary\\',
    ];
    foreach (inventoryPhpFiles($retailRoot . '/src') as $file) {
        $source = file_get_contents($file);
        expect($source)->toContain('namespace ESolution\\InventoryRetail');
        foreach ($siblingNamespaces as $namespace) {
            expect($source)->not->toContain($namespace, "Retail file {$file} imports sibling {$namespace}");
        }
    }

    foreach (inventoryPhpFiles($retailRoot . '/database/migrations') as $file) {
        preg_match_all("/Schema::create\\('([^']+)'/", file_get_contents($file), $matches);
        foreach ($matches[1] as $table) {
            expect($table)->toStartWith('invr_');
        }
    }
});

it('keeps Consignment settlement accounting project-owned', function (): void {
    $retailSource = file_get_contents(
        dirname(__DIR__, 2) . '/packages/retail/src/Services/SettlementRecorder.php',
    );

    expect($retailSource)->not->toContain('AccountingBridge')
        ->and($retailSource)->not->toContain('AccountingPostingData');
});

it('keeps the WMS package dependent on Core only and inside its namespace and table prefix', function (): void {
    $root = dirname(__DIR__, 2);
    $wmsRoot = $root . '/packages/wms';
    $composer = json_decode(file_get_contents($wmsRoot . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
    $dependencies = array_keys($composer['require'] ?? []);

    expect($dependencies)->toContain('elgibor-solution/laravel-inventory');
    foreach ($dependencies as $dependency) {
        if ($dependency !== 'elgibor-solution/laravel-inventory') {
            expect($dependency)->not->toStartWith('elgibor-solution/laravel-inventory-');
        }
    }

    $siblingNamespaces = [
        'ESolution\\InventoryRetail\\',
        'ESolution\\InventoryManufacturing\\',
        'ESolution\\InventoryHealthcare\\',
        'ESolution\\InventoryFood\\',
        'ESolution\\InventoryAsset\\',
        'ESolution\\InventoryProject\\',
        'ESolution\\InventoryAutomotive\\',
        'ESolution\\InventoryLibrary\\',
    ];
    foreach (inventoryPhpFiles($wmsRoot . '/src') as $file) {
        $source = file_get_contents($file);
        expect($source)->toContain('namespace ESolution\\InventoryWms');
        foreach ($siblingNamespaces as $namespace) {
            expect($source)->not->toContain($namespace, "WMS file {$file} imports sibling {$namespace}");
        }
    }

    foreach (inventoryPhpFiles($wmsRoot . '/database/migrations') as $file) {
        preg_match_all("/Schema::create\\('([^']+)'/", file_get_contents($file), $matches);
        foreach ($matches[1] as $table) {
            expect($table)->toStartWith('invw_');
        }
    }
});

it('keeps the Manufacturing package dependent on Core only and inside its namespace and table prefix', function (): void {
    $root = dirname(__DIR__, 2);
    $manufacturingRoot = $root . '/packages/manufacturing';
    $composer = json_decode(
        file_get_contents($manufacturingRoot . '/composer.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $dependencies = array_keys($composer['require'] ?? []);

    expect($dependencies)->toContain('elgibor-solution/laravel-inventory');
    foreach ($dependencies as $dependency) {
        if ($dependency !== 'elgibor-solution/laravel-inventory') {
            expect($dependency)->not->toStartWith('elgibor-solution/laravel-inventory-');
        }
    }

    $siblingNamespaces = [
        'ESolution\\InventoryRetail\\',
        'ESolution\\InventoryWms\\',
        'ESolution\\InventoryHealthcare\\',
        'ESolution\\InventoryFood\\',
        'ESolution\\InventoryAsset\\',
        'ESolution\\InventoryProject\\',
        'ESolution\\InventoryAutomotive\\',
        'ESolution\\InventoryLibrary\\',
    ];
    foreach (inventoryPhpFiles($manufacturingRoot . '/src') as $file) {
        $source = file_get_contents($file);
        expect($source)->toContain('namespace ESolution\\InventoryManufacturing');
        foreach ($siblingNamespaces as $namespace) {
            expect($source)->not->toContain($namespace, "Manufacturing file {$file} imports sibling {$namespace}");
        }
        expect($source)->not->toContain('StockLedger::create')
            ->and($source)->not->toContain('CostLayer::create')
            ->and($source)->not->toContain('implements MovementPolicy');
    }

    foreach (inventoryPhpFiles($manufacturingRoot . '/database/migrations') as $file) {
        preg_match_all("/Schema::create\\('([^']+)'/", file_get_contents($file), $matches);
        foreach ($matches[1] as $table) {
            expect($table)->toStartWith('invm_');
        }
    }
});

it('keeps the Healthcare package dependent on Core only and inside its namespace and table prefix', function (): void {
    $root = dirname(__DIR__, 2);
    $healthcareRoot = $root . '/packages/healthcare';
    $composer = json_decode(
        file_get_contents($healthcareRoot . '/composer.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $dependencies = array_keys($composer['require'] ?? []);

    expect($dependencies)->toContain('elgibor-solution/laravel-inventory');
    foreach ($dependencies as $dependency) {
        if ($dependency !== 'elgibor-solution/laravel-inventory') {
            expect($dependency)->not->toStartWith('elgibor-solution/laravel-inventory-');
        }
    }

    $siblingNamespaces = [
        'ESolution\\InventoryRetail\\',
        'ESolution\\InventoryWms\\',
        'ESolution\\InventoryManufacturing\\',
        'ESolution\\InventoryFood\\',
        'ESolution\\InventoryAsset\\',
        'ESolution\\InventoryProject\\',
        'ESolution\\InventoryAutomotive\\',
        'ESolution\\InventoryLibrary\\',
    ];
    foreach (inventoryPhpFiles($healthcareRoot . '/src') as $file) {
        $source = file_get_contents($file);
        expect($source)->toContain('namespace ESolution\\InventoryHealthcare');
        foreach ($siblingNamespaces as $namespace) {
            expect($source)->not->toContain($namespace, "Healthcare file {$file} imports sibling {$namespace}");
        }
    }

    foreach (inventoryPhpFiles($healthcareRoot . '/database/migrations') as $file) {
        preg_match_all("/Schema::create\\('([^']+)'/", file_get_contents($file), $matches);
        foreach ($matches[1] as $table) {
            expect($table)->toStartWith('invh_');
        }
    }
});

it('keeps the Food package dependent on Core only and inside its namespace and table prefix', function (): void {
    $root = dirname(__DIR__, 2);
    $foodRoot = $root . '/packages/food';
    $composer = json_decode(
        file_get_contents($foodRoot . '/composer.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $dependencies = array_keys($composer['require'] ?? []);

    expect($dependencies)->toContain('elgibor-solution/laravel-inventory');
    foreach ($dependencies as $dependency) {
        if ($dependency !== 'elgibor-solution/laravel-inventory') {
            expect($dependency)->not->toStartWith('elgibor-solution/laravel-inventory-');
        }
    }

    $siblingNamespaces = [
        'ESolution\\InventoryRetail\\',
        'ESolution\\InventoryWms\\',
        'ESolution\\InventoryManufacturing\\',
        'ESolution\\InventoryHealthcare\\',
        'ESolution\\InventoryAsset\\',
        'ESolution\\InventoryProject\\',
        'ESolution\\InventoryAutomotive\\',
        'ESolution\\InventoryLibrary\\',
    ];
    foreach (inventoryPhpFiles($foodRoot . '/src') as $file) {
        $source = file_get_contents($file);
        expect($source)->toContain('namespace ESolution\\InventoryFood');
        foreach ($siblingNamespaces as $namespace) {
            expect($source)->not->toContain($namespace, "Food file {$file} imports sibling {$namespace}");
        }
        expect($source)->not->toContain('StockLedger::create')
            ->and($source)->not->toContain('CostLayer::create')
            ->and($source)->not->toContain('implements MovementPolicy');
    }

    foreach (inventoryPhpFiles($foodRoot . '/database/migrations') as $file) {
        preg_match_all("/Schema::create\\('([^']+)'/", file_get_contents($file), $matches);
        foreach ($matches[1] as $table) {
            expect($table)->toStartWith('invf_');
        }
    }
});

it('keeps the Asset package dependent on Core only and inside its namespace and table prefix', function (): void {
    $root = dirname(__DIR__, 2);
    $assetRoot = $root . '/packages/asset';
    $composer = json_decode(
        file_get_contents($assetRoot . '/composer.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $dependencies = array_keys($composer['require'] ?? []);

    expect($dependencies)->toContain('elgibor-solution/laravel-inventory');
    foreach ($dependencies as $dependency) {
        if ($dependency !== 'elgibor-solution/laravel-inventory') {
            expect($dependency)->not->toStartWith('elgibor-solution/laravel-inventory-');
        }
    }

    $siblingNamespaces = [
        'ESolution\\InventoryRetail\\',
        'ESolution\\InventoryWms\\',
        'ESolution\\InventoryManufacturing\\',
        'ESolution\\InventoryHealthcare\\',
        'ESolution\\InventoryFood\\',
        'ESolution\\InventoryProject\\',
        'ESolution\\InventoryAutomotive\\',
        'ESolution\\InventoryLibrary\\',
    ];
    foreach (inventoryPhpFiles($assetRoot . '/src') as $file) {
        $source = file_get_contents($file);
        expect($source)->toContain('namespace ESolution\\InventoryAsset');
        foreach ($siblingNamespaces as $namespace) {
            expect($source)->not->toContain($namespace, "Asset file {$file} imports sibling {$namespace}");
        }
        expect($source)->not->toContain('DocumentTypeDefinition')
            ->and($source)->not->toContain('implements MovementPolicy')
            ->and($source)->not->toContain('implements CostingDriver')
            ->and($source)->not->toContain('StockLedger::create')
            ->and($source)->not->toContain('CostLayer::create');
    }

    foreach (inventoryPhpFiles($assetRoot . '/database/migrations') as $file) {
        preg_match_all("/Schema::create\\('([^']+)'/", file_get_contents($file), $matches);
        foreach ($matches[1] as $table) {
            expect($table)->toStartWith('inva_');
        }
    }
});

it('keeps the Project package dependent on Core only and inside its namespace and table prefix', function (): void {
    $root = dirname(__DIR__, 2);
    $projectRoot = $root . '/packages/project';
    $composer = json_decode(
        file_get_contents($projectRoot . '/composer.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $dependencies = array_keys($composer['require'] ?? []);

    expect($dependencies)->toContain('elgibor-solution/laravel-inventory');
    foreach ($dependencies as $dependency) {
        if ($dependency !== 'elgibor-solution/laravel-inventory') {
            expect($dependency)->not->toStartWith('elgibor-solution/laravel-inventory-');
        }
    }

    $siblingNamespaces = [
        'ESolution\\InventoryRetail\\',
        'ESolution\\InventoryWms\\',
        'ESolution\\InventoryManufacturing\\',
        'ESolution\\InventoryHealthcare\\',
        'ESolution\\InventoryFood\\',
        'ESolution\\InventoryAsset\\',
        'ESolution\\InventoryAutomotive\\',
        'ESolution\\InventoryLibrary\\',
    ];
    foreach (inventoryPhpFiles($projectRoot . '/src') as $file) {
        $source = file_get_contents($file);
        expect($source)->toContain('namespace ESolution\\InventoryProject');
        foreach ($siblingNamespaces as $namespace) {
            expect($source)->not->toContain($namespace, "Project file {$file} imports sibling {$namespace}");
        }
        expect($source)->not->toContain('DocumentTypeDefinition')
            ->and($source)->not->toContain('implements MovementPolicy')
            ->and($source)->not->toContain('implements CostingDriver')
            ->and($source)->not->toContain('StockLedger::create')
            ->and($source)->not->toContain('CostLayer::create');
    }

    foreach (inventoryPhpFiles($projectRoot . '/database/migrations') as $file) {
        preg_match_all("/Schema::create\\('([^']+)'/", file_get_contents($file), $matches);
        foreach ($matches[1] as $table) {
            expect($table)->toStartWith('invp_');
        }
    }
});
