<?php

namespace ESolution\Inventory\Tests\Fakes;

final class FakeIdentityResolver
{
    public function getActorType(): string
    {
        return 'system';
    }

    public function getActorId(): string
    {
        return 'inventory-tests';
    }

    public function getTenantId(): ?string
    {
        return null;
    }
}
