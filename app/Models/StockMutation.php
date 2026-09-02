<?php

namespace App\Models;

use App\Enums\MutationType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockMutation extends Model
{
    use HasUuids;

    protected $primaryKey = 'mutation_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['item_id', 'mutation_type', 'amount', 'mutation_date', 'reference', 'reference_type'];

    protected function casts(): array
    {
        return [
            'mutation_type' => MutationType::class,
            'amount' => 'integer',
            'mutation_date' => 'date',
        ];
    }

    /**
     * Jumlah mutasi dengan tanda eksplisit, mis. "+50" atau "-10".
     */
    public function signedAmount(): string
    {
        return ($this->amount > 0 ? '+' : '').$this->amount;
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id', 'item_id');
    }
}
