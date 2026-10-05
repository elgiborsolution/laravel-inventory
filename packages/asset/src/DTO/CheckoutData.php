<?php

namespace ESolution\InventoryAsset\DTO;

final class CheckoutData
{
    public function __construct(
        public readonly string $checkoutNo,
        public readonly int $serialId,
        public readonly int $warehouseId,
        public readonly string $borrowerType,
        public readonly string $borrowerId,
        public readonly ?string $checkedOutAt = null,
        public readonly ?string $dueAt = null,
        public readonly array $meta = [],
    ) {
        if ($checkoutNo === '' || $borrowerType === '' || $borrowerId === '') {
            throw new \InvalidArgumentException('Asset checkout number and borrower reference are required.');
        }
    }
}
