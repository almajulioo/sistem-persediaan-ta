<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tanda tangan elektronik (TTE) pada BAST pengadaan — satu baris untuk satu
 * kali menandatangani (ADR 0051).
 *
 * - Identitas penanda tangan **disalin** saat TTE (nama, NIP, jabatan): yang
 *   tercetak dan yang tampil di halaman verifikasi tidak boleh berubah hanya
 *   karena data akunnya diperbarui kemudian.
 * - `verification_code` disimpan apa adanya, bukan di-hash, supaya BAST bisa
 *   dirender ulang kapan saja dengan QR yang sama.
 * - Baris yang dicabut tidak dihapus: QR pada cetakan lama harus tetap bisa
 *   menjelaskan bahwa tanda tangannya tidak berlaku lagi dan sebabnya.
 *   Karena itu "satu TTE aktif per pihak" dijaga kode di bawah kunci, bukan
 *   indeks unik.
 * - `signed_at` diberi DEFAULT eksplisit: tanpa itu MariaDB 10.4 memasang
 *   ON UPDATE CURRENT_TIMESTAMP, dan mencabut TTE akan menggeser waktu
 *   tanda tangannya (lihat migrasi 2026_10_24_000003).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('procurement_receipt_signatures', function (Blueprint $table) {
            $table->uuid('signature_id')->primary();

            $table->foreignUuid('procurement_receipt_id')
                ->constrained('procurement_receipts', 'procurement_receipt_id')
                ->restrictOnDelete();

            $table->string('party', 20);

            $table->foreignUuid('user_id')
                ->constrained('users', 'user_id')
                ->restrictOnDelete();

            $table->string('signer_name');
            $table->string('signer_nip', 30)->nullable();
            $table->string('signer_position')->nullable();

            $table->char('content_hash', 64);
            $table->string('verification_code', 40)->unique();

            $table->timestamp('signed_at')->useCurrent();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoke_reason')->nullable();

            $table->timestamps();

            // Nama eksplisit: nama bawaan Laravel di sini 65 karakter, lewat
            // batas 64 MySQL. SQLite (Pest) tidak membatasinya.
            $table->index(['procurement_receipt_id', 'party'], 'bast_ttd_receipt_party_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('procurement_receipt_signatures');
    }
};
