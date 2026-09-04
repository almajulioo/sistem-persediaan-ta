<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel notifikasi dalam aplikasi.
 *
 * Namanya sengaja `user_notifications`, bukan `notifications`. Model User
 * memakai trait Notifiable bawaan Laravel, yang mengklaim nama `notifications`
 * beserta bentuk kolomnya sendiri (`notifiable_type`, `notifiable_id`, `data`).
 * Memakai nama itu untuk skema yang berbeda akan membuat `$user->notifications`
 * meledak. Nama ini juga menyisakan tabel bawaan tetap bebas seandainya kelak
 * notifikasi surel dibangun di atas mekanisme Laravel.
 *
 * Satu baris = satu notifikasi untuk satu penerima (fan-out saat penulisan),
 * sehingga `read_at` bekerja per orang tanpa tabel pivot.
 *
 * Kunci rancangannya ada pada indeks unik (user_id, dedup_key): seluruh
 * penulisan bersifat idempoten, sehingga pemeriksaan boleh dijalankan berulang
 * kali — reaktif maupun terjadwal — tanpa melahirkan duplikat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_notifications', function (Blueprint $table) {
            $table->uuid('notification_id')->primary();

            // Notifikasi bukan jejak audit: ia ikut terhapus bersama akunnya dan
            // tidak boleh menghalangi penghapusan pengguna seperti audit_logs.
            $table->foreignUuid('user_id')->constrained('users', 'user_id')->cascadeOnDelete();

            $table->string('type');
            $table->string('level');
            $table->string('title');
            $table->text('body');

            // Tautan tindak lanjut; notifikasi tanpa tujuan hanyalah pengumuman.
            $table->string('action_url')->nullable();

            // Subjek polimorfik, dipakai untuk menutup notifikasi milik sebuah
            // dokumen atau barang secara borongan.
            $table->string('subject_type')->nullable();
            $table->uuid('subject_id')->nullable();

            $table->string('dedup_key');

            $table->timestamp('read_at')->nullable();

            // Terisi hanya untuk notifikasi bersifat keadaan, saat keadaannya
            // pulih. Peristiwa selamanya null.
            $table->timestamp('resolved_at')->nullable();

            $table->timestamps();

            $table->unique(['user_id', 'dedup_key']);
            $table->index(['user_id', 'resolved_at', 'read_at']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_notifications');
    }
};
