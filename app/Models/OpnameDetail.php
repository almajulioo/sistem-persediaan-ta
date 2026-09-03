<?php

namespace App\Models;

use App\Enums\AlasanSelisih;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu baris pencacahan: satu barang dalam satu dokumen opname.
 *
 * Lihat migrasi add_adjustment_columns_to_opname_details_table untuk makna
 * kelima angkanya.
 */
class OpnameDetail extends Model
{
    use HasUuids;

    protected $primaryKey = 'opname_detail_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'opname_id', 'item_id', 'stock_stored', 'stock_real', 'counted_at',
        'difference', 'adjustment_reason', 'adjustment_note',
        'stock_at_posting', 'adjustment_amount',
    ];

    protected function casts(): array
    {
        return [
            'stock_stored' => 'integer',
            'stock_real' => 'integer',
            'difference' => 'integer',
            'stock_at_posting' => 'integer',
            'adjustment_amount' => 'integer',
            'counted_at' => 'datetime',
            'adjustment_reason' => AlasanSelisih::class,
        ];
    }

    public function sudahDicacah(): bool
    {
        return $this->stock_real !== null;
    }

    public function adaSelisih(): bool
    {
        return $this->sudahDicacah() && $this->difference !== 0;
    }

    /**
     * Stok bergerak setelah barang ini dicacah, mis. ada penyerahan barang di
     * tengah opname berjalan. Ditandai di laporan supaya penyesuaian yang
     * berbeda dari selisih hasil cacah dapat dijelaskan.
     */
    public function stokBerubahSejakDicacah(): bool
    {
        return $this->stock_at_posting !== null
            && $this->stock_at_posting !== $this->stock_stored;
    }

    /**
     * Selisih bertanda untuk ditampilkan, mis. "+5" atau "-3".
     */
    public function signedDifference(): string
    {
        if (! $this->sudahDicacah()) {
            return '—';
        }

        return ($this->difference > 0 ? '+' : '').$this->difference;
    }

    public function opname(): BelongsTo
    {
        return $this->belongsTo(StockOpname::class, 'opname_id', 'opname_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id', 'item_id');
    }
}
