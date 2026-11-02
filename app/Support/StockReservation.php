<?php

namespace App\Support;

use App\Models\Item;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Stok yang ditahan permintaan barang yang masih berjalan.
 *
 * Stok fisik (`items.stock_current`) baru berkurang saat barang diserahkan,
 * lewat StockLedger. Sebelum itu, setiap permintaan aktif menahan jumlahnya
 * supaya permintaan berikutnya tidak mengklaim stok yang sama: permintaan
 * baru hanya boleh mengambil dari stok tersedia, yaitu stok fisik dikurangi
 * yang ditahan.
 *
 * Tahanan tidak disimpan, melainkan dihitung ulang dari permintaan setiap
 * kali dibutuhkan. Dengan begitu tahanan lepas sendiri saat permintaan
 * ditolak, dikurangi jumlahnya, dikembalikan statusnya, atau diserahkan, dan
 * tabel bersama `items` tidak perlu kolom baru.
 */
class StockReservation
{
    /**
     * Status yang menahan stok. Diserahkan dan selesai tidak ikut karena stok
     * fisiknya sudah berkurang; permintaan umum tidak ikut karena masih
     * antrean tinjauan Gudang, dan baru menahan setelah digabung menjadi
     * permintaan darurat.
     */
    public const HOLDING_STATUSES = ['submitted', 'approved_by_head', 'processed_by_staff', 'in_issuance'];

    /**
     * Jumlah yang ditahan per barang. Jumlah yang sudah diputuskan Gudang
     * (termasuk 0) memakai keputusannya, yang belum memakai jumlah diminta.
     *
     * @param  list<string>  $itemIds
     * @return Collection<string, int> item_id => jumlah
     */
    public static function reservedFor(array $itemIds, ?string $exceptRequestId = null): Collection
    {
        if ($itemIds === []) {
            return collect();
        }

        return DB::table('request_details')
            ->join('requests', 'requests.request_id', '=', 'request_details.request_id')
            ->whereIn('request_details.item_id', $itemIds)
            ->whereIn('requests.status', self::HOLDING_STATUSES)
            ->where('requests.type', '!=', 'umum')
            ->when($exceptRequestId !== null, fn ($query) => $query->where('requests.request_id', '!=', $exceptRequestId))
            ->groupBy('request_details.item_id')
            ->selectRaw('request_details.item_id, SUM(COALESCE(request_details.quantity_approved, request_details.quantity_requested)) as jumlah')
            ->pluck('jumlah', 'item_id')
            ->map(fn ($jumlah) => (int) $jumlah);
    }

    /**
     * Stok yang masih boleh diminta: stok fisik dikurangi tahanan permintaan
     * lain. `$exceptRequestId` mengecualikan permintaan yang sedang diproses
     * supaya tidak berebut dengan tahanannya sendiri.
     */
    public static function availableFor(Item $item, ?string $exceptRequestId = null): int
    {
        $reserved = self::reservedFor([$item->item_id], $exceptRequestId)->get($item->item_id, 0);

        return max(0, (int) $item->stock_current - $reserved);
    }
}
