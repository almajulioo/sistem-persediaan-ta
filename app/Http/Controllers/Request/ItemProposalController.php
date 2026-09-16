<?php

namespace App\Http\Controllers\Request;

use App\Enums\AuditEvent;
use App\Enums\AuditModule;
use App\Enums\ItemProposalStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Request\StoreItemProposalRequest;
use App\Models\ItemProposal;
use App\Support\AuditLogger;
use App\Support\NotificationScanner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Usulan barang baru — pintu depan kedua ke tabel `item_proposals` (ADR 0032).
 *
 * Tabel dan keputusannya sama persis dengan milik Pengadaan
 * (App\Http\Controllers\Gudang\ItemProposalController): siapa pun yang butuh
 * barang yang belum ada di master boleh mengusulkan lewat sini, tapi
 * keputusannya tetap satu jalur — menolak lewat rute bersama
 * `item-proposals.reject`, menyetujui lewat `items.store`. Tidak ada `decide()`
 * di sini supaya tidak ada dua cara memutuskan usulan yang sama.
 */
class ItemProposalController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $canManage = $user->isAdmin() || $user->hasRole(UserRole::PengelolaGudang);

        $proposals = ItemProposal::with(['proposedBy', 'decidedBy'])
            ->when(! $canManage, fn ($query) => $query->where('proposed_by', $user->user_id))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('pages.request.item-proposals.index', [
            'title' => 'Usulan Tambah Barang',
            'proposals' => $proposals,
            'canManage' => $canManage,
        ]);
    }

    public function create(): View
    {
        return view('pages.request.item-proposals.create', [
            'title' => 'Usulkan Barang Baru',
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
            ->route('request.item-proposals.index')
            ->with('status', "Usulan barang \"{$proposal->item_name}\" berhasil diajukan dan menunggu keputusan Pengelola Gudang.");
    }
}
