<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Seluruh foreign key ke `items` memakai restrictOnDelete, sehingga barang
     * yang sudah bertransaksi tidak dapat dihapus. Kolom ini dipakai untuk
     * mengarsipkan barang tersebut tanpa merusak riwayat.
     */
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('stock_max');
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};
