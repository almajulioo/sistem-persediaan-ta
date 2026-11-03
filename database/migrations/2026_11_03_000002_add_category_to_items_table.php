<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kategori dan sub kategori pada `items` (tabel bersama).
 *
 * `category` adalah salinan kategori sub kategorinya, diisi App\Models\Item
 * saat disimpan — tidak pernah dipilih sendiri di formulir. Kedua kolom
 * nullable semata supaya migrasi ini aman dijalankan di basis data yang sudah
 * berisi barang; formulir barang tetap mewajibkan sub kategori. Lihat ADR 0043.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->enum('category', ['ATK', 'ARTK'])->nullable()->after('item_name');
            $table->foreignUuid('subcategory_id')->nullable()->after('category')
                ->constrained('item_subcategories', 'subcategory_id')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropForeign(['subcategory_id']);
            $table->dropColumn(['subcategory_id', 'category']);
        });
    }
};
