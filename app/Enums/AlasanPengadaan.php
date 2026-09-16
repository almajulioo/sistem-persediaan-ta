<?php

namespace App\Enums;

/**
 * Dasar dibuatnya sebuah realisasi pengadaan **tanpa usulan**, disimpan pada
 * `procurements.basis_reason`.
 *
 * Selalu dipasangkan dengan keterangan bebas yang wajib diisi: enum membuat
 * dasar pengadaan dapat direkap, keterangan menangkap kekhususan tiap kasus —
 * pola yang sama dengan AlasanPembatalan (ADR 0024) dan AlasanSelisih pada stok
 * opname (ADR 0011).
 *
 * Kosong (NULL) pada realisasi yang lahir dari usulan: di situ alasannya sudah
 * ada tempatnya sendiri, yaitu dokumen usulan beserta pengaju dan sumbernya.
 * Lihat ADR 0031.
 *
 * Nilainya bukan hiasan. Justru rekap atas enum inilah penangkal risiko utama
 * ADR 0031 — pintu langsung berubah menjadi kebiasaan sehingga usulan Pengelola
 * Gudang terlewat. Kebiasaan itu hanya dapat ditegur bila angkanya terlihat.
 */
enum AlasanPengadaan: string
{
    case KebutuhanMendesak = 'kebutuhan_mendesak';
    case BarangBaru = 'barang_baru';
    case ArahanPimpinan = 'arahan_pimpinan';
    case BelanjaRutinNilaiKecil = 'belanja_rutin_nilai_kecil';
    case Lainnya = 'lainnya';

    public function label(): string
    {
        return match ($this) {
            self::KebutuhanMendesak => 'Kebutuhan Mendesak',
            self::BarangBaru => 'Barang Baru',
            self::ArahanPimpinan => 'Arahan Pimpinan',
            self::BelanjaRutinNilaiKecil => 'Belanja Rutin Nilai Kecil',
            self::Lainnya => 'Lainnya',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::KebutuhanMendesak => 'Barang dibutuhkan segera sehingga tidak dapat menunggu siklus usulan dari Pengelola Gudang.',
            self::BarangBaru => 'Barang belum pernah diadakan sebelumnya, sehingga belum muncul pada pemantauan stok.',
            self::ArahanPimpinan => 'Pengadaan dilakukan atas arahan pimpinan di luar usulan Pengelola Gudang.',
            self::BelanjaRutinNilaiKecil => 'Belanja rutin bernilai kecil yang lazim dilakukan tanpa usulan tersendiri.',
            self::Lainnya => 'Dasar lain yang dijelaskan pada keterangan.',
        };
    }

    /**
     * @return array<string, string> nilai => label, untuk dropdown.
     */
    public static function options(): array
    {
        return array_reduce(
            self::cases(),
            fn (array $carry, self $reason) => $carry + [$reason->value => $reason->label()],
            [],
        );
    }
}
