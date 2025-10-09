<?php
namespace ESolution\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentLine extends Model {
    protected $table = 'inv_document_lines';
    protected $fillable = ['document_id','item_id','branch_id','warehouse_id','rack_id','qty','unit_cost','meta'];
    protected $casts = ['meta'=>'array'];
    public function document(){ return $this->belongsTo(Document::class); }
    public function item(){ return $this->belongsTo(Item::class); }
}
