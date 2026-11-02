<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Permohonan barang baru dan tambah stok, milik sub sistem Permintaan.
 *
 * Menggantikan dua jalur otomatis lama dari formulir permintaan: selisih
 * jumlah diminta dengan stok yang langsung masuk draf usulan pengadaan, dan
 * barang belum terdaftar yang langsung menjadi usulan barang baru. Sekarang
 * keduanya diajukan sengaja lewat permohonan dan baru diteruskan setelah
 * disetujui Pengelola Gudang.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_applications', function (Blueprint $table) {
            $table->uuid('item_application_id')->primary();
            $table->string('application_number')->unique();
            // Akun diarsipkan, bukan dihapus (ADR 0003); permohonan adalah
            // dokumen yang harus tetap bisa ditelusuri ke pemohonnya.
            $table->foreignUuid('user_id')
                ->constrained('users', 'user_id')
                ->restrictOnDelete();
            $table->date('date');
            $table->text('purpose');
            $table->string('status')->default('diajukan')->index();
            $table->foreignUuid('decided_by')
                ->nullable()
                ->constrained('users', 'user_id')
                ->restrictOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_applications');
    }
};
