<?php

namespace ESolution\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Reservation extends Model
{
    protected $table = 'inv_reservations';
    protected $guarded = [];

    public function consumptions(): HasMany
    {
        return $this->hasMany(ReservationConsumption::class);
    }

    public function getRemainingQtyAttribute(): float
    {
        return (float) $this->reserved_qty - (float) $this->consumed_qty - (float) $this->released_qty;
    }
}
