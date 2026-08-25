<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RequestDetail extends Model
{
    use HasUuids;

    protected $primaryKey = 'request_detail_id';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $fillable = ['request_id', 'item_id', 'amount_request', 'status', 'amount_granted'];
    protected function casts(): array { return ['amount_request' => 'integer', 'amount_granted' => 'integer']; }
    public function request(): BelongsTo { return $this->belongsTo(Request::class, 'request_id', 'request_id'); }
    public function item(): BelongsTo { return $this->belongsTo(Item::class, 'item_id', 'item_id'); }
}