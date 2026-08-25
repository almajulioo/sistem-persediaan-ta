<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProcurementRequest extends Model
{
    use HasUuids;

    protected $primaryKey = 'procurement_request_id';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $fillable = ['request_id', 'request_type', 'user_id'];
    public function request(): BelongsTo { return $this->belongsTo(Request::class, 'request_id', 'request_id'); }
    public function user(): BelongsTo { return $this->belongsTo(User::class, 'user_id', 'user_id'); }
}