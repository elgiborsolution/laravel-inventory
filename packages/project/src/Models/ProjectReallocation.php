<?php

namespace ESolution\InventoryProject\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectReallocation extends Model
{
    protected $table = 'invp_project_reallocations';

    protected $guarded = [];

    protected $casts = ['qty' => 'float'];

    public function sourceAllocation(): BelongsTo
    {
        return $this->belongsTo(ProjectAllocation::class, 'source_allocation_id');
    }

    public function destinationAllocation(): BelongsTo
    {
        return $this->belongsTo(ProjectAllocation::class, 'destination_allocation_id');
    }
}
