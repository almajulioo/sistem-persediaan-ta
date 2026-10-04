<?php

namespace App\Http\Controllers\Pengadaan;

use App\Enums\AuditEvent;
use App\Enums\AuditModule;
use App\Enums\ProcurementRequestSource;
use App\Enums\ProcurementRequestStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pengadaan\UpdateProcurementDraftRequest;
use App\Models\Item;
use App\Models\ProcurementRequest;
use App\Support\AuditLogger;
use App\Support\NotificationScanner;
use App\Support\ProcurementDraftScanner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

        return view('pages.procurement-requests.draft', [
            'title' => 'Draf Usulan Pengadaan',
            'draft' => ProcurementRequest::query()->draf()->with('details.item')->first(),
            'items' => Item::aktif()->orderBy('item_code')->get(['item_id', 'item_code', 'item_name', 'unit', 'stock_current']),
        ]);
    }

    public function update(UpdateProcurementDraftRequest $request): RedirectResponse
    {
        $items = $request->validated()['items'];

        $draft = DB::transaction(function () use ($items) {
            $draft = ProcurementRequest::query()->draf()->lockForUpdate()->first();

            if ($draft === null) {
                if ($items === []) {
                    return null;
                }

                $draft = ProcurementRequest::create([
                    'user_id' => null,
                    'source' => ProcurementRequestSource::PemantauanStok,
                    'status' => ProcurementRequestStatus::Draf,
                ]);
            }

            // Ganti seluruhnya, kebalikan dari konvensi
            // ProcurementController::update() untuk realisasi: di sana baris
            // yang hilang dari kiriman adalah galat, di sini baris yang tidak
            // dikirim ulang berarti Pengelola Gudang memang membuangnya.
            foreach ($items as $item) {
                $draft->details()->updateOrCreate(
                    ['item_id' => $item['item_id']],
                    ['amount_request' => $item['amount_request'], 'is_auto' => false],
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
        $draft = ProcurementRequest::query()->draf()->with('details')->first();

        if ($draft === null || $draft->details->isEmpty()) {
            return redirect()
                ->route('procurement-requests.draft.edit')
                ->with('error', 'Tidak ada barang pada draf untuk diajukan.');
        }

        DB::transaction(function () use ($request, $draft) {
            $draft->forceFill([
                'user_id' => $request->user()->user_id,
                'status' => ProcurementRequestStatus::Diajukan,
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
        $draft = ProcurementRequest::query()->draf()->first();

        if ($draft === null) {
            return redirect()
                ->route('procurement-requests.index')
                ->with('error', 'Tidak ada draf usulan untuk dibuang.');
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
}
