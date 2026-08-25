<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockOpname extends Model
{
    use HasUuids;

    protected $primaryKey = 'opname_id';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $fillable = ['opname_date', 'user_id'];
    protected function casts(): array { return ['opname_date' => 'date']; }
    public function user(): BelongsTo { return $this->belongsTo(User::class, 'user_id', 'user_id'); }
    public function details(): HasMany { return $this->hasMany(OpnameDetail::class, 'opname_id', 'opname_id'); }
}