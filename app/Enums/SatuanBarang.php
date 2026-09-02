<?php

namespace App\Enums;

/**
 * Satuan barang persediaan.
 *
 * Daftar tetap dipakai agar rekap laporan tidak terpecah karena penulisan
 * yang berbeda-beda ("Buah" / "buah" / "bh"). Menambah satuan baru cukup
 * dengan menambahkan satu case di sini.
 */
enum SatuanBarang: string
{
    case Buah = 'buah';
    case Rim = 'rim';
    case Box = 'box';
    case Pak = 'pak';
    case Dus = 'dus';
    case Lusin = 'lusin';
    case Unit = 'unit';
    case Set = 'set';
    case Botol = 'botol';
    case Kilogram = 'kilogram';
    case Liter = 'liter';
    case Lembar = 'lembar';

    public function label(): string
    {
        return match ($this) {
            self::Buah => 'Buah',
            self::Rim => 'Rim',
            self::Box => 'Box',
            self::Pak => 'Pak',
            self::Dus => 'Dus',
            self::Lusin => 'Lusin',
            self::Unit => 'Unit',
            self::Set => 'Set',
            self::Botol => 'Botol',
            self::Kilogram => 'Kilogram',
            self::Liter => 'Liter',
            self::Lembar => 'Lembar',
        };
    }

    /**
     * @return array<string, string> nilai => label, untuk dropdown form.
     */
    public static function options(): array
    {
        return array_reduce(
            self::cases(),
            fn (array $carry, self $satuan) => $carry + [$satuan->value => $satuan->label()],
            [],
        );
    }
}
