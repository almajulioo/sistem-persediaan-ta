<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dasar pengadaan bagi realisasi yang tidak punya usulan.
 *
 * Pada realisasi dari usulan, alasan pengadaan sudah ada tempatnya: dokumen
 * usulannya sendiri, beserta pengaju dan `source`-nya. Kasubbag Umum dan TI
 * membukanya untuk menilai apakah belanja itu memang perlu. Realisasi langsung
 * tidak punya hulu itu, sehingga tanpa kolom ini layar persetujuan kehilangan
 * satu-satunya bahan penilaiannya dan persetujuan ADR 0030 merosot jadi tanda
 * tangan kosong. Lihat ADR 0031.
 *
 * Aturannya satu kalimat: alasan tidak pernah hilang dari sistem, ia hanya
 * pindah ke dokumen yang tidak punya hulu.
 *
 * `basis_reason` berpasangan dengan `basis_note` yang wajib diisi — enum
 * membuat dasar pengadaan dapat direkap sehingga kebiasaan memakai pintu
 * langsung terlihat angkanya, keterangan menangkap kekhususan tiap kasus. Pola
 * yang sama dengan `cancel_reason`/`cancel_note` (ADR 0024) dan
 * `opname_details.adjustment_reason` (ADR 0011). String biasa, bukan enum basis
 * data, supaya menambah alasan cukup menambah case pada AlasanPengadaan.
 *
 * Keduanya nullable di basis data: NULL berarti "dasarnya adalah usulannya".
 * Kewajibannya ditegakkan di form request, tempat keberadaan usulan diketahui.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('procurements', function (Blueprint $table) {
            $table->string('basis_reason')->nullable()->after('procurement_date');
            $table->text('basis_note')->nullable()->after('basis_reason');
        });
    }

    public function down(): void
    {
        Schema::table('procurements', function (Blueprint $table) {
            $table->dropColumn(['basis_reason', 'basis_note']);
        });
    }
};
