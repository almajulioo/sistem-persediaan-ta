<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Procurement extends Model
{
    use HasUuids;

    protected $primaryKey = 'procurement_id';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $fillable = ['procurement_date', 'status', 'bast_scan', 'additional_doc', 'user_id', 'supplier'];
    protected function casts(): array { return ['procurement_date' => 'date']; }
    public function user(): BelongsTo { return $this->belongsTo(User::class, 'user_id', 'user_id'); }
    public function details(): HasMany { return $this->hasMany(ProcurementDetail::class, 'procurement_id', 'procurement_id'); }
}