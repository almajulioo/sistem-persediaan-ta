<?php

namespace App\Models;

use App\Enums\ProcurementRequestSource;
use App\Enums\ProcurementRequestStatus;
use Illuminate\Database\Eloquent\Builder;
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

    /**
     * Judul singkat untuk tampilan dan notifikasi, mis. "Usulan #A1B2C3D4".
     */
    public function judul(): string
    {
        return 'Usulan #'.strtoupper(substr($this->procurement_request_id, 0, 8));
    }

    public function isDiajukan(): bool
    {
        return $this->status === ProcurementRequestStatus::Diajukan;
    }

    public function isDisetujui(): bool
    {
        return $this->status === ProcurementRequestStatus::Disetujui;
    }

    public function isDitolak(): bool
    {
        return $this->status === ProcurementRequestStatus::Ditolak;
    }

    /**
     * Ringkasan rincian barang, dipakai tampilan daftar dan isi notifikasi.
     *
     * `amount_granted` null (belum diputuskan) dihitung 0 pada jumlah disetujui.
     *
     * @return array<string, int>
     */
    public function ringkasan(): array
    {
        $details = $this->relationLoaded('details') ? $this->details : $this->details()->get();

        return [
            'barang' => $details->count(),
            'barang_disetujui' => $details->filter(fn (ProcurementRequestDetail $detail) => $detail->disetujui())->count(),
            'diminta' => (int) $details->sum('amount_request'),
            'disetujui' => (int) $details->sum(fn (ProcurementRequestDetail $detail) => $detail->amount_granted ?? 0),
        ];
    }

    public function scopeFilter(Builder $query, array $filters): Builder
    {
        return $query
            ->when(
                ProcurementRequestStatus::tryFrom((string) ($filters['status'] ?? '')),
                fn (Builder $query, ProcurementRequestStatus $status) => $query->where('status', $status),
            )
            ->when(
                ProcurementRequestSource::tryFrom((string) ($filters['source'] ?? '')),
                fn (Builder $query, ProcurementRequestSource $source) => $query->where('source', $source),
            );
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
