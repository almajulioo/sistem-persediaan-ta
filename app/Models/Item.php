<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Item extends Model
{
    use HasUuids;

    protected $primaryKey = 'item_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'item_code', 'item_name', 'unit', 'stock_current', 'stock_min', 'stock_max',
    ];

    protected function casts(): array
    {
        return [
            'stock_current' => 'integer',
            'stock_min' => 'integer',
            'stock_max' => 'integer',
        ];
    }

    public function opnameDetails(): HasMany { return $this->hasMany(OpnameDetail::class, 'item_id', 'item_id'); }
    public function procurementDetails(): HasMany { return $this->hasMany(ProcurementDetail::class, 'item_id', 'item_id'); }
    public function requestDetails(): HasMany { return $this->hasMany(RequestDetail::class, 'item_id', 'item_id'); }
    public function stockMutations(): HasMany { return $this->hasMany(StockMutation::class, 'item_id', 'item_id'); }
}