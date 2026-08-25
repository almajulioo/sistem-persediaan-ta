<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Bast extends Model
{
    use HasUuids;

    protected $table = 'basts';
    protected $primaryKey = 'bast_id';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $fillable = ['request_id', 'bast_number', 'file_url'];
    public function request(): BelongsTo { return $this->belongsTo(Request::class, 'request_id', 'request_id'); }
}