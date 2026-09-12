<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Draf usulan otomatis lahir tanpa pengaju manusia — dibuat oleh
 * ProcurementDraftScanner, baik dari pemicu reaktif di controller (ada
 * pengguna yang sedang login, tetapi ia tidak "mengajukan" apa pun) maupun
 * dari sapuan terjadwal `notifikasi:periksa` (tidak ada sesi pengguna sama
 * sekali). `user_id` baru terisi saat draf itu benar-benar diajukan lewat
 * ProcurementDraftController::finalize(). Lihat ADR 0027.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('procurement_requests', function (Blueprint $table) {
            $table->uuid('user_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Draf yang belum diajukan (user_id null) tidak punya pemilik untuk
        // diisikan kembali; dibuang di sini supaya perubahan ke NOT NULL di
        // bawah tidak gagal karena baris yang melanggarnya.
        DB::table('procurement_requests')->whereNull('user_id')->delete();

        Schema::table('procurement_requests', function (Blueprint $table) {
            $table->uuid('user_id')->nullable(false)->change();
        });
    }
};
