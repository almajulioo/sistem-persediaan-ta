<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Data induk penyedia barang.
 *
 * Alasan utamanya bukan normalisasi, melainkan BAST: dokumen serah terima
 * memuat identitas penyedia, dan mengetik ulang alamat beserta narahubung pada
 * tiap dokumen persis friksi yang membuat orang enggan memakai sistem. Karena
 * itu `supplier_name` dan `address` wajib diisi, sisanya menyusul sesuai apa
 * yang diketahui saat penyedia didaftarkan.
 *
 * Sebelumnya penyedia hanya berupa kolom string bebas `procurements.supplier`,
 * yang membuat nama penyedia tidak konsisten dan tidak ada riwayat per
 * penyedia sama sekali.
 *
 * Mengikuti ADR 0003: penyedia yang sudah pernah bertransaksi diarsipkan lewat
 * `is_active`, tidak dihapus.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->uuid('supplier_id')->primary();

            $table->string('supplier_name');
            $table->string('address');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('contact_person')->nullable();
            $table->string('npwp')->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suppliers');
    }
};
