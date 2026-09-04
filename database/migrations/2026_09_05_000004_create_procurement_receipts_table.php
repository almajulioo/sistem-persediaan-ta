<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BAST penerimaan barang dari penyedia ke gudang. Sebelumnya cuma kolom
 * string bebas (`procurements.bast_scan`) tanpa nomor dokumen maupun siapa
 * yang mengonfirmasi — tidak cukup untuk sistem yang dipakai lembaga audit.
 *
 * `procurement_id` unik: versi pertama mengasumsikan satu penerimaan per
 * pengadaan (tidak bertahap). Kalau kelak penerimaan barang perlu dicatat
 * bertahap, kolom unique-nya tinggal dilepas — struktur tabelnya sudah
 * mendukung tanpa desain ulang.
 *
 * `received_by` sengaja terpisah dari `procurements.user_id` (pengaju
 * realisasi): siapa yang mengadakan bukan siapa yang mengonfirmasi diterima,
 * sesuai pemisahan tugas pada ADR 0008. Lihat ADR 0015.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('procurement_receipts', function (Blueprint $table) {
            $table->uuid('procurement_receipt_id')->primary();

            $table->foreignUuid('procurement_id')
                ->unique()
                ->constrained('procurements', 'procurement_id')
                ->restrictOnDelete();

            $table->string('bast_number');
            $table->string('file_url');

            $table->foreignUuid('received_by')
                ->constrained('users', 'user_id')
                ->restrictOnDelete();

            $table->timestamp('received_at');
            $table->text('notes')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('procurement_receipts');
    }
};
