<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Rincian barang pada satu usulan pengadaan.
 *
 * Sebelumnya bernama `RequestDetail` dengan foreign key yang salah arah
 * (menunjuk `requests`, permintaan unit milik sub sistem Permintaan). Nama
 * dan arah FK diperbaiki di migrasi 2026_09_05_000002. Lihat ADR 0015.
 */
class ProcurementRequestDetail extends Model
{
    use HasUuids;

    protected $primaryKey = 'procurement_request_detail_id';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = ['procurement_request_id', 'item_id', 'amount_request', 'amount_granted'];

    protected function casts(): array
    {
        return [
            'amount_request' => 'integer',
            'amount_granted' => 'integer',
        ];
    }

    /**
     * Sudah diputuskan berapa banyak yang disetujui untuk barang ini.
     *
     * `amount_granted` merangkap keputusan per barang — null berarti belum
     * diputuskan, 0 berarti barang ini tidak disetujui. Kolom `status`
     * terpisah yang dulu ada dihapus karena menyatakan hal yang sama.
     */
    public function sudahDiputuskan(): bool
    {
        return $this->amount_granted !== null;
    }

    public function disetujui(): bool
    {
        return ($this->amount_granted ?? 0) > 0;
    }

    public function procurementRequest(): BelongsTo
    {
        return $this->belongsTo(ProcurementRequest::class, 'procurement_request_id', 'procurement_request_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id', 'item_id');
    }
}
