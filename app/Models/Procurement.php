<?php

namespace App\Models;

use App\Enums\ProcurementDocumentType;
use App\Enums\ProcurementStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Realisasi pengadaan — lahir begitu satu ProcurementRequest disetujui.
 *
 * Berhenti di status MenungguPenerimaan sampai Pengelola Gudang
 * mengonfirmasi penerimaan lewat ProcurementReceipt; baru saat itu stok
 * bertambah. Lihat ADR 0008 dan ADR 0015.
 */
class Procurement extends Model
{
    use HasUuids;

    protected $primaryKey = 'procurement_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'procurement_request_id', 'supplier_id', 'procurement_date', 'status', 'user_id',
    ];

    protected function casts(): array
    {
        return [
            'procurement_date' => 'date',
            'status' => ProcurementStatus::class,
        ];
    }

    /**
     * Judul singkat untuk tampilan dan notifikasi, mis. "Realisasi #A1B2C3D4".
     */
    public function judul(): string
    {
        return 'Realisasi #'.strtoupper(substr($this->procurement_id, 0, 8));
    }

    public function isMenungguPenerimaan(): bool
    {
        return $this->status === ProcurementStatus::MenungguPenerimaan;
    }

    public function isDiterima(): bool
    {
        return $this->status === ProcurementStatus::Diterima;
    }

    public function scopeFilter(Builder $query, array $filters): Builder
    {
        return $query
            ->when(
                ProcurementStatus::tryFrom((string) ($filters['status'] ?? '')),
                fn (Builder $query, ProcurementStatus $status) => $query->where('status', $status),
            );
    }

    /**
     * Nilai seluruh barang yang dipesan, dalam rupiah bulat.
     */
    public function nilaiTotal(): int
    {
        return $this->details->sum(fn (ProcurementDetail $detail) => $detail->subtotal());
    }

    /**
     * Apakah ada barang yang datang kurang dari yang dipesan.
     *
     * Bukan status tersendiri: pertanyaannya dijawab dari rincian, supaya tidak
     * ada dua sumber kebenaran. Lihat ADR 0017.
     */
    public function diterimaSebagian(): bool
    {
        return $this->details->contains(fn (ProcurementDetail $detail) => $detail->kurangKirim() > 0);
    }

    /**
     * Apakah berkas BAST bertanda tangan sudah diunggah.
     *
     * Penerimaan sengaja tidak menunggu berkas ini — lihat ADR 0018 — sehingga
     * keadaan "sudah diterima tetapi BAST belum diunggah" memang sah dan perlu
     * bisa ditanyakan.
     */
    public function bastSudahDiunggah(): bool
    {
        return $this->documents()
            ->where('type', ProcurementDocumentType::Bast)
            ->exists();
    }

    public function procurementRequest(): BelongsTo
    {
        return $this->belongsTo(ProcurementRequest::class, 'procurement_request_id', 'procurement_request_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id', 'supplier_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function details(): HasMany
    {
        return $this->hasMany(ProcurementDetail::class, 'procurement_id', 'procurement_id');
    }

    public function receipt(): HasOne
    {
        return $this->hasOne(ProcurementReceipt::class, 'procurement_id', 'procurement_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ProcurementDocument::class, 'procurement_id', 'procurement_id');
    }
}
