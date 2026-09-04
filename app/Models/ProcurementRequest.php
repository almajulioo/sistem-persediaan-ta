<?php

namespace App\Models;

use App\Enums\ProcurementRequestSource;
use App\Enums\ProcurementRequestStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Usulan pengadaan — permintaan dari Gudang kepada Pejabat Pengadaan.
 *
 * Tidak berelasi ke `requests` (permintaan unit ke gudang, milik sub sistem
 * Permintaan) lewat foreign key basis data. Satu-satunya jalur ke sana adalah
 * `reference_type`/`reference_id` polimorfik, dan itu pun hanya terisi ketika
 * `source` bernilai DariPermintaan. Lihat ADR 0015.
 */
class ProcurementRequest extends Model
{
    use HasUuids;

    protected $primaryKey = 'procurement_request_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'user_id', 'source', 'reference_type', 'reference_id', 'status',
    ];

    protected function casts(): array
    {
        return [
            'source' => ProcurementRequestSource::class,
            'status' => ProcurementRequestStatus::class,
        ];
    }

    public function isDiajukan(): bool
    {
        return $this->status === ProcurementRequestStatus::Diajukan;
    }

    public function isDisetujui(): bool
    {
        return $this->status === ProcurementRequestStatus::Disetujui;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function details(): HasMany
    {
        return $this->hasMany(ProcurementRequestDetail::class, 'procurement_request_id', 'procurement_request_id');
    }

    public function procurement(): HasOne
    {
        return $this->hasOne(Procurement::class, 'procurement_request_id', 'procurement_request_id');
    }

    /**
     * Dokumen asal saat `source` DariPermintaan — baris `requests` milik sub
     * sistem Permintaan lewat alias morph map `permintaan`.
     */
    public function reference(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'reference_type', 'reference_id');
    }
}
