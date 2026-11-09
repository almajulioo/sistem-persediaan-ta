<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jumlah hasil pemeriksaan gudang, yang belum menjadi stok.
 *
 * Sejak BAST ditandatangani elektronik (ADR 0051), Pengelola Gudang mencatat
 * jumlah yang datang lebih dulu, lalu stok baru bertambah setelah kedua pihak
 * membubuhkan TTE. Angka itu butuh tempat sendiri: `amount_received` tetap
 * berarti "sudah masuk stok", karena laporan, dasbor, dan kolom "Diterima"
 * membacanya dengan makna itu. Saat posting, nilainya disalin ke
 * `amount_received`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('procurement_details', function (Blueprint $table) {
            $table->unsignedInteger('amount_checked')->nullable()->after('amount_received');
        });
    }

    public function down(): void
    {
        Schema::table('procurement_details', function (Blueprint $table) {
            $table->dropColumn('amount_checked');
        });
    }
};
