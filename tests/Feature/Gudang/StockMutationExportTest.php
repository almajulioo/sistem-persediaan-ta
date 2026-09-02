<?php

use App\Enums\MutationType;
use App\Enums\UserRole;
use App\Models\Item;
use App\Models\User;
use App\Support\StockLedger;

beforeEach(function () {
    $this->petugas = User::factory()->role(UserRole::PengelolaGudang)
        ->create(['fullname' => 'Siti Pengelola']);

    $this->kertas = Item::factory()->create([
        'item_code' => 'ATK-001', 'item_name' => 'Kertas HVS A4', 'stock_current' => 0,
    ]);
    // Diberi stok awal langsung lewat factory supaya mutasi keluar di bawah
    // tidak kehabisan stok, tanpa menambah baris mutasi ke dalam laporan.
    $this->tinta = Item::factory()->create([
        'item_code' => 'ATK-002', 'item_name' => 'Tinta Printer', 'stock_current' => 20,
    ]);

    StockLedger::record(
        item: $this->kertas,
        type: MutationType::SaldoAwal,
        amount: 100,
        recordedBy: $this->petugas,
        referenceNumber: 'PG01001',
        date: now()->subDays(20),
    );

    StockLedger::record(
        item: $this->tinta,
        type: MutationType::BarangRusak,
        amount: 5,
        recordedBy: $this->petugas,
        notes: 'Botol pecah saat penyimpanan.',
        date: now()->subDays(2),
    );
});

/**
 * Ambil isi unduhan streaming sebagai teks.
 */
function bodyOf($response): string
{
    ob_start();
    $response->baseResponse->sendContent();

    return ob_get_clean();
}

describe('ekspor CSV', function () {
    it('mengunduh CSV berisi seluruh mutasi', function () {
        $response = $this->actingAs($this->petugas)->get('/mutasi/cetak/csv');

        $response->assertOk();
        expect($response->headers->get('content-type'))->toContain('text/csv');

        $csv = bodyOf($response);

        expect($csv)->toStartWith("\xEF\xBB\xBF")          // BOM UTF-8 untuk Excel
            // fputcsv mengutip field yang memuat spasi, jadi judul kolom berkutip.
            ->and($csv)->toContain('Tanggal;"Kode Barang";"Nama Barang"')
            ->and($csv)->toContain('"Jenis Mutasi";Sumber')
            ->and($csv)->toContain('Manual')
            ->and($csv)->toContain('Kertas HVS A4')
            ->and($csv)->toContain('Tinta Printer')
            ->and($csv)->toContain('Siti Pengelola')
            ->and($csv)->toContain('PG01001')
            ->and($csv)->toContain('Botol pecah saat penyimpanan.');
    });

    it('menghormati penyaring yang sama dengan layar', function () {
        $response = $this->actingAs($this->petugas)->get('/mutasi/cetak/csv?q=Kertas');

        $csv = bodyOf($response);

        expect($csv)->toContain('Kertas HVS A4')
            ->and($csv)->not->toContain('Tinta Printer');
    });

    it('menghormati penyaring jenis mutasi', function () {
        $response = $this->actingAs($this->petugas)
            ->get('/mutasi/cetak/csv?type='.MutationType::BarangRusak->value);

        $csv = bodyOf($response);

        expect($csv)->toContain('Tinta Printer')
            ->and($csv)->not->toContain('Kertas HVS A4');
    });

    it('ditolak untuk Pejabat Pengadaan', function () {
        $this->actingAs(User::factory()->role(UserRole::PejabatPengadaan)->create())
            ->get('/mutasi/cetak/csv')
            ->assertForbidden();
    });
});

describe('cetak PDF', function () {
    it('menghasilkan PDF laporan mutasi', function () {
        $response = $this->actingAs($this->petugas)->get('/mutasi/cetak/pdf');

        $response->assertOk();
        expect($response->headers->get('content-type'))->toContain('application/pdf')
            ->and($response->getContent())->toStartWith('%PDF');
    });

    it('menghasilkan PDF walau tidak ada data pada penyaring', function () {
        $response = $this->actingAs($this->petugas)->get('/mutasi/cetak/pdf?q=TidakAda');

        $response->assertOk();
        expect($response->getContent())->toStartWith('%PDF');
    });

    it('menghasilkan kartu persediaan per barang', function () {
        $response = $this->actingAs($this->petugas)
            ->get("/barang/{$this->kertas->item_id}/kartu-persediaan");

        $response->assertOk();
        expect($response->headers->get('content-type'))->toContain('application/pdf')
            ->and($response->getContent())->toStartWith('%PDF');
    });

    it('ditolak untuk Pejabat Pengadaan', function () {
        $this->actingAs(User::factory()->role(UserRole::PejabatPengadaan)->create())
            ->get('/mutasi/cetak/pdf')
            ->assertForbidden();
    });
});
