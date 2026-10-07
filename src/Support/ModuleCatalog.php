<?php

namespace ESolution\Inventory\Support;

final class ModuleCatalog
{
    /** @return array<string, array{provider: class-string, config: string, migrations: string}> */
    public static function all(): array
    {
        $modules = [];
        foreach (glob(__DIR__ . '/../../packages/*/composer.json') ?: [] as $file) {
            $manifest = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
            $directory = dirname($file);
            $name = basename($directory);
            $modules[$name] = [
                'provider' => $manifest['extra']['laravel']['providers'][0],
                'config' => $directory . '/config/inventory-' . $name . '.php',
                'migrations' => $directory . '/database/migrations',
            ];
        }

        return $modules;
    }
}
