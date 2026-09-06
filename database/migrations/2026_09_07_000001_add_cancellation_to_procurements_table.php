<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jalan keluar bagi realisasi yang tidak akan pernah sampai ke penerimaan.
 *
 * `ProcurementStatus::Dibatalkan` sudah ada sejak Tahap 1 tetapi tidak pernah
 * punya pemicu, sehingga realisasi yang penyedianya gagal mengirim terkunci
 * di MenungguPenerimaan selamanya — dan satu-satunya jalan keluar yang
 * tersedia adalah mengonfirmasi penerimaan barang yang tidak pernah datang.
 * Lihat ADR 0024.
 *
 * `cancel_reason` berpasangan dengan `cancel_note` yang wajib diisi: enum
 * membuat pembatalan dapat direkap, keterangan menangkap kekhususan tiap
 * kasus — pola yang sama dengan `opname_details.adjustment_reason`
 * (ADR 0011). Kolomnya string biasa, bukan enum basis data, supaya menambah
 * alasan baru cukup menambah case pada AlasanPembatalan tanpa migrasi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('procurements', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable()->after('status');

            // Sejajar `procurement_receipts.received_by`: siapa yang
            // membatalkan berbeda dari siapa yang merealisasi, walau pada
            // praktiknya sering orang yang sama.
            $table->foreignUuid('cancelled_by')
                ->nullable()
                ->after('cancelled_at')
                ->constrained('users', 'user_id')
                ->restrictOnDelete();

            $table->string('cancel_reason')->nullable()->after('cancelled_by');
            $table->text('cancel_note')->nullable()->after('cancel_reason');
        });
    }

    public function down(): void
    {
        Schema::table('procurements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['cancelled_at', 'cancel_reason', 'cancel_note']);
        });
    }
};
