<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RequestDocument extends Model
{
    use HasUuids;

    protected $table = 'request_documents';
    protected $primaryKey = 'request_document_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'request_id',
        'user_id',
        'document_type',
        'document_number',
        'file_path',
        'approved_by_user_id',
        'approved_at',
        'verification_token_hash',
    ];

    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(Request::class, 'request_id', 'request_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id', 'user_id');
    }
}
