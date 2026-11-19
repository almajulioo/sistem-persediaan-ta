<?php

use App\Enums\UnitKerja;
use App\Enums\UserRole;
use App\Models\Item;
use App\Models\Request as ModelsRequest;
use App\Models\RequestDetail;
use App\Models\StockMutation;
use App\Models\User;
use App\Support\RequestDocumentGenerator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Nomor BAST permintaan diketik Pengelola Gudang dari agenda BAST saat barang
 * diserahkan, terpisah dari nomor Nota Dinas. Helper bersufiks
 * `Bast` supaya tidak bertabrakan dengan helper global berkas uji lain.
 */

const ND_HUKUM_51 = '51/B/ND/DJPKN-V.BLP.1.5/KEU.02.02/10/2026';
const BAST_HUKUM_12 = '12/B/BAST/DJPKN-V.BLP.1.5/KEU.02.02/11/2026';

function permintaanBast(string $status = 'in_issuance', string $type = 'normal', array $attributes = []): ModelsRequest
{
    $request = ModelsRequest::create([
        'user_id' => User::factory()->role(UserRole::Pengguna)->create(['unit' => UnitKerja::SubbagianHukum])->user_id,
        'date' => Carbon::parse('2026-10-15'),
        'type' => $type,
        'status' => $status,
        'request_number' => 'REQ-'.Str::upper(Str::random(8)),
        'nd_number' => ND_HUKUM_51,
        ...$attributes,
    ]);

    RequestDetail::create([
        'request_id' => $request->request_id,
        'item_id' => Item::factory()->create(['stock_current' => 50])->item_id,
        'quantity_requested' => 5,
        'quantity_approved' => 5,
    ]);

    return $request;
}

function serahkanBast(ModelsRequest $request, ?string $bastNumber)
{
    return test()
        ->from(route('request.show', $request))
        ->post(route('request.respond', $request), array_filter([
            'action' => 'approve',
            'bast_number' => $bastNumber,
        ], fn ($value) => $value !== null));
}

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-03 10:00'));
    $this->gudang = User::factory()->role(UserRole::PengelolaGudang)->create();
    $this->generator = $this->mock(RequestDocumentGenerator::class);
});

describe('saat barang diserahkan', function () {
    it('menyimpan nomor yang diketik, terpisah dari nomor Nota Dinas', function () {
        $this->generator->shouldReceive('generateBast')->once()
            ->withArgs(fn (ModelsRequest $request) => $request->bast_number === BAST_HUKUM_12);
        $request = permintaanBast();

        $this->actingAs($this->gudang);
        serahkanBast($request, '  '.BAST_HUKUM_12.' ')->assertSessionHasNoErrors();

        expect($request->refresh())
            ->status->toBe('delivered')
            ->bast_number->toBe(BAST_HUKUM_12)
            ->nd_number->toBe(ND_HUKUM_51);
    });

    it('mewajibkan nomor BAST dan tidak mengeluarkan stok tanpanya', function () {
        $this->generator->shouldNotReceive('generateBast');
        $request = permintaanBast();

        $this->actingAs($this->gudang);
        serahkanBast($request, null)->assertSessionHasErrors(['bast_number' => 'Nomor BAST wajib diisi saat barang diserahkan.']);

        expect($request->refresh())
            ->status->toBe('in_issuance')
            ->bast_number->toBeNull()
            ->and(StockMutation::count())->toBe(0);
    });

    it('menolak nomor yang tidak diawali nomor urut', function (string $nomor) {
        $this->generator->shouldNotReceive('generateBast');
        $request = permintaanBast();

        $this->actingAs($this->gudang);
        serahkanBast($request, $nomor)->assertSessionHasErrors('bast_number');

        expect($request->refresh()->status)->toBe('in_issuance');
    })->with([
        'hanya nomor urut' => ['12'],
        'huruf di nomor urut' => ['12a/B/BAST'],
        'berspasi' => ['12/B/BAST/DJPKN V'],
        'terlalu panjang' => ['12/'.str_repeat('X', 100)],
    ]);

    it('menolak nomor yang sudah dipakai BAST permintaan lain', function () {
        $this->generator->shouldNotReceive('generateBast');
        permintaanBast('completed', attributes: ['nd_number' => '50/B/ND/DJPKN-V.BLP.1.5/KEU.02.02/10/2026', 'bast_number' => BAST_HUKUM_12]);
        $request = permintaanBast();

        $this->actingAs($this->gudang);
        serahkanBast($request, BAST_HUKUM_12)
            ->assertSessionHasErrors(['bast_number' => 'Nomor BAST '.BAST_HUKUM_12.' sudah dipakai permintaan lain.']);

        expect($request->refresh()->status)->toBe('in_issuance');
    });

    it('menolak nomor yang sudah dipakai BAST penerimaan pengadaan', function () {
        $this->generator->shouldNotReceive('generateBast');
        // Realisasinya tidak relevan di sini; pemeriksaan FK ditunda sampai
        // akhir transaksi uji, yang memang selalu dibatalkan.
        DB::statement('PRAGMA defer_foreign_keys = ON');
        DB::table('procurement_receipts')->insert([
            'procurement_receipt_id' => (string) Str::uuid(),
            'procurement_id' => (string) Str::uuid(),
            'bast_number' => BAST_HUKUM_12,
            'received_by' => $this->gudang->user_id,
            'received_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $request = permintaanBast();

        $this->actingAs($this->gudang);
        serahkanBast($request, BAST_HUKUM_12)->assertSessionHasErrors('bast_number');

        expect($request->refresh()->status)->toBe('in_issuance');
    });

    it('meminta nomor BAST juga saat permintaan darurat diselesaikan', function () {
        $this->generator->shouldNotReceive('generateBast');
        $request = permintaanBast('approved_by_head', 'emergency');

        $this->actingAs($this->gudang);
        serahkanBast($request, null)->assertSessionHasErrors('bast_number');

        expect($request->refresh()->status)->toBe('approved_by_head');
    });
});

describe('sebelum diserahkan', function () {
    it('tidak memberi nomor BAST saat Kasubbag memulai pengeluaran', function () {
        $this->generator->shouldReceive('startBast')->once();
        $kasubbag = User::factory()->role(UserRole::KasubbagUmumTi)->create(['unit' => UnitKerja::SubbagianUmumTi]);
        $request = permintaanBast('processed_by_staff');

        $this->actingAs($kasubbag);
        serahkanBast($request, BAST_HUKUM_12)->assertSessionHasNoErrors();

        expect($request->refresh())
            ->status->toBe('in_issuance')
            ->bast_number->toBeNull();
    });
});

describe('saran nomor', function () {
    it('mengikuti unit pemohon dan bulan penyerahan, bukan bulan Nota Dinas', function () {
        $request = permintaanBast();

        expect($request->nomorAkhiranBast())->toBe('/B/BAST/DJPKN-V.BLP.1.5/KEU.02.02/11/2026')
            ->and($request->nomorAkhiran())->toBe('/B/ND/DJPKN-V.BLP.1.5/KEU.02.02/10/2026');
    });

    it('menampilkan kolom nomor BAST hanya pada langkah yang menyerahkan barang', function () {
        $this->actingAs($this->gudang);

        expect(permintaanBast()->menyerahkanSaatDisetujui())->toBeTrue()
            ->and(permintaanBast('approved_by_head', 'emergency')->menyerahkanSaatDisetujui())->toBeTrue()
            ->and(permintaanBast('approved_by_head')->menyerahkanSaatDisetujui())->toBeFalse()
            ->and(permintaanBast('processed_by_staff')->menyerahkanSaatDisetujui())->toBeFalse();

        // @js meng-escape tanda kutip dan garis miring; dibuang dulu supaya
        // yang dibandingkan isinya saja.
        $html = str_replace('\\', '', $this->get(route('request.show', permintaanBast()))->assertOk()->getContent());

        expect($html)
            ->toContain('needsBastu0022:true')
            ->toContain('akhiranBastu0022:u0022/B/BAST/DJPKN-V.BLP.1.5/KEU.02.02/11/2026');
    });
});
