<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Melengkapi rincian opname dengan alasan selisih dan jejak posting.
     *
     * Empat angka pada satu baris rincian:
     *   stock_stored      stok tercatat saat draf dibuat
     *   stock_real        hasil cacah fisik
     *   difference        stock_real - stock_stored, selisih saat mencacah
     *   stock_at_posting  stok tercatat pada detik posting
     *   adjustment_amount mutasi yang benar-benar diterapkan
     *
     * stock_at_posting yang berbeda dari stock_stored berarti stok bergerak
     * setelah barang dicacah — penanda ini muncul di laporan. Lihat ADR 0010.
     */
    public function up(): void
    {
        Schema::table('opname_details', function (Blueprint $table) {
            // Draf menyimpan barang yang belum dicacah, jadi keduanya harus
            // boleh kosong. ERD asli menetapkannya wajib terisi.
            $table->unsignedInteger('stock_real')->nullable()->change();
            $table->integer('difference')->nullable()->change();

            $table->timestamp('counted_at')->nullable()->after('stock_real');
            $table->string('adjustment_reason')->nullable()->after('difference');
            $table->unsignedInteger('stock_at_posting')->nullable()->after('adjustment_note');
            $table->integer('adjustment_amount')->nullable()->after('stock_at_posting');
        });
    }

    public function down(): void
    {
        Schema::table('opname_details', function (Blueprint $table) {
            $table->dropColumn(['counted_at', 'adjustment_reason', 'stock_at_posting', 'adjustment_amount']);

            $table->unsignedInteger('stock_real')->nullable(false)->change();
            $table->integer('difference')->nullable(false)->change();
        });
    }
};
