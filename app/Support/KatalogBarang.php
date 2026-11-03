<?php

namespace App\Support;

use App\Models\Item;
use App\Models\ItemSubcategory;
use Illuminate\Support\Collection;

/**
 * Daftar barang aktif untuk pemilih barang di formulir sub sistem Permintaan
 * (`x-request.pemilih-barang`).
 *
 * Seluruh daftar dikirim sekali ke peramban lalu disaring di sana, jadi tiap
 * barisnya hanya memuat kolom yang dipakai pemilih: identitas, satuan, stok,
 * serta kategori dan sub kategori untuk penyaring (ADR 0043).
 */
class KatalogBarang
{
    /**
     * @return Collection<int, Item>
     */
    public static function barangAktif(): Collection
    {
        return Item::aktif()
            ->with('subcategory:subcategory_id,subcategory_code,subcategory_name')
            ->orderBy('item_name')
            ->get(['item_id', 'item_code', 'item_name', 'unit', 'stock_current', 'category', 'subcategory_id']);
    }

    /**
     * @return array<string, mixed>
     */
    public static function baris(Item $item): array
    {
        return [
            'item_id' => $item->item_id,
            'item_code' => $item->item_code,
            'item_name' => $item->item_name,
            'unit' => $item->unit?->value,
            'stock_current' => (int) $item->stock_current,
            'category' => $item->category?->value,
            'subcategory_id' => $item->subcategory_id,
            'subcategory_code' => $item->subcategory?->subcategory_code,
            'subcategory_name' => $item->subcategory?->subcategory_name,
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public static function untukPemilih(): Collection
    {
        return self::barangAktif()->map(fn (Item $item) => self::baris($item))->values();
    }

    /**
     * Sub kategori yang punya barang aktif, untuk penyaring tingkat kedua.
     * Pemilih mengelompokkannya di bawah kategori menurut kolom `category`
     * milik sub kategori itu sendiri.
     *
     * @return Collection<int, array{id: string, kode: string, nama: string, kategori: string}>
     */
    public static function subkategori(): Collection
    {
        return ItemSubcategory::query()
            ->whereHas('items', fn ($query) => $query->aktif())
            ->orderBy('subcategory_code')
            ->get(['subcategory_id', 'subcategory_code', 'subcategory_name', 'category'])
            ->map(fn (ItemSubcategory $subcategory) => [
                'id' => $subcategory->subcategory_id,
                'kode' => $subcategory->subcategory_code,
                'nama' => $subcategory->subcategory_name,
                'kategori' => $subcategory->category->value,
            ])
            ->values();
    }
}
