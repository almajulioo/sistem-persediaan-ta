<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `request_details` sudah salah nama dan salah arah foreign key sejak desain
 * ERD awal — ia bukan rincian permintaan unit (`requests`, milik sub sistem
 * Permintaan), melainkan rincian usulan pengadaan (`procurement_requests`,
 * milik sub sistem ini). Nama dan FK-nya diperbaiki di sini. Lihat ADR 0015.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('request_details', function (Blueprint $table) {
            $table->dropForeign(['request_id']);
        });

        Schema::rename('request_details', 'procurement_request_details');

        Schema::table('procurement_request_details', function (Blueprint $table) {
            $table->renameColumn('request_detail_id', 'procurement_request_detail_id');
            $table->renameColumn('request_id', 'procurement_request_id');
        });

        Schema::table('procurement_request_details', function (Blueprint $table) {
            $table->foreign('procurement_request_id')
                ->references('procurement_request_id')->on('procurement_requests')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('procurement_request_details', function (Blueprint $table) {
            $table->dropForeign(['procurement_request_id']);
        });

        Schema::table('procurement_request_details', function (Blueprint $table) {
            $table->renameColumn('procurement_request_detail_id', 'request_detail_id');
            $table->renameColumn('procurement_request_id', 'request_id');
        });

        Schema::rename('procurement_request_details', 'request_details');

        Schema::table('request_details', function (Blueprint $table) {
            $table->foreign('request_id')
                ->references('request_id')->on('requests')
                ->cascadeOnDelete();
        });
    }
};
