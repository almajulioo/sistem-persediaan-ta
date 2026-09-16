<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Usulan barang baru — pintu depan katalog.
 *
 * Permintaan Kasubbag Umum dan TI: Pejabat Pengadaan perlu dapat mengadakan
 * barang yang belum ada di master barang. Secara teknis barangnya harus jadi
 * baris `items` lebih dulu — stok tidak bisa diposting ke sebuah nama bebas,
 * karena `StockLedger::record()` menuntut sebuah Item dan
 * `procurement_details.item_id` adalah FK NOT NULL. Sementara kode barang,
 * satuan, dan batas minimum/maksimum adalah wewenang Pengelola Gudang, dan
 * pemeriksaan duplikat olehnya justru bagian paling bernilai dari alurnya.
 * Lihat ADR 0032.
 *
 * Tabel tersendiri, **bukan kolom tambahan pada `items`**: `items` adalah tabel
 * bersama dengan Alma, dan baris barang yang belum diverifikasi akan menyeret
 * `Item::aktif()`, snapshot stok opname, ProcurementDraftScanner, serta
 * NotificationScanner::periksaBarang ikut diaudit.
 *
 * Bentuknya sengaja **netral peran**: pengusulnya cukup seorang `users`, dan
 * perannya sendiri sudah menjelaskan asal usulannya. Dengan begitu sub sistem
 * permintaan barang dapat memakai tabel yang sama kelak tanpa perubahan skema —
 * kebutuhan "pengguna ingin meminta barang yang belum ada" adalah kebutuhan yang
 * sama dari pintu yang berbeda. Perlu disepakati dengan Alma bila ia memakainya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_proposals', function (Blueprint $table) {
            $table->uuid('item_proposal_id')->primary();

            // Nama bebas: yang mengusulkan belum tentu tahu penamaan baku di
            // master barang. Satuan tetap diminta karena pengusul justru tahu
            // satuan barang yang dibutuhkannya.
            $table->string('item_name');
            $table->string('unit');

            // Kegunaannya, wajib. Tanpa ini Pengelola Gudang tidak punya bahan
            // untuk memutuskan apakah barangnya benar-benar baru atau hanya
            // nama lain dari barang yang sudah ada.
            $table->text('purpose_note');

            $table->foreignUuid('proposed_by')
                ->constrained('users', 'user_id')
                ->restrictOnDelete();

            $table->string('status')->default('diajukan');

            // Terisi saat Pengelola Gudang membuat barangnya — menyetujui
            // usulan ini berarti mendaftarkan barangnya, tidak ada persetujuan
            // yang berdiri sendiri tanpa baris `items`.
            $table->foreignUuid('item_id')
                ->nullable()
                ->constrained('items', 'item_id')
                ->restrictOnDelete();

            $table->foreignUuid('decided_by')
                ->nullable()
                ->constrained('users', 'user_id')
                ->restrictOnDelete();
            $table->timestamp('decided_at')->nullable();

            // Wajib diisi saat menolak, kosong saat menyetujui — ditegakkan di
            // form request, bukan di basis data, sejajar pola `review_note`.
            $table->text('decision_note')->nullable();

            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_proposals');
    }
};
