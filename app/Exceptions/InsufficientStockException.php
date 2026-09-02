<?php

namespace App\Exceptions;

use App\Models\Item;
use RuntimeException;

/**
 * Dilempar ketika sebuah mutasi akan membuat stok barang menjadi negatif.
 *
 * Kolom `items.stock_current` bertipe unsigned, jadi kondisi ini harus
 * ditangkap di level aplikasi supaya pengguna menerima pesan yang jelas,
 * bukan galat SQL.
 */
class InsufficientStockException extends RuntimeException
{
    public function __construct(
        public readonly Item $item,
        public readonly int $requested,
        public readonly int $available,
    ) {
        parent::__construct(sprintf(
            'Stok %s tidak mencukupi. Diminta %d %s, tersedia %d %s.',
            $item->item_name,
            $requested,
            $item->unit->label(),
            $available,
            $item->unit->label(),
        ));
    }
}
