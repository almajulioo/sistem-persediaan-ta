<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Realisasi pengadaan boleh berdiri tanpa usulan.
 *
 * Permintaan langsung Kasubbag Umum dan TI: Pejabat Pengadaan perlu dapat
 * membuat realisasi sendiri, tanpa menunggu usulan dari Pengelola Gudang.
 * Lihat ADR 0031.
 *
 * **Indeks uniknya sengaja tidak dibuang.** MySQL maupun SQLite mengizinkan
 * banyak baris NULL pada indeks unik, sehingga satu perubahan ini melayani dua
 * kebutuhan sekaligus: realisasi langsung boleh sebanyak apa pun, sementara
 * invarian "satu usulan paling banyak satu realisasi" — dasar ADR 0021 dan
 * ADR 0024 — tetap dijaga basis data, bukan kode.
 *
 * ADR 0015 dulu menolak kolom ini nullable demi ketertelusuran. Itu tidak
 * dianulir, hanya dipindah tempatnya: realisasi tanpa usulan wajib membawa
 * dasarnya sendiri lewat `basis_reason`/`basis_note` pada migrasi berikutnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('procurements', function (Blueprint $table) {
            // FK-nya tidak perlu dilepas lebih dulu: yang berubah cuma
            // nullability, bukan tipe kolomnya. MySQL menerima `modify` pada
            // kolom ber-FK, dan SQLite menangani `change()` dengan membangun
            // ulang tabel beserta seluruh constraint-nya.
            $table->uuid('procurement_request_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('procurements', function (Blueprint $table) {
            $table->uuid('procurement_request_id')->nullable(false)->change();
        });
    }
};
