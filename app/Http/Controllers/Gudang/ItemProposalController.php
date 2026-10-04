<?php

namespace App\Http\Controllers\Gudang;

use App\Enums\AuditEvent;
use App\Enums\AuditModule;
use App\Enums\ItemProposalStatus;
use App\Enums\SatuanBarang;
use App\Http\Controllers\Controller;
use App\Http\Requests\Gudang\RejectItemProposalRequest;
use App\Http\Requests\Gudang\StoreItemProposalRequest;
use App\Models\ItemProposal;
use App\Support\AuditLogger;
use App\Support\NotificationScanner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Usulan barang baru — pintu depan katalog (ADR 0032).
 *
 * Diajukan pemegang `kelola-pengadaan` ketika barang yang mau diadakan belum
 * ada di master barang, diputuskan pemegang `kelola-gudang` yang memiliki
 * master barang itu.
 *
 * **Tidak ada aksi "setujui" di sini.** Menyetujui usulan berarti mendaftarkan
 * barangnya, dan persetujuan tanpa baris `items` tidak berarti apa pun — jadi
 * tombolnya menuju formulir barang yang sudah terisi, dan
 * ItemController::store() yang menutup usulan ini. Polanya sama dengan ADR
 * 0030: menyimpan perbaikan *adalah* pengajuan ulangnya.
 *
 * Diletakkan di folder Gudang, bukan Pengadaan, karena subjek dokumennya
 * adalah master barang dan pemutusnya Pengelola Gudang. Letak itu sekaligus
 * menjaganya netral: kebutuhan yang sama muncul dari sub sistem permintaan
 * barang, dan tabelnya sudah dirancang untuk itu.
 */
class ItemProposalController extends Controller
{
    private const PER_PAGE = 10;

    public function index(Request $request): View
    {
        $filters = [
            'status' => (string) $request->query('status', ''),
            'q' => (string) $request->query('q', ''),
        ];

        return view('pages.item-proposals.index', [
            'title' => 'Usulan Barang Baru',
            'proposals' => ItemProposal::query()
                ->filter($filters)
                ->with(['proposedBy', 'item'])
                ->latest('created_at')
                ->paginate(self::PER_PAGE)
                ->withQueryString(),
            'filters' => $filters,
            'summary' => [
                'diajukan' => ItemProposal::where('status', ItemProposalStatus::Diajukan)->count(),
                'disetujui' => ItemProposal::where('status', ItemProposalStatus::Disetujui)->count(),
                'ditolak' => ItemProposal::where('status', ItemProposalStatus::Ditolak)->count(),
            ],
        ]);
    }

    public function create(): View
    {
        return view('pages.item-proposals.create', [
            'title' => 'Usulkan Barang Baru',
            'satuan' => SatuanBarang::options(),
        ]);
    }

    public function store(StoreItemProposalRequest $request): RedirectResponse
    {
        $proposal = ItemProposal::create($request->validated() + [
            'proposed_by' => $request->user()->user_id,
            'status' => ItemProposalStatus::Diajukan,
        ]);

        AuditLogger::record(
            user: $request->user(),
            activity: "Mengusulkan barang baru: {$proposal->item_name}",
            module: AuditModule::UsulanBarang,
            event: AuditEvent::Ajukan,
            subject: $proposal,
        );
        NotificationScanner::periksaUsulanBarang($proposal->fresh('proposedBy'));

        return redirect()
            ->route('item-proposals.show', $proposal)
            ->with('status', "Usulan barang \"{$proposal->item_name}\" berhasil diajukan.");
    }

    public function show(ItemProposal $itemProposal): View
    {
        return view('pages.item-proposals.show', [
            'title' => $itemProposal->judul(),
            'proposal' => $itemProposal->load(['proposedBy', 'decidedBy', 'item']),
        ]);
    }

    /**
     * Menolak usulan barang baru.
     *
     * Jalan keluar yang wajib ada: tanpa penolakan, usulan yang barangnya
     * ternyata sudah terdaftar akan menggantung selamanya dan tagihan
     * notifikasinya tidak pernah tertutup — jalan buntu yang sama dengan yang
     * diperbaiki ADR 0024.
     */
    public function reject(RejectItemProposalRequest $request, ItemProposal $itemProposal): RedirectResponse
    {
        // Ditolak dengan pesan, bukan 500 — pola yang sama dengan penjaga
        // keputusan ganda pada ProcurementRequestController::approve().
        if ($itemProposal->sudahDiputuskan()) {
            return back()->with(
                'error',
                "Usulan barang \"{$itemProposal->item_name}\" sudah diputuskan sebelumnya.",
            );
        }

        $itemProposal->forceFill([
            'status' => ItemProposalStatus::Ditolak,
            'decided_by' => $request->user()->user_id,
            'decided_at' => now(),
            'decision_note' => $request->input('decision_note'),
        ])->save();

        AuditLogger::record(
            user: $request->user(),
            activity: "Menolak usulan barang baru: {$itemProposal->item_name}",
            module: AuditModule::UsulanBarang,
            event: AuditEvent::Tolak,
            subject: $itemProposal,
        );
        NotificationScanner::catatUsulanBarangDiputuskan($itemProposal->fresh(['proposedBy', 'item']), $request->user());

        return redirect()
            ->route('item-proposals.show', $itemProposal)
            ->with('status', "Usulan barang \"{$itemProposal->item_name}\" ditolak.");
    }
}
