<?php
namespace ESolution\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

class Journal extends Model {
    protected $table = 'inv_journals';
    protected $fillable = ['document_id','date','memo'];
    public function entries(){ return $this->hasMany(JournalEntry::class,'journal_id'); }
}
