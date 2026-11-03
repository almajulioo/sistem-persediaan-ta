<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sub kategori barang persediaan, mis. "ALAT TULIS" atau "KABEL LISTRIK".
 *
 * Kategorinya (ATK / ARTK) cukup dua nilai tetap, jadi disimpan sebagai kolom
 * enum alih-alih tabel sendiri. `subcategory_code` mengikuti kodefikasi barang
 * persediaan: sepuluh digit pertama kode barang adalah kode sub kategorinya.
 * Lihat ADR 0043.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_subcategories', function (Blueprint $table) {
            $table->uuid('subcategory_id')->primary();
            $table->string('subcategory_code')->unique();
            $table->string('subcategory_name');
            $table->enum('category', ['ATK', 'ARTK']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_subcategories');
    }
};
