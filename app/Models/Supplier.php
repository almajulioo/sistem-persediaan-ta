<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Penyedia barang pengadaan.
 *
 * `address` wajib diisi karena BAST memuatnya; kolom kontak lainnya boleh
 * menyusul sesuai apa yang diketahui saat penyedia didaftarkan.
 */
class Supplier extends Model
{
    /** @use HasFactory<\Database\Factories\SupplierFactory> */
    use HasFactory, HasUuids;

    protected $primaryKey = 'supplier_id';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'supplier_name', 'address', 'phone', 'email', 'contact_person', 'npwp', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * Relasi transaksional yang memakai onDelete restrict. Selama masih ada
     * isinya, penyedia tidak boleh dihapus dan sebaiknya diarsipkan saja.
     *
     * @var array<string, string>
     */
    public const RESTRICTED_RELATIONS = [
        'procurements' => 'realisasi pengadaan',
    ];

    /**
     * Nama relasi transaksional yang masih memiliki data untuk penyedia ini.
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
     * Penyedia yang masih boleh dipakai pada realisasi baru.
     */
    public function scopeAktif(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Pencarian bebas pada nama penyedia dan narahubungnya.
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        return $query->where(function (Builder $query) use ($term) {
            $query->where('supplier_name', 'like', '%'.$term.'%')
                ->orWhere('contact_person', 'like', '%'.$term.'%');
        });
    }

    public function procurements(): HasMany
    {
        return $this->hasMany(Procurement::class, 'supplier_id', 'supplier_id');
    }
}
