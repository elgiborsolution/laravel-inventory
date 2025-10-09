<?php
namespace ESolution\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

class JournalEntry extends Model {
    protected $table = 'inv_journal_entries';
    protected $fillable = ['journal_id','account','dc','amount','meta'];
    protected $casts = ['meta'=>'array'];
}
