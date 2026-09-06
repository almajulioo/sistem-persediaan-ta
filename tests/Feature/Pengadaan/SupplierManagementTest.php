<?php

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Supplier;
use App\Models\User;

// Nama pembantu sengaja unik: fungsi di berkas Pest bersifat global lintas
// berkas, dan `pengelolaGudang()` sudah dipakai ItemManagementTest.
function pejabatPengadaan(array $attributes = []): User
{
    return User::factory()->role(UserRole::PejabatPengadaan)->create($attributes);
}

function supplierPayload(array $overrides = []): array
{
    return array_merge([
        'supplier_name' => 'CV Sumber Rejeki Lampung',
        'address' => 'Jl. Raden Intan No. 45, Bandar Lampung',
        'phone' => '0721-263450',
        'email' => 'sales@sumberrejeki.co.id',
        'contact_person' => 'Budi Santoso',
        'npwp' => '01.234.567.8-321.000',
        'is_active' => 1,
    ], $overrides);
}

describe('hak akses', function () {
    it('mengarahkan tamu ke halaman masuk', function () {
        $this->get('/penyedia')->assertRedirect('/login');
    });

    it('menolak Pengelola Gudang', function () {
        $this->actingAs(User::factory()->role(UserRole::PengelolaGudang)->create())
            ->get('/penyedia')
            ->assertForbidden();
    });

    it('mengizinkan Pejabat Pengadaan', function () {
        $this->actingAs(pejabatPengadaan())
            ->get('/penyedia')
            ->assertOk()
            ->assertSee('Penyedia Barang');
    });

    // Menangkap jebakan middleware: `role:pejabat_pengadaan` akan menolak Admin,
    // sedangkan gate `kelola-pengadaan` meloloskannya lewat Gate::before.
    it('mengizinkan Admin', function () {
        $this->actingAs(User::factory()->role(UserRole::Admin)->create())
            ->get('/penyedia')
            ->assertOk();
    });

    it('menolak Pengelola Gudang membuat penyedia', function () {
        $this->actingAs(User::factory()->role(UserRole::PengelolaGudang)->create())
            ->post('/penyedia', supplierPayload())
            ->assertForbidden();

        expect(Supplier::where('supplier_name', 'CV Sumber Rejeki Lampung')->exists())->toBeFalse();
    });

    it('menyembunyikan menu penyedia dari Pengelola Gudang', function () {
        $this->actingAs(User::factory()->role(UserRole::PengelolaGudang)->create())
            ->get('/dasbor')
            ->assertOk()
            ->assertDontSee('Penyedia Barang');
    });
});

describe('membuat penyedia', function () {
    it('menyimpan penyedia dan mencatat jejak audit', function () {
        $actor = pejabatPengadaan();

        $this->actingAs($actor)
            ->post('/penyedia', supplierPayload())
            ->assertRedirect('/penyedia')
            ->assertSessionHas('status');

        $supplier = Supplier::where('supplier_name', 'CV Sumber Rejeki Lampung')->first();

        expect($supplier)->not->toBeNull()
            ->and($supplier->address)->toBe('Jl. Raden Intan No. 45, Bandar Lampung')
            ->and($supplier->contact_person)->toBe('Budi Santoso')
            ->and($supplier->is_active)->toBeTrue();

        expect(AuditLog::where('user_id', $actor->user_id)
            ->where('activity', 'like', 'Menambah penyedia: CV Sumber Rejeki%')
            ->exists())->toBeTrue();
    });

    it('menerima penyedia dengan data kontak seadanya', function () {
        $this->actingAs(pejabatPengadaan())
            ->post('/penyedia', supplierPayload([
                'phone' => '',
                'email' => '',
                'contact_person' => '',
                'npwp' => '',
            ]))
            ->assertSessionHasNoErrors();

        expect(Supplier::where('supplier_name', 'CV Sumber Rejeki Lampung')->first()->phone)->toBeNull();
    });

    // Alamat wajib karena BAST mencantumkannya.
    it('menolak penyedia tanpa alamat', function () {
        $this->actingAs(pejabatPengadaan())
            ->post('/penyedia', supplierPayload(['address' => '']))
            ->assertSessionHasErrors('address');
    });

    it('menolak nama penyedia ganda', function () {
        Supplier::factory()->create(['supplier_name' => 'CV Sumber Rejeki Lampung']);

        $this->actingAs(pejabatPengadaan())
            ->post('/penyedia', supplierPayload())
            ->assertSessionHasErrors('supplier_name');
    });

    it('menolak surel yang tidak sah', function () {
        $this->actingAs(pejabatPengadaan())
            ->post('/penyedia', supplierPayload(['email' => 'bukan-surel']))
            ->assertSessionHasErrors('email');
    });

    it('menolak NPWP berisi huruf', function () {
        $this->actingAs(pejabatPengadaan())
            ->post('/penyedia', supplierPayload(['npwp' => 'NPWP-ABC']))
            ->assertSessionHasErrors('npwp');
    });
});

describe('mengubah penyedia', function () {
    it('memperbarui data penyedia', function () {
        $supplier = Supplier::factory()->create(['supplier_name' => 'CV Lama']);

        $this->actingAs(pejabatPengadaan())
            ->put("/penyedia/{$supplier->supplier_id}", supplierPayload([
                'supplier_name' => 'CV Baru',
                'address' => 'Jl. Baru No. 1',
            ]))
            ->assertRedirect('/penyedia');

        $supplier->refresh();

        expect($supplier->supplier_name)->toBe('CV Baru')
            ->and($supplier->address)->toBe('Jl. Baru No. 1');
    });

    // Aturan unique harus mengecualikan baris yang sedang disunting.
    it('mengizinkan menyimpan tanpa mengubah nama', function () {
        $supplier = Supplier::factory()->create(['supplier_name' => 'CV Tetap']);

        $this->actingAs(pejabatPengadaan())
            ->put("/penyedia/{$supplier->supplier_id}", supplierPayload([
                'supplier_name' => 'CV Tetap',
            ]))
            ->assertSessionHasNoErrors();
    });
});

describe('status penyedia', function () {
    it('mengarsipkan dan mengaktifkan kembali', function () {
        $actor = pejabatPengadaan();
        $supplier = Supplier::factory()->create(['is_active' => true]);

        $this->actingAs($actor)->patch("/penyedia/{$supplier->supplier_id}/status");
        expect($supplier->refresh()->is_active)->toBeFalse();

        $this->actingAs($actor)->patch("/penyedia/{$supplier->supplier_id}/status");
        expect($supplier->refresh()->is_active)->toBeTrue();
    });
});

describe('menghapus penyedia', function () {
    it('menghapus penyedia yang belum dipakai', function () {
        $supplier = Supplier::factory()->create();

        $this->actingAs(pejabatPengadaan())
            ->delete("/penyedia/{$supplier->supplier_id}")
            ->assertRedirect('/penyedia')
            ->assertSessionHas('status');

        expect(Supplier::find($supplier->supplier_id))->toBeNull();
    });
});

describe('daftar dan penyaringan', function () {
    it('menyaring berdasarkan kata kunci dan status', function () {
        $actor = pejabatPengadaan();
        Supplier::factory()->create(['supplier_name' => 'CV Sumber Rejeki', 'contact_person' => 'Budi']);
        Supplier::factory()->create(['supplier_name' => 'PT Mitra Sarana', 'contact_person' => 'Siti']);
        Supplier::factory()->arsip()->create(['supplier_name' => 'UD Sudah Tutup']);

        $this->actingAs($actor)->get('/penyedia?q=Sumber')
            ->assertSee('CV Sumber Rejeki')->assertDontSee('PT Mitra Sarana');

        // Pencarian juga menjangkau narahubung.
        $this->actingAs($actor)->get('/penyedia?q=Siti')
            ->assertSee('PT Mitra Sarana')->assertDontSee('CV Sumber Rejeki');

        $this->actingAs($actor)->get('/penyedia?status=arsip')
            ->assertSee('UD Sudah Tutup')->assertDontSee('CV Sumber Rejeki');
    });
});

describe('halaman detail', function () {
    it('menampilkan identitas penyedia', function () {
        $supplier = Supplier::factory()->create([
            'supplier_name' => 'CV Sumber Rejeki',
            'address' => 'Jl. Raden Intan No. 45',
            'contact_person' => 'Budi Santoso',
        ]);

        $this->actingAs(pejabatPengadaan())
            ->get("/penyedia/{$supplier->supplier_id}")
            ->assertOk()
            ->assertSee('CV Sumber Rejeki')
            ->assertSee('Jl. Raden Intan No. 45')
            ->assertSee('Budi Santoso')
            ->assertSee('Belum ada pengadaan kepada penyedia ini.');
    });
});

describe('cacah pengadaan penyedia', function () {
    /**
     * Satu penyedia dengan dua realisasi: satu berjalan, satu dibatalkan.
     * Pembantunya dipakai ulang dari berkas uji Tahap 3 dan pembatalan.
     */
    $penyediaDenganSatuBatal = function (): Supplier {
        $pejabat = pejabatPengadaan();
        $gudang = pengelolaGudang();
        $supplier = Supplier::factory()->create(['supplier_name' => 'CV Bumi Lampung']);

        foreach ([false, true] as $dibatalkan) {
            $usulan = setujuiUsulan($pejabat, buatUsulan($gudang, [
                ['item_id' => \App\Models\Item::factory()->create()->item_id, 'amount_request' => 10],
            ]));
            $procurement = buatRealisasi($pejabat, $usulan, $supplier);

            if ($dibatalkan) {
                test()->actingAs($pejabat)
                    ->patch("/pengadaan/{$procurement->procurement_id}/batal", pembatalanPayload());
            }
        }

        return $supplier;
    };

    it('tidak menghitung realisasi yang dibatalkan pada daftar', function () use ($penyediaDenganSatuBatal) {
        // Inti ADR 0026: yang dicacah adalah pengadaan yang benar-benar
        // berjalan, bukan dokumen yang pernah dibuat.
        $penyediaDenganSatuBatal();

        $this->actingAs(pejabatPengadaan())
            ->get('/penyedia')
            ->assertOk()
            ->assertSee('1&times;', false)
            ->assertSee('1 dibatalkan');
    });

    it('menyebut pembatalannya di halaman detail tanpa menyembunyikan barisnya', function () use ($penyediaDenganSatuBatal) {
        $supplier = $penyediaDenganSatuBatal();

        $this->actingAs(pejabatPengadaan())
            ->get("/penyedia/{$supplier->supplier_id}")
            ->assertOk()
            ->assertSee('1 pengadaan berjalan')
            ->assertSee('lainnya dibatalkan')
            // Barisnya tetap tampil lengkap dengan badge statusnya.
            ->assertSee('Dibatalkan')
            ->assertSee('Menunggu Penerimaan');
    });
});
