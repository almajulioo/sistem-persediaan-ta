<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Berkas pendukung sebuah pengadaan: BAST bertanda tangan hasil pindai, dan
 * SPK/kontrak/surat pesanan.
 *
 * Menggantikan dua rancangan lama yang sama-sama tidak memadai: kolom string
 * `procurements.additional_doc` dan `procurement_receipts.file_url`. Keduanya
 * hanya menampung satu berkas, tanpa jenis, tanpa nama asli, dan tanpa jejak
 * siapa yang mengunggah.
 *
 * `file_path` menunjuk berkas pada disk **privat** (`storage/app/private`),
 * bukan disk publik. Kontrak dan BAST bertanda tangan tidak boleh terunduh
 * oleh siapa pun yang menebak URL-nya; unduhan dilayani rute terautentikasi.
 * Lihat ADR 0018.
 *
 * `type` sengaja string biasa, bukan kolom enum basis data: menambah jenis
 * dokumen baru kelak cukup menambah case pada ProcurementDocumentType tanpa
 * migrasi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('procurement_documents', function (Blueprint $table) {
            $table->uuid('procurement_document_id')->primary();

            $table->foreignUuid('procurement_id')
                ->constrained('procurements', 'procurement_id')
                ->cascadeOnDelete();

            $table->string('type');
            $table->string('file_path');
            $table->string('original_name');
            $table->unsignedInteger('file_size');
            $table->string('mime_type');

            $table->foreignUuid('uploaded_by')
                ->constrained('users', 'user_id')
                ->restrictOnDelete();

            $table->text('notes')->nullable();

            $table->timestamps();

            // Pertanyaan yang paling sering ditanyakan ke tabel ini: "sudah ada
            // BAST-nya belum?" — dipakai notifikasi BastBelumDiunggah.
            $table->index(['procurement_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('procurement_documents');
    }
};
