<?php

test('Phase14 complete catalog has independent dependencies unique tables and migration names', function (): void {
    $root = dirname(__DIR__, 2);
    $manifests = glob($root . '/packages/*/composer.json');
    $namespaces = [];
    foreach ($manifests as $file) {
        $manifest = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        $namespaces[dirname($file)] = array_key_first($manifest['autoload']['psr-4']);
    }
    $tables = [];
    $migrationNames = [];
    foreach ($namespaces as $directory => $namespace) {
        $manifest = json_decode(file_get_contents($directory . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        expect(array_keys($manifest['require']))->toEqualCanonicalizing(['php', 'elgibor-solution/laravel-inventory']);
        foreach (inventoryPhpFiles($directory . '/src') as $file) {
            $source = file_get_contents($file);
            foreach ($namespaces as $sibling) {
                if ($sibling !== $namespace) {
                    expect($source)->not->toContain($sibling);
                }
            }
        }
    }
    foreach (array_merge([$root], array_keys($namespaces)) as $directory) {
        foreach (inventoryPhpFiles($directory . '/database/migrations') as $file) {
            expect($migrationNames)->not->toContain(basename($file));
            $migrationNames[] = basename($file);
            preg_match_all("/Schema::create\\('([^']+)'/", file_get_contents($file), $matches);
            foreach ($matches[1] as $table) {
                expect($tables)->not->toContain($table);
                $tables[] = $table;
            }
        }
    }
});
