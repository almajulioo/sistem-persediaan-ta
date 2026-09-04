<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `request_id` di sini tidak pernah menjadi foreign key yang disengaja —
 * peninggalan kebingungan penamaan primary key saat desain ERD awal.
 * `procurement_request_id` sebagai PK sudah benar sejak semula.
 *
 * `request_type` diganti `source`: usulan pengadaan punya tiga asal-usul
 * berbeda (dikonfirmasi narasumber lapangan), bukan satu jenis "manual"
 * generik. Dipasangkan `reference_type`/`reference_id` polimorfik — pola yang
 * sama dengan `stock_mutations.reference_*` (ADR 0007) — supaya usulan yang
 * lahir dari permintaan unit (App\Models\Request, milik sub sistem Permintaan)
 * bisa ditelusuri balik tanpa memaksa modul ini menunggu sub sistem itu
 * dibangun lebih dulu: kolomnya nullable, diisi hanya ketika
 * `source = ProcurementRequestSource::DariPermintaan`. Lihat ADR 0015.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('procurement_requests', function (Blueprint $table) {
            $table->dropForeign(['request_id']);
            $table->dropColumn(['request_id', 'request_type']);

            $table->string('source')->after('procurement_request_id');
            $table->string('reference_type')->nullable()->after('source');
            $table->uuid('reference_id')->nullable()->after('reference_type');
            $table->string('status')->default('diajukan')->after('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('procurement_requests', function (Blueprint $table) {
            $table->dropColumn(['source', 'reference_type', 'reference_id', 'status']);

            $table->foreignUuid('request_id')
                ->nullable()
                ->after('procurement_request_id')
                ->constrained('requests', 'request_id')
                ->cascadeOnDelete();
            $table->string('request_type')->nullable()->after('request_id');
        });
    }
};
