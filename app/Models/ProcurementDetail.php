<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProcurementDetail extends Model
{
    use HasUuids;

    protected $primaryKey = 'procurement_detail_id';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $fillable = ['procurement_id', 'item_id', 'amount'];
    protected function casts(): array { return ['amount' => 'integer']; }
    public function procurement(): BelongsTo { return $this->belongsTo(Procurement::class, 'procurement_id', 'procurement_id'); }
    public function item(): BelongsTo { return $this->belongsTo(Item::class, 'item_id', 'item_id'); }
}