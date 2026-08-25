<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    use HasUuids;

    protected $primaryKey = 'log_id';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $fillable = ['user_id', 'activity', 'timestamp'];
    protected function casts(): array { return ['timestamp' => 'datetime']; }
    public function user(): BelongsTo { return $this->belongsTo(User::class, 'user_id', 'user_id'); }
}