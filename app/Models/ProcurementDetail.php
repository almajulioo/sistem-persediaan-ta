<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu baris barang pada realisasi pengadaan.
 *
 * Dua kolom jumlah, dan perbedaannya penting: `amount` adalah yang **dipesan**,
 * `amount_received` yang **benar-benar datang**. Stok bertambah sebanyak yang
 * kedua, bukan yang pertama — lihat ADR 0017.
 */
class ProcurementDetail extends Model
{
    use HasUuids;

    protected $primaryKey = 'procurement_detail_id';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'procurement_id', 'item_id', 'amount', 'unit_price', 'amount_received',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'unit_price' => 'integer',
            'amount_received' => 'integer',
        ];
    }

    /**
     * Nilai baris ini dalam rupiah bulat, dihitung dari jumlah yang dipesan.
     */
    public function subtotal(): int
    {
        return $this->amount * $this->unit_price;
    }

    public function sudahDiterima(): bool
    {
        return $this->amount_received !== null;
    }

    /**
     * Selisih kurang kiriman penyedia. Nol bila belum dikonfirmasi maupun bila
     * kiriman lengkap.
     */
    public function kurangKirim(): int
    {
        if (! $this->sudahDiterima()) {
            return 0;
        }

        return max(0, $this->amount - $this->amount_received);
    }

    public function procurement(): BelongsTo
    {
        return $this->belongsTo(Procurement::class, 'procurement_id', 'procurement_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id', 'item_id');
    }
}
