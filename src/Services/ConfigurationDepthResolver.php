<?php

namespace ESolution\Inventory\Services;

final class ConfigurationDepthResolver
{
    /** @return list<string> */
    public function validate(array $config): array
    {
        $errors = [];
        if (! (bool) ($config['organization']['levels']['warehouse'] ?? false)) {
            $errors[] = 'Organization warehouse level is mandatory.';
        }
        if (! (bool) ($config['storage']['levels']['warehouse'] ?? false)
            || ! (bool) ($config['storage']['levels']['rack'] ?? false)) {
            $errors[] = 'Storage warehouse and rack levels are mandatory.';
        }
        if (! in_array($config['costing']['scope'] ?? null, ['warehouse', 'rack'], true)) {
            $errors[] = 'Costing scope must be warehouse or rack.';
        }
        if (! in_array($config['costing']['default_method'] ?? null, ['fifo', 'weighted_average', 'moving_average'], true)) {
            $errors[] = 'Default costing method is not supported.';
        }
        foreach ((array) ($config['approval']['rejection_status_map'] ?? []) as $documentType => $target) {
            if (! is_string($documentType) || ! in_array($target, ['draft', 'cancelled'], true)) {
                $errors[] = 'Approval rejection targets must be draft or cancelled.';
                break;
            }
        }

        $reservationTargets = (array) ($config['policies']['negative_stock']['applies_to'] ?? ['goods_issue']);
        if (array_diff($reservationTargets, ['goods_issue', 'reservation']) !== []) {
            $errors[] = 'Negative-stock applies_to only supports goods_issue and reservation.';
        }

        return $errors;
    }

    /** @return array{0: string, 1: int} */
    public function costingScope(int $warehouseId, ?int $locationId = null): array
    {
        $type = (string) config('inventory.costing.scope', 'warehouse');

        return $type === 'rack'
            ? ['rack', $locationId ?? throw new \DomainException('Rack scope requires a storage location.')]
            : ['warehouse', $warehouseId];
    }
}
