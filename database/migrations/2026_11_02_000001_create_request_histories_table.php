<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Riwayat dan catatan setiap langkah permintaan: siapa memutuskan apa, dari
 * status mana ke mana, dan alasannya. Inilah jalur balik dari petugas ke
 * pemohon — alasan penolakan, perubahan jumlah, dan pengembalian status
 * tercatat di sini dan ditampilkan di halaman permintaan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('request_histories', function (Blueprint $table) {
            $table->id('request_history_id');
            $table->foreignUuid('request_id')
                ->constrained('requests', 'request_id')
                ->cascadeOnDelete();
            // Akun boleh dihapus permanen oleh Admin; riwayatnya tetap
            // tersimpan tanpa nama pelaku alih-alih menghalangi penghapusan.
            $table->foreignUuid('user_id')
                ->nullable()
                ->constrained('users', 'user_id')
                ->nullOnDelete();
            $table->string('action');
            $table->string('from_status')->nullable();
            $table->string('to_status')->nullable();
            $table->text('note')->nullable();
            // Rincian perubahan jumlah per barang: [{item_name, before, after}].
            $table->json('quantity_changes')->nullable();
            $table->timestamps();

            $table->index(['request_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('request_histories');
    }
};
