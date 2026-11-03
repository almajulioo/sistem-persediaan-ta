<?php

namespace App\Models;

use App\Enums\KategoriBarang;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Sub kategori barang persediaan. Kategorinya (ATK / ARTK) ikut disalin ke
 * setiap barang di bawahnya; lihat ADR 0043.
 */
class ItemSubcategory extends Model
{
    /** @use HasFactory<\Database\Factories\ItemSubcategoryFactory> */
    use HasFactory, HasUuids;

    protected $primaryKey = 'subcategory_id';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'subcategory_code', 'subcategory_name', 'category',
    ];

    protected function casts(): array
    {
        return [
            'category' => KategoriBarang::class,
        ];
    }

    /**
     * Relasi yang memakai onDelete restrict. Selama masih ada isinya, sub
     * kategori tidak boleh dihapus.
     *
     * @var array<string, string>
     */
    public const RESTRICTED_RELATIONS = [
        'items' => 'barang',
    ];

    protected static function booted(): void
    {
        // `items.category` adalah salinan kolom ini. Mengubah kategori sub
        // kategori harus ikut memindahkan seluruh barangnya, kalau tidak
        // keduanya akan berbeda.
        static::updated(function (ItemSubcategory $subcategory) {
            if ($subcategory->wasChanged('category')) {
                Item::where('subcategory_id', $subcategory->subcategory_id)
                    ->update(['category' => $subcategory->category->value]);
            }
        });
    }

    /**
     * Nama relasi yang masih memiliki data untuk sub kategori ini.
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
     * Pencarian bebas pada kode dan nama sub kategori.
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        return $query->where(function (Builder $query) use ($term) {
            $query->where('subcategory_code', 'like', '%'.$term.'%')
                ->orWhere('subcategory_name', 'like', '%'.$term.'%');
        });
    }

    /**
     * Pilihan sub kategori untuk dropdown, dikelompokkan per kategori:
     * [label kategori => [subcategory_id => "kode — nama"]].
     *
     * @return array<string, array<string, string>>
     */
    public static function options(): array
    {
        $subcategories = self::orderBy('subcategory_code')->get()->groupBy(fn (self $row) => $row->category->value);
        $options = [];

        // Urutan kelompok mengikuti urutan case enum, bukan abjad.
        foreach (KategoriBarang::options() as $value => $label) {
            foreach ($subcategories->get($value, []) as $subcategory) {
                $options[$label][$subcategory->subcategory_id] = "{$subcategory->subcategory_code} — {$subcategory->subcategory_name}";
            }
        }

        return $options;
    }

    public function items(): HasMany
    {
        return $this->hasMany(Item::class, 'subcategory_id', 'subcategory_id');
    }
}
