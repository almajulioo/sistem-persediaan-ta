<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * `quantity_approved` dulu memakai 0 untuk dua arti: "belum diputuskan" dan
 * "tidak diberikan". Sejak Pengelola Gudang boleh tidak memberikan satu
 * barang, keduanya dipisah: NULL berarti belum diputuskan, 0 berarti tidak
 * diberikan. Kolomnya sudah nullable sejak awal, jadi cukup datanya.
 *
 * Yang diubah hanya rincian permintaan yang belum melewati tahap Pengelola
 * Gudang; sesudah tahap itu, angka 0 memang keputusan.
 */
return new class extends Migration
{
    private const BELUM_DIPROSES = ['submitted', 'approved_by_head'];

    public function up(): void
    {
        DB::table('request_details')
            ->where('quantity_approved', 0)
            ->whereIn('request_id', function (Builder $query) {
                $query->select('request_id')
                    ->from('requests')
                    ->whereIn('status', [...self::BELUM_DIPROSES, 'merged'])
                    ->orWhere(fn (Builder $query) => $query
                        ->where('status', 'rejected')
                        ->where(fn (Builder $query) => $query
                            ->whereNull('rejected_from_status')
                            ->orWhereIn('rejected_from_status', self::BELUM_DIPROSES)));
            })
            ->update(['quantity_approved' => null]);
    }

    public function down(): void
    {
        DB::table('request_details')
            ->whereNull('quantity_approved')
            ->update(['quantity_approved' => 0]);
    }
};
