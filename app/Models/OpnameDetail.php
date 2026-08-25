<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OpnameDetail extends Model
{
    use HasUuids;

    protected $primaryKey = 'opname_detail_id';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $fillable = ['opname_id', 'item_id', 'stock_stored', 'stock_real', 'difference', 'adjustment_note'];
    protected function casts(): array { return ['stock_stored' => 'integer', 'stock_real' => 'integer', 'difference' => 'integer']; }
    public function opname(): BelongsTo { return $this->belongsTo(StockOpname::class, 'opname_id', 'opname_id'); }
    public function item(): BelongsTo { return $this->belongsTo(Item::class, 'item_id', 'item_id'); }
}