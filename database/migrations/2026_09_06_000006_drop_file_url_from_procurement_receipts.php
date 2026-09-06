<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Berkas BAST bertanda tangan pindah ke `procurement_documents`.
 *
 * Selain menyatukan seluruh unggahan pada satu mekanisme, ini memperbaiki
 * kesalahan urutan yang tersembunyi di kolom lama: `file_url` bersifat NOT
 * NULL, sehingga penerimaan tidak dapat dikonfirmasi sebelum berkasnya ada.
 * Padahal urutan fisiknya justru sebaliknya — cetak BAST, tanda tangan, baru
 * pindai. Mensyaratkan berkas lebih dulu berarti memblokir pencatatan stok di
 * belakang alur pemindaian, dan yang terjadi di lapangan adalah orang mencari
 * jalan pintas.
 *
 * Sekarang: penerimaan dikonfirmasi → stok langsung masuk → BAST dicetak →
 * ditandatangani → dipindai → diunggah menyusul, ditagih notifikasi keadaan
 * `BastBelumDiunggah` yang menutup diri sendiri. Lihat ADR 0018.
 *
 * `procurement_receipts` kini murni mencatat **peristiwa** penerimaan: siapa
 * yang menerima, kapan, nomor BAST berapa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('procurement_receipts', function (Blueprint $table) {
            $table->dropColumn('file_url');
        });
    }

    public function down(): void
    {
        Schema::table('procurement_receipts', function (Blueprint $table) {
            $table->string('file_url');
        });
    }
};
