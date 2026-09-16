<?php

namespace App\Models;

use App\Enums\SatuanBarang;
use App\Enums\StockStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Item extends Model
{
    /** @use HasFactory<\Database\Factories\ItemFactory> */
    use HasFactory, HasUuids;

    protected $primaryKey = 'item_id';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * `stock_current` sengaja tidak fillable: stok hanya boleh berubah lewat
     * App\Support\StockLedger agar setiap perubahan punya jejak mutasi.
     *
     * @var list<string>
     */
    protected $fillable = [
        'item_code', 'item_name', 'unit', 'stock_min', 'stock_max', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'unit' => SatuanBarang::class,
            'stock_current' => 'integer',
            'stock_min' => 'integer',
            'stock_max' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Relasi transaksional yang memakai onDelete restrict. Selama masih ada
     * isinya, barang tidak boleh dihapus dan sebaiknya diarsipkan saja.
     *
     * @var array<string, string>
     */
    public const RESTRICTED_RELATIONS = [
        'stockMutations' => 'riwayat mutasi stok',
        'opnameDetails' => 'catatan stock opname',
        'procurementDetails' => 'rincian pengadaan',
        'procurementRequestDetails' => 'rincian usulan pengadaan',
        'itemProposals' => 'usulan barang baru',
    ];

    /**
     * Nama relasi transaksional yang masih memiliki data untuk barang ini.
     *
     * @return array<int, string>
     */
    public function blockingRelations(): array
    {
        $blocking = [];

        foreach (self::RESTRICTED_RELATIONS as $relation => $label) {
            if ($this->{$relation}()->exists()) {
                $blocking[] = $label;
            }
        }

        return $blocking;
    }

    /**
     * Status kecukupan stok. `stock_max` bernilai 0 berarti tanpa batas atas.
     */
    public function stockStatus(): StockStatus
    {
        return match (true) {
            $this->stock_current <= 0 => StockStatus::Habis,
            $this->stock_current <= $this->stock_min => StockStatus::Menipis,
            $this->stock_max > 0 && $this->stock_current > $this->stock_max => StockStatus::Berlebih,
            default => StockStatus::Aman,
        };
    }

    /**
     * Stok sudah menyentuh atau melewati batas minimum — gabungan
     * StockStatus::Menipis dan StockStatus::Habis. Dipakai
     * App\Support\ProcurementDraftScanner untuk menentukan barang yang perlu
     * masuk draf usulan pengadaan (ADR 0027).
     */
    public function diBawahMinimum(): bool
    {
        return $this->stock_current <= $this->stock_min;
    }

    public function scopeDiBawahMinimum(Builder $query): Builder
    {
        return $query->whereColumn('stock_current', '<=', 'stock_min');
    }

    /**
     * Barang yang masih boleh dipakai pada transaksi baru.
     */
    public function scopeAktif(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Pencarian bebas pada kode dan nama barang.
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        return $query->where(function (Builder $query) use ($term) {
            $query->where('item_code', 'like', '%'.$term.'%')
                ->orWhere('item_name', 'like', '%'.$term.'%');
        });
    }

    /**
     * Menyaring berdasarkan status stok yang dihitung, bukan kolom database.
     */
    public function scopeStockStatus(Builder $query, StockStatus $status): Builder
    {
        return match ($status) {
            StockStatus::Habis => $query->where('stock_current', '<=', 0),
            StockStatus::Menipis => $query->where('stock_current', '>', 0)
                ->whereColumn('stock_current', '<=', 'stock_min'),
            StockStatus::Berlebih => $query->where('stock_max', '>', 0)
                ->whereColumn('stock_current', '>', 'stock_max'),
            StockStatus::Aman => $query->where('stock_current', '>', 0)
                ->whereColumn('stock_current', '>', 'stock_min')
                ->where(function (Builder $query) {
                    $query->where('stock_max', '<=', 0)
                        ->orWhereColumn('stock_current', '<=', 'stock_max');
                }),
        };
    }

    public function opnameDetails(): HasMany
    {
        return $this->hasMany(OpnameDetail::class, 'item_id', 'item_id');
    }

    public function procurementDetails(): HasMany
    {
        return $this->hasMany(ProcurementDetail::class, 'item_id', 'item_id');
    }

    public function procurementRequestDetails(): HasMany
    {
        return $this->hasMany(ProcurementRequestDetail::class, 'item_id', 'item_id');
    }

    public function stockMutations(): HasMany
    {
        return $this->hasMany(StockMutation::class, 'item_id', 'item_id');
    }

    /**
     * Usulan barang baru yang menghasilkan baris ini (ADR 0032). Dokumen
     * riwayat, jadi FK-nya restrictOnDelete seperti relasi transaksional
     * lainnya — barang yang pernah diusulkan diarsipkan, tidak dihapus.
     */
    public function itemProposals(): HasMany
    {
        return $this->hasMany(ItemProposal::class, 'item_id', 'item_id');
    }
}
