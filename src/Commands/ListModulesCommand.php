<?php

namespace ESolution\Inventory\Commands;

use ESolution\Inventory\Support\ModuleCatalog;
use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migrator;

final class ListModulesCommand extends Command
{
    protected $signature = 'inventory:modules';

    protected $description = 'Show bundled module activation and database migration status';

    public function handle(): int
    {
        // Laravel 9/10 register this service by name, without a Migrator class binding.
        /** @var Migrator $migrator */
        $migrator = $this->laravel->make('migrator');
        $databaseError = null;
        try {
            $repository = $migrator->getRepository();
            $ran = $repository->repositoryExists() ? $repository->getRan() : [];
        } catch (\Throwable $exception) {
            $ran = null;
            $databaseError = 'Database unavailable; migration status could not be checked.';
        }

        $rows = [];
        foreach (ModuleCatalog::all() as $name => $module) {
            $files = $migrator->getMigrationFiles([$module['migrations']]);
            $pending = $ran === null ? [] : array_diff(array_keys($files), $ran);
            $status = $files === [] ? 'Not required' : ($ran === null ? 'Unknown (database)' : ($pending === [] ? 'Complete' : count($pending) . ' pending'));
            $rows[] = [
                $name,
                class_exists($module['provider']) ? 'Yes' : 'No',
                is_file(config_path('inventory-' . $name . '.php')) ? 'Yes' : 'No',
                $this->laravel->getProvider($module['provider']) !== null ? 'Yes' : 'No',
                $status,
            ];
        }
        $this->table(['Module', 'Code available', 'Config published', 'Active', 'Migrations'], $rows);
        if ($databaseError !== null) {
            $this->warn($databaseError);
        }

        return self::SUCCESS;
    }
}
