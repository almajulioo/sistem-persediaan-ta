<?php

namespace App\Http\Controllers\Gudang;

use App\Enums\AuditEvent;
use App\Enums\AuditModule;
use App\Enums\ItemProposalStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Gudang\RejectItemProposalRequest;
use App\Models\ItemProposal;
use App\Support\AuditLogger;
use App\Support\NotificationScanner;
use Illuminate\Http\RedirectResponse;

/**
 * Keputusan menolak usulan barang baru (ADR 0032).
 *
 * Usulan barang lahir dari permohonan barang baru yang disetujui Pengelola
 * Gudang, dan diputuskan dari rincian permohonan itu — tidak ada lagi daftar,
 * formulir, maupun halaman rincian usulan sendiri (ADR 0053). Yang tersisa di
 * sini hanya penolakan.
 *
 * **Tidak ada aksi "setujui" di sini.** Menyetujui usulan berarti mendaftarkan
 * barangnya, dan persetujuan tanpa baris `items` tidak berarti apa pun — jadi
 * tombolnya menuju formulir barang yang sudah terisi, dan
 * ItemController::store() yang menutup usulan ini.
 */
class ItemProposalController extends Controller
{
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
            return redirect()
                ->to($itemProposal->tautan())
                ->with('error', "Usulan barang \"{$itemProposal->item_name}\" sudah diputuskan sebelumnya.");
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
            ->to($itemProposal->tautan())
            ->with('status', "Usulan barang \"{$itemProposal->item_name}\" ditolak.");
    }
}
