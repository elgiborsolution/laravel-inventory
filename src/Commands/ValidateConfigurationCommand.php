<?php

namespace ESolution\Inventory\Commands;

use ESolution\Inventory\Services\ConfigurationDepthResolver;
use Illuminate\Console\Command;

final class ValidateConfigurationCommand extends Command
{
    protected $signature = 'inventory:validate-config';

    protected $description = 'Validate the Inventory Core configuration';

    public function handle(ConfigurationDepthResolver $resolver): int
    {
        $errors = $resolver->validate((array) config('inventory', []));
        if ($errors !== []) {
            foreach ($errors as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $this->info('Inventory configuration is valid.');

        return self::SUCCESS;
    }
}
