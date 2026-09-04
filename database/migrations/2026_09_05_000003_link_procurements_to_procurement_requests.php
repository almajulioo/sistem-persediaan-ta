<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Setiap realisasi pengadaan wajib bisa ditelusuri balik ke satu usulan yang
 * sudah disetujui — sejalan dengan prinsip pemisahan tugas yang sudah dipegang
 * sistem ini. Wajib diisi dan unik: satu usulan menghasilkan paling banyak
 * satu realisasi.
 *
 * `bast_scan` dibuang bersamaan: desain awalnya menyimpan BAST sebagai berkas
 * hasil pindai lewat kolom string bebas, tanpa nomor dokumen atau siapa yang
 * mengonfirmasi. Digantikan tabel `procurement_receipts` yang terstruktur.
 * Lihat ADR 0015.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('procurements', function (Blueprint $table) {
            $table->foreignUuid('procurement_request_id')
                ->after('procurement_id')
                ->unique()
                ->constrained('procurement_requests', 'procurement_request_id')
                ->restrictOnDelete();

            $table->dropColumn('bast_scan');
        });
    }

    public function down(): void
    {
        Schema::table('procurements', function (Blueprint $table) {
            $table->dropForeign(['procurement_request_id']);
            $table->dropColumn('procurement_request_id');

            $table->string('bast_scan')->nullable()->after('status');
        });
    }
};
