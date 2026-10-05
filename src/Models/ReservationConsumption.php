<?php

namespace ESolution\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ReservationConsumption extends Model
{
    public $timestamps = false;

    protected $table = 'inv_reservation_consumptions';

    protected $guarded = [];

    protected $casts = ['created_at' => 'datetime'];

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function documentLine(): BelongsTo
    {
        return $this->belongsTo(DocumentLine::class);
    }
}
