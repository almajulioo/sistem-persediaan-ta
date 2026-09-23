<?php

namespace App\Support;

use App\Models\Procurement;
use App\Models\ProcurementDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Satu-satunya tempat berkas pengadaan disimpan, dibaca, dan dibuang.
 *
 * Dipusatkan di sini — bukan disebar di controller — supaya konvensi path,
 * penamaan berkas, dan pemilihan disk hanya ditulis sekali. Perubahan tempat
 * penyimpanan kelak (misalnya ke S3) cukup menyentuh berkas ini.
 *
 * Diskinya **privat** (`storage/app/private`), bukan `public`: isi berkasnya
 * adalah kontrak, harga, dan dokumen bertanda tangan milik lembaga pemeriksa
 * keuangan, yang tidak boleh terunduh oleh siapa pun yang menebak URL-nya.
 * Karena itu tidak ada URL berkas sama sekali; unduhan selalu lewat rute
 * terautentikasi. Lihat ADR 0018.
 */
class DocumentStorage
{
    /** Disk privat bawaan Laravel: storage/app/private. */
    public const DISK = 'local';

    /** Folder akar seluruh berkas pengadaan di dalam disk tersebut. */
    private const FOLDER = 'pengadaan';

    /**
     * Simpan berkas unggahan, mengembalikan path relatif terhadap disk.
     *
     * Nama berkasnya diacak, bukan memakai nama asli: nama asli sering memuat
     * spasi, karakter non-ASCII, dan kadang identitas penyedia — dan nama yang
     * sama dari dua unggahan akan saling menimpa. Nama aslinya tetap disimpan
     * di kolom `original_name`, lalu dipakai kembali saat berkasnya diunduh.
     */
    public static function simpan(UploadedFile $file, Procurement $procurement): string
    {
        $ekstensi = strtolower($file->getClientOriginalExtension() ?: 'bin');

        return (string) $file->storeAs(
            self::folder($procurement),
            Str::uuid()->toString().'.'.$ekstensi,
            ['disk' => self::DISK],
        );
    }

    /**
     * Alirkan berkas ke pengunduh dengan nama aslinya kembali.
     */
    public static function unduh(ProcurementDocument $document): StreamedResponse
    {
        return Storage::disk(self::DISK)->download($document->file_path, $document->original_name);
    }

    /**
     * Tampilkan berkas di peramban alih-alih mengunduhnya — untuk foto belanja
     * dan PDF yang cukup dilihat sekilas (ADR 0035).
     *
     * Hanya untuk jenis yang lolos `ProcurementDocument::dapatDitampilkan()`,
     * dan `nosniff` dipasang supaya peramban tidak menebak-nebak jenis isinya
     * menjadi sesuatu yang dapat dijalankan.
     */
    public static function tampilkan(ProcurementDocument $document): StreamedResponse
    {
        return Storage::disk(self::DISK)->response(
            $document->file_path,
            $document->original_name,
            ['Content-Type' => $document->mime_type, 'X-Content-Type-Options' => 'nosniff'],
        );
    }

    public static function hapus(ProcurementDocument $document): void
    {
        self::hapusBerkas($document->file_path);
    }

    /**
     * Membuang berkas yang belum sempat punya baris dokumen — misalnya foto
     * yang sudah tertulis ke disk ketika transaksi penandaan belanja gagal.
     */
    public static function hapusBerkas(string $path): void
    {
        Storage::disk(self::DISK)->delete($path);
    }

    /**
     * Apakah berkasnya benar-benar masih ada di disk.
     *
     * Baris basis data dan berkas fisik bisa terpisah — misalnya berkas
     * terhapus di luar aplikasi saat pemulihan cadangan. Ditanyakan sebelum
     * mengunduh supaya pengguna melihat pesan yang jelas, bukan galat 500.
     */
    public static function ada(ProcurementDocument $document): bool
    {
        return Storage::disk(self::DISK)->exists($document->file_path);
    }

    private static function folder(Procurement $procurement): string
    {
        return self::FOLDER.'/'.$procurement->procurement_id;
    }
}
