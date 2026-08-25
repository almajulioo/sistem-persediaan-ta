<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotaDinas extends Model
{
    use HasUuids;

    protected $table = 'nota_dinas';
    protected $primaryKey = 'nota_dinas_id';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $fillable = ['request_id', 'nd_number', 'file_url'];
    public function request(): BelongsTo { return $this->belongsTo(Request::class, 'request_id', 'request_id'); }
}