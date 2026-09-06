<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dua kolom yang menentukan apakah modul ini dipercaya atau ditinggalkan.
 *
 * **`amount_received`** — sebelumnya `amount` adalah satu-satunya kolom jumlah,
 * sehingga stok bertambah sebanyak yang *dipesan*. Padahal penyedia sering
 * mengirim kurang. Tanpa tempat mencatat "pesan 100, datang 80", petugas hanya
 * punya pilihan buruk: tidak mengonfirmasi sama sekali, mengonfirmasi angka
 * yang salah, atau menambal lewat mutasi koreksi manual yang jejaknya pecah
 * dua dokumen. Kolom ini membuat penerimaan boleh jujur. Nullable karena baru
 * terisi saat penerimaan dikonfirmasi. Lihat ADR 0017.
 *
 * **`unit_price`** — harga satuan saat realisasi, dalam rupiah bulat. Sengaja
 * integer, bukan decimal: rupiah tidak punya satuan pecahan yang dipakai, dan
 * integer menutup seluruh kelas galat pembulatan floating point.
 *
 * Harga di sini **bukan** penilaian persediaan. Nilai persediaan per barang
 * membutuhkan metode costing (FIFO/rata-rata bergerak) yang akan menyentuh
 * StockLedger — kontrak yang dipakai bersama sub sistem Permintaan. Itu di
 * luar cakupan; lihat ADR 0019.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('procurement_details', function (Blueprint $table) {
            $table->unsignedBigInteger('unit_price')->after('amount');
            $table->unsignedInteger('amount_received')->nullable()->after('unit_price');
        });
    }

    public function down(): void
    {
        Schema::table('procurement_details', function (Blueprint $table) {
            $table->dropColumn(['unit_price', 'amount_received']);
        });
    }
};
