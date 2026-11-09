<?php

namespace App\Http\Controllers\Pengadaan;

use App\Enums\AuditEvent;
use App\Enums\AuditModule;
use App\Enums\ProcurementRequestSource;
use App\Enums\ProcurementRequestStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pengadaan\UpdateProcurementDraftRequest;
use App\Models\Item;
use App\Models\ItemApplicationDetail;
use App\Models\Procurement;
use App\Models\ProcurementRequest;
use App\Models\ProcurementRequestDetail;
use App\Support\AuditLogger;
use App\Support\KatalogBarang;
use App\Support\NotificationScanner;
use App\Support\ProcurementDraftScanner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Draf usulan pengadaan otomatis dari pemantauan stok (ADR 0027).
 *
 * Berbeda dari ProcurementRequestController: dokumen di sini belum berstatus
 * Diajukan, jadi belum menjadi usulan sungguhan. Aksesnya disamakan dengan
 * mengajukan usulan manual — tugas Pengelola Gudang (ADR 0016), Admin lolos
 * lewat Gate::before.
 */
class ProcurementDraftController extends Controller
{
    public function edit(): View
    {
        // Disapu ulang di sini, bukan hanya mengandalkan pemicu reaktif —
        // halaman ini satu-satunya tempat manusia benar-benar menatap draf
        // ini, jadi ia harus terjamin mutakhir saat dibuka.
        ProcurementDraftScanner::perbaruiSeluruh();

        $draft = ProcurementRequest::query()->draf()->with('details.item')->first();

        return view('pages.procurement-requests.draft', [
            'title' => 'Draf Usulan Pengadaan',
            'draft' => $draft,
            'katalog' => KatalogBarang::untukPemilih(),
            'subkategori' => KatalogBarang::subkategori(),
            'baris' => $draft !== null ? $this->barisDraf($draft) : collect(),
            'dalamProses' => $this->barangDalamProses(),
        ]);
    }

    public function update(UpdateProcurementDraftRequest $request): RedirectResponse
    {
        $items = $request->validated()['items'];
        $id = (string) $request->input('draft_id');

        // Dulu draf dibuat baru bila tidak ada, sehingga halaman draf yang
        // dipulihkan tombol Kembali setelah diajukan dapat melahirkan draf
        // kedua berisi baris lama yang semuanya terbaca Manual (ADR 0045).
        $draft = DB::transaction(function () use ($items, $id) {
            $draft = ProcurementRequest::query()->draf()->whereKey($id)->lockForUpdate()->first();

            if ($draft === null) {
                return false;
            }

            $lama = $draft->details()->get()->keyBy('item_id');

            // Ganti seluruhnya, kebalikan dari konvensi
            // ProcurementController::update() untuk realisasi: di sana baris
            // yang hilang dari kiriman adalah galat, di sini baris yang tidak
            // dikirim ulang berarti Pengelola Gudang memang membuangnya.
            foreach ($items as $item) {
                // Sumber tidak pernah diambil dari kiriman: baris yang sudah
                // ada mempertahankan asalnya walau jumlahnya disunting, dan
                // barang yang baru ditambahkan di sini adalah tambahan Gudang.
                $sumber = $lama->get($item['item_id'])?->source ?? ProcurementRequestSource::Manual;

                $draft->details()->updateOrCreate(
                    ['item_id' => $item['item_id']],
                    [
                        'source' => $sumber,
                        'amount_request' => $item['amount_request'],
                        'is_auto' => false,
                        ...($sumber->bolehBerketerangan() ? ['note' => $item['note'] ?? null] : []),
                    ],
                );
            }

            $draft->details()
                ->whereNotIn('item_id', array_column($items, 'item_id'))
                ->delete();

            if ($draft->details()->doesntExist()) {
                $draft->delete();

                return null;
            }

            return $draft;
        });

        if ($draft === false) {
            return $this->drafBerganti($id);
        }

        // Draf yang dikosongkan sampai habis ikut terhapus di dalam transaksi
        // di atas, jadi keduanya dicatat sesuai apa yang sungguh terjadi
        // ketimbang sama sama disebut "mengubah".
        AuditLogger::record(
            user: $request->user(),
            activity: $draft !== null
                ? "Mengubah draf usulan pengadaan: {$draft->judul()}"
                : 'Mengosongkan draf usulan pengadaan hingga terbuang',
            module: AuditModule::UsulanPengadaan,
            event: $draft !== null ? AuditEvent::Ubah : AuditEvent::Hapus,
            subject: $draft,
        );

        ProcurementDraftScanner::sinkronkanNotifikasi();

        return redirect()
            ->route('procurement-requests.draft.edit')
            ->with('status', $draft !== null
                ? 'Perubahan draf usulan berhasil disimpan.'
                : 'Draf usulan dikosongkan dan dibuang.');
    }

    public function finalize(Request $request): RedirectResponse
    {
        $id = (string) $request->input('draft_id');
        $draft = ProcurementRequest::query()->draf()->whereKey($id)->with('details')->first();

        if ($draft === null) {
            return $this->drafBerganti($id);
        }

        if ($draft->details->isEmpty()) {
            return redirect()
                ->route('procurement-requests.draft.edit')
                ->with('error', 'Tidak ada barang pada draf untuk diajukan.');
        }

        DB::transaction(function () use ($request, $draft) {
            $draft->forceFill([
                'user_id' => $request->user()->user_id,
                'status' => ProcurementRequestStatus::Diajukan,
                // Draf belum resmi; usulannya baru lahir saat diajukan. Tanpa
                // ini, draf yang lama menunggu tercatat diajukan pada hari
                // drafnya dibuat, dan langsung terbaca menggantung (ADR 0049).
                'created_at' => now(),
            ])->save();
        });

        AuditLogger::record(
            user: $request->user(),
            activity: "Mengajukan usulan pengadaan: {$draft->judul()}",
            module: AuditModule::UsulanPengadaan,
            event: AuditEvent::Ajukan,
            subject: $draft,
        );
        NotificationScanner::periksaUsulan($draft);
        ProcurementDraftScanner::sinkronkanNotifikasi();

        return redirect()
            ->route('procurement-requests.show', $draft)
            ->with('status', "{$draft->judul()} berhasil diajukan.");
    }

    public function discard(Request $request): RedirectResponse
    {
        $id = (string) $request->input('draft_id');
        $draft = ProcurementRequest::query()->draf()->whereKey($id)->first();

        if ($draft === null) {
            return $this->drafBerganti($id);
        }

        $judul = $draft->judul();
        $draft->delete();

        AuditLogger::record(
            user: $request->user(),
            activity: "Membuang draf usulan pengadaan: {$judul}",
            module: AuditModule::UsulanPengadaan,
            event: AuditEvent::Hapus,
        );
        ProcurementDraftScanner::sinkronkanNotifikasi();

        return redirect()
            ->route('procurement-requests.index')
            ->with('status', "{$judul} dibuang.");
    }

    /**
     * Aksi dikirim dari halaman draf yang sudah usang: draf yang ditampilkannya
     * sudah diajukan, dibuang, atau digantikan draf baru (ADR 0045). Setiap
     * aksi draf diikat ke `draft_id` halaman pengirim, bukan ke draf apa pun
     * yang kebetulan ada sekarang — tanpa itu, tab lain atau tombol Kembali
     * dapat mengajukan atau membuang draf yang belum pernah dilihat.
     */
    private function drafBerganti(string $id): RedirectResponse
    {
        $usulan = $id !== '' ? ProcurementRequest::query()->bukanDraf()->find($id) : null;

        if ($usulan !== null) {
            return redirect()
                ->route('procurement-requests.show', $usulan)
                ->with('status', "{$usulan->judul()} sudah diajukan sebelumnya.");
        }

        return redirect()
            ->route('procurement-requests.draft.edit')
            ->with('error', 'Draf sudah berubah sejak halaman ini dibuka. Periksa kembali isinya.');
    }

    /**
     * Baris draf untuk editor di peramban, beserta permohonan yang menyumbang
     * tiap barang supaya Gudang tahu siapa yang membutuhkannya sebelum
     * mengajukan usulan.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function barisDraf(ProcurementRequest $draft): Collection
    {
        $permohonanPerBarang = $draft->permohonanPerBarang();

        return $draft->details
            ->map(fn (ProcurementRequestDetail $detail) => [
                'item_id' => $detail->item_id,
                'item_code' => $detail->item?->item_code,
                'item_name' => $detail->item?->item_name ?? 'Barang tidak dikenal',
                'unit' => $detail->item?->unit?->value,
                'stock_current' => (int) $detail->item?->stock_current,
                'amount_request' => $detail->amount_request,
                'source' => $detail->source->value,
                'note' => (string) $detail->note,
                'permohonan' => $permohonanPerBarang->get($detail->item_id, collect())
                    ->map(fn (ItemApplicationDetail $asal) => [
                        'nomor' => $asal->application->application_number,
                        'pemohon' => $asal->application->user?->fullname ?? '-',
                        'jumlah' => $asal->quantity,
                        'url' => route('item-applications.show', $asal->application),
                    ])
                    ->values()
                    ->all(),
            ])
            ->values();
    }

    /**
     * Barang di bawah minimum yang dilewati pemindaian karena pengadaannya
     * sudah berjalan (ADR 0047). Ditampilkan supaya Gudang tidak mengira
     * barangnya terlewat lalu menambahkannya manual ke draf.
     *
     * @return Collection<int, array{item: Item, dokumen: Collection<int, array<string, mixed>>}>
     */
    private function barangDalamProses(): Collection
    {
        $dokumen = ProcurementDraftScanner::dokumenDalamProses();

        return Item::query()->aktif()->diBawahMinimum()
            ->whereIn('item_id', $dokumen->keys())
            ->orderBy('item_name')
            ->get()
            ->map(fn (Item $item) => [
                'item' => $item,
                'dokumen' => $dokumen->get($item->item_id)
                    ->map(fn (ProcurementRequest|Procurement $asal) => [
                        'judul' => $asal->judul(),
                        'status' => $asal->status,
                        'url' => $asal instanceof Procurement
                            ? route('procurements.show', $asal)
                            : route('procurement-requests.show', $asal),
                    ]),
            ]);
    }
}
