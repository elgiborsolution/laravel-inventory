<?php

namespace ESolution\Inventory\Bridges\Support;

use Illuminate\Contracts\Container\Container;

final class TenantResolver
{
    public function __construct(private readonly Container $container) {}

    public function resolve(mixed $explicitIdentity = null): ?string
    {
        $identity = $explicitIdentity;
        if ($identity === null && $this->container->bound('current-tenant-id')) {
            $identity = $this->container->make('current-tenant-id');
        }

        return $identity === null ? null : (string) $identity;
    }
}
