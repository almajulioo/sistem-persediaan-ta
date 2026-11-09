<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(Tests\TestCase::class)
    ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    // Uji fitur merender Blade tanpa memerlukan hasil build Vite.
    ->beforeEach(fn () => $this->withoutVite())
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Token kiriman formulir pembuat dokumen (App\Support\KirimanFormulir).
 * Ditaruh di sini, bukan di berkas uji, supaya tetap ada saat satu berkas
 * dijalankan sendiri.
 */
function tokenKiriman(): string
{
    return (string) Illuminate\Support\Str::uuid();
}

/**
 * Menuntaskan penerimaan lewat alur BAST digital sungguhan (ADR 0051): hasil
 * pemeriksaan oleh `$petugas`, nomor BAST, lalu TTE `$pejabat` dan `$petugas`.
 * Stok bertambah pada TTE kedua.
 *
 * `$jumlah` memetakan procurement_detail_id => jumlah diterima; bila null,
 * seluruh barang dianggap datang lengkap. Ditaruh di sini supaya tersedia saat
 * satu berkas dijalankan sendiri.
 */
function terimaLewatTte(
    App\Models\Procurement $procurement,
    App\Models\User $petugas,
    App\Models\User $pejabat,
    ?array $jumlah = null,
    ?Carbon\CarbonInterface $tanggal = null,
    ?string $nomor = null,
): App\Models\Procurement {
    $procurement->load('details');

    App\Support\BastPengadaan::catatPemeriksaan(
        procurement: $procurement,
        petugas: $petugas,
        tanggal: $tanggal ?? now(),
        jumlah: $jumlah ?? $procurement->details
            ->mapWithKeys(fn ($detail) => [$detail->procurement_detail_id => $detail->amount])
            ->all(),
        catatan: null,
    );
    App\Support\BastPengadaan::isiNomor(
        $procurement,
        $petugas,
        $nomor ?? 'BAST-'.strtoupper(substr($procurement->procurement_id, 0, 8)).'/'.now()->year,
    );
    App\Support\BastPengadaan::tandatangani($procurement, $pejabat);
    App\Support\BastPengadaan::tandatangani($procurement, $petugas);

    return $procurement->fresh(['details', 'receipt.signatures']);
}

/**
 * Meniru penerimaan sebelum ADR 0051 — "BAST lama": stok masuk saat gudang
 * mengonfirmasi, tanpa TTE, BAST ditandatangani basah lalu dipindai. Hanya
 * untuk menguji perilaku yang masih berlaku bagi data lama (cetak dan nomor
 * ala ADR 0042, notifikasi BastBelumDiunggah).
 */
function terimaCaraLama(
    App\Models\Procurement $procurement,
    App\Models\User $petugas,
    ?array $jumlah = null,
    ?Carbon\CarbonInterface $tanggal = null,
    ?string $nomor = null,
): App\Models\Procurement {
    $procurement->load('details');
    $tanggal ??= now();

    Illuminate\Support\Facades\DB::transaction(function () use ($procurement, $petugas, $jumlah, $tanggal, $nomor) {
        foreach ($procurement->details as $detail) {
            $diterima = (int) ($jumlah[$detail->procurement_detail_id] ?? $detail->amount);

            if ($diterima > 0) {
                App\Support\StockLedger::record(
                    item: App\Models\Item::findOrFail($detail->item_id),
                    type: App\Enums\MutationType::Masuk,
                    amount: $diterima,
                    recordedBy: $petugas,
                    notes: 'Penerimaan cara lama',
                    reference: $procurement,
                    referenceNumber: $procurement->judul(),
                    date: $tanggal,
                );
            }

            $detail->forceFill(['amount_received' => $diterima])->save();
        }

        $procurement->forceFill(['status' => App\Enums\ProcurementStatus::Diterima])->save();

        App\Models\ProcurementReceipt::create([
            'procurement_id' => $procurement->procurement_id,
            'bast_number' => $nomor,
            'received_by' => $petugas->user_id,
            'received_at' => $tanggal,
        ]);
    });

    return $procurement->fresh(['details', 'receipt.signatures']);
}
