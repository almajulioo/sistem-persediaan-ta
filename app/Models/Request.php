<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Request extends Model
{
    use HasFactory, HasUuids;

    /**
     * The primary key for the model.
     *
     * @var string
     */
    protected $primaryKey = 'request_id';

    /**
     * The "type" of the primary key ID.
     *
     * @var string
     */
    protected $keyType = 'string';

    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'date',
        'type',
        'status',
        'bast_number',
        'nd_number',
        'request_amount',
        'accept_amount',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'request_amount' => 'integer',
            'accept_amount' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function details(): HasMany
    {
        return $this->hasMany(RequestDetail::class, 'request_id', 'request_id');
    }

    public function procurementRequests(): HasMany
    {
        return $this->hasMany(ProcurementRequest::class, 'request_id', 'request_id');
    }

    public function notaDinas(): HasMany
    {
        return $this->hasMany(NotaDinas::class, 'request_id', 'request_id');
    }

    public function basts(): HasMany
    {
        return $this->hasMany(Bast::class, 'request_id', 'request_id');
    }
}
