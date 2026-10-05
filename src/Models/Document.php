<?php

namespace ESolution\Inventory\Models;

use ESolution\Inventory\Enums\DocumentStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** @property-read Collection<int, DocumentLine> $lines */
class Document extends Model
{
    protected $table = 'inv_documents';
    protected $guarded = [];
    protected $casts = ['meta' => 'array','trx_date' => 'date','posted_at' => 'datetime','status' => DocumentStatus::class];
    public function lines(): HasMany
    {
        return $this->hasMany(DocumentLine::class);
    } public function audits(): HasMany
    {
        return $this->hasMany(AuditTrail::class);
    } public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }
}
