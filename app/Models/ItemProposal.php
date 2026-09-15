<?php

namespace App\Models;

use App\Enums\SatuanBarang;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ItemProposal extends Model
{
    use HasUuids;

    protected $primaryKey = 'item_proposal_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'user_id',
        'item_name',
        'unit',
        'reason',
        'status',
        'followed_up_by',
        'followed_up_at',
    ];

    protected function casts(): array
    {
        return [
            'unit' => SatuanBarang::class,
            'quantity_requested' => 'integer',
            'followed_up_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function followedUpBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'followed_up_by', 'user_id');
    }
}