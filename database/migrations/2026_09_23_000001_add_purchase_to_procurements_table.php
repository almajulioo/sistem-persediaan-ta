<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fakta belanja pada realisasi pengadaan.
 *
 * Sampai ADR 0030 realisasi yang disetujui langsung berstatus
 * MenungguPenerimaan, padahal barangnya belum dibelanjakan — dan gudang sudah
 * ditagih menunggu barang yang belum dibeli. Sejak ADR 0035 persetujuan
 * membawa realisasi ke ProsesBelanja, dan Pejabat Pengadaan menandainya
 * sudah dibelanjakan beserta foto buktinya.
 *
 * Kolom ini yang menyimpan fakta itu, bukan statusnya: status berubah lagi
 * ketika realisasinya diubah dan harus disetujui ulang, sedangkan belanja
 * yang sudah terjadi tidak ikut batal karenanya. Pola yang sama dengan
 * `reviewed_*` dan `cancelled_*`.
 *
 * `purchased_at` bertipe tanggal, bukan timestamp: yang dicatat adalah hari
 * belanja di lapangan, yang boleh diisi mundur, bukan detik tombolnya
 * ditekan — detik itu sudah ada di `audit_logs`.
 *
 * Seluruhnya nullable dan tanpa pengisian ulang: realisasi yang disetujui
 * sebelum migrasi ini memang tidak pernah mencatat kapan dibelanjakan, dan
 * menebaknya justru mengarang data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('procurements', function (Blueprint $table) {
            $table->date('purchased_at')->nullable()->after('review_note');

            // Sejajar `reviewed_by` dan `cancelled_by`. Pada praktiknya hampir
            // selalu perealisasinya sendiri, tetapi Admin dapat menandai
            // sebagai pengganti — dan siapa yang menandai itulah yang dicatat.
            $table->foreignUuid('purchased_by')
                ->nullable()
                ->after('purchased_at')
                ->constrained('users', 'user_id')
                ->restrictOnDelete();

            $table->string('purchase_note', 500)->nullable()->after('purchased_by');
        });
    }

    public function down(): void
    {
        Schema::table('procurements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('purchased_by');
            $table->dropColumn(['purchased_at', 'purchase_note']);
        });
    }
};
