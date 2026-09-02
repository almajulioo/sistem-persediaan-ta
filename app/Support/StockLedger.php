<?php

namespace App\Support;

use App\Enums\MutationType;
use App\Exceptions\InsufficientStockException;
use App\Models\Item;
use App\Models\StockMutation;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Satu-satunya jalur perubahan stok barang.
 *
 * Modul pengadaan, permintaan barang, mutasi manual, dan stock opname semuanya
 * memanggil kelas ini, sehingga `items.stock_current` selalu sama dengan jumlah
 * seluruh baris di `stock_mutations` dan setiap perubahan punya jejak audit.
 */
class StockLedger
{
    /**
     * Catat satu mutasi dan sesuaikan stok barang.
     *
     * @param  int  $amount  Jumlah mutasi. Untuk Saldo Awal/Masuk/Keluar nilainya
     *                       harus positif; arah ditentukan jenis mutasi. Untuk
     *                       Penyesuaian, tanda nilai menentukan arah.
     *
     * @throws InsufficientStockException bila stok akhir menjadi negatif
     * @throws \InvalidArgumentException bila jumlah tidak masuk akal
     */
    public static function record(
        Item $item,
        MutationType $type,
        int $amount,
        ?string $reference = null,
        ?string $referenceType = null,
        ?CarbonInterface $date = null,
    ): StockMutation {
        $signed = self::signedAmount($type, $amount);

        return DB::transaction(function () use ($item, $type, $signed, $reference, $referenceType, $date) {
            // Kunci baris barang supaya dua transaksi bersamaan tidak saling
            // menimpa hasil perhitungan stok.
            $locked = Item::query()->lockForUpdate()->findOrFail($item->getKey());

            $newStock = $locked->stock_current + $signed;

            if ($newStock < 0) {
                throw new InsufficientStockException($locked, abs($signed), $locked->stock_current);
            }

            $mutation = StockMutation::create([
                'item_id' => $locked->item_id,
                'mutation_type' => $type,
                'amount' => $signed,
                'mutation_date' => $date ?? now(),
                'reference' => $reference,
                'reference_type' => $referenceType,
            ]);

            $locked->forceFill(['stock_current' => $newStock])->save();

            // Selaraskan instance yang dipegang pemanggil.
            $item->setAttribute('stock_current', $newStock);
            $item->syncOriginalAttribute('stock_current');

            return $mutation;
        });
    }

    /**
     * Ubah jumlah mentah menjadi nilai bertanda sesuai jenis mutasi.
     */
    private static function signedAmount(MutationType $type, int $amount): int
    {
        $direction = $type->direction();

        // Penyesuaian boleh naik maupun turun, jadi tandanya ikut nilai masukan.
        if ($direction === 0) {
            if ($amount === 0) {
                throw new \InvalidArgumentException('Jumlah penyesuaian tidak boleh nol.');
            }

            return $amount;
        }

        if ($amount <= 0) {
            throw new \InvalidArgumentException(
                sprintf('Jumlah mutasi %s harus lebih besar dari nol.', $type->label()),
            );
        }

        return $direction * $amount;
    }
}
