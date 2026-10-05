<?php

namespace ESolution\Inventory\Commands;

use ESolution\Inventory\Contracts\DocumentTypeRegistry;
use ESolution\Inventory\Support\ApprovalPackageInspector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ValidateApprovalCommand extends Command
{
    protected $signature = 'inventory:approval:validate';

    protected $description = 'Validate Inventory Approval Bridge prerequisites and published workflows';

    public function handle(
        DocumentTypeRegistry $documentTypes,
        ApprovalPackageInspector $package,
    ): int {
        if (! $package->installed()) {
            $this->info('Approval Flow package is not installed; Null Approval Bridge is active.');

            return self::SUCCESS;
        }

        if (config('approval-flow.default_status_field') !== 'approval_status') {
            $this->error("approval-flow.default_status_field must be 'approval_status'.");

            return self::FAILURE;
        }

        $identityResolver = config('approval-flow.identity_resolver');
        if (! is_string($identityResolver) || $identityResolver === '' || ! class_exists($identityResolver)) {
            $this->error('approval-flow.identity_resolver must reference a resolvable project class.');

            return self::FAILURE;
        }
        $identityContract = 'ESolution\\ApprovalFlow\\Contracts\\IdentityResolver';
        if (interface_exists($identityContract) && ! is_a($identityResolver, $identityContract, true)) {
            $this->error('approval-flow.identity_resolver must implement the external IdentityResolver contract.');

            return self::FAILURE;
        }
        try {
            app()->make($identityResolver);
        } catch (Throwable $exception) {
            $this->error('Unable to resolve approval identity resolver: ' . $exception->getMessage());

            return self::FAILURE;
        }

        $types = array_keys($documentTypes->all());
        try {
            $workflows = DB::connection(config('approval-flow.database.connection'))
                ->table('approval_rules as rules')
                ->join('approval_workflows as workflows', 'workflows.id', '=', 'rules.workflow_id')
                ->whereIn('rules.module', $types)
                ->get(['rules.module', 'workflows.id', 'workflows.status']);
        } catch (Throwable $exception) {
            $this->error('Unable to inspect approval workflows: ' . $exception->getMessage());

            return self::FAILURE;
        }

        foreach ($workflows as $workflow) {
            if ($workflow->status !== 'active') {
                $this->warn(
                    "Approval workflow '{$workflow->id}' for module '{$workflow->module}' is not published/active.",
                );
            }
        }

        $serviceAuth = (bool) config('approval-flow.enforce_service_auth', true);
        $this->info($serviceAuth
            ? 'Service authorization is enabled; confirm a system identity exists for queue/console execution.'
            : 'Service authorization is disabled by project configuration.');
        $this->info('Inventory approval configuration validation completed.');

        return self::SUCCESS;
    }
}
