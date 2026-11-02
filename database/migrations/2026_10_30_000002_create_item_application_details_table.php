<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_application_details', function (Blueprint $table) {
            $table->id('item_application_detail_id');
            $table->foreignUuid('item_application_id')
                ->constrained('item_applications', 'item_application_id')
                ->cascadeOnDelete();
            // tambah_stok: item_id terisi. barang_baru: item_name dan unit
            // terisi, item_id kosong karena barangnya belum ada di master.
            $table->string('type');
            $table->foreignUuid('item_id')
                ->nullable()
                ->constrained('items', 'item_id')
                ->restrictOnDelete();
            $table->string('item_name')->nullable();
            $table->string('unit')->nullable();
            $table->text('note')->nullable();
            $table->unsignedInteger('quantity');
            // Tindak lanjut, terisi saat permohonan disetujui: baris barang
            // baru menjadi satu usulan barang, baris tambah stok masuk ke draf
            // usulan pengadaan. Draf boleh dibuang Pengelola Gudang, jadi
            // tautannya ikut dikosongkan alih-alih menghalangi.
            $table->foreignUuid('item_proposal_id')
                ->nullable()
                ->constrained('item_proposals', 'item_proposal_id')
                ->restrictOnDelete();
            $table->foreignUuid('procurement_request_id')
                ->nullable()
                ->constrained('procurement_requests', 'procurement_request_id')
                ->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_application_details');
    }
};
