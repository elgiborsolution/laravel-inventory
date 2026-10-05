<?php

namespace ESolution\Inventory\DTO;

final class ReservationConsumptionData
{
    public function __construct(
        public int $reservationId,
        public int $lineNo,
        public float $qty,
        public string $idempotencyKey,
    ) {}
}
