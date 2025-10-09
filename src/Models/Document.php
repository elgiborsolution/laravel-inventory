<?php
namespace ESolution\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

class Document extends Model {
    protected $table = 'inv_documents';
    protected $fillable = ['external_id','type','date','ref','meta'];
    protected $casts = ['meta'=>'array'];
    public function lines(){ return $this->hasMany(DocumentLine::class,'document_id'); }
}
