<?php

namespace App\Http\Controllers\Request;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Request\StoreItemProposalRequest;
use App\Models\ItemProposal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ItemProposalController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $canManage = $user->isAdmin() || $user->hasRole(UserRole::PengelolaGudang);

        $proposals = ItemProposal::with(['user', 'followedUpBy'])
            ->when(! $canManage, fn ($query) => $query->where('user_id', $user->user_id))
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
        ItemProposal::create([
            ...$request->validated(),
            'user_id' => $request->user()->user_id,
            'status' => 'diajukan',
        ]);

        return redirect()
            ->route('request.item-proposals.index')
            ->with('status', 'Usulan barang berhasil diajukan.');
    }

    public function decide(Request $request, ItemProposal $itemProposal): RedirectResponse
    {
        abort_unless(
            $request->user()->isAdmin() || $request->user()->hasRole(UserRole::PengelolaGudang),
            403,
        );

        if (! in_array($request->input('action'), ['complete', 'reject'], true)) {
            return back()->with('error', 'Keputusan usulan tidak valid.');
        }

        if ($itemProposal->status !== 'diajukan') {
            return back()->with('error', 'Usulan ini sudah memiliki keputusan.');
        }

        $itemProposal->update([
            'status' => $request->input('action') === 'complete' ? 'ditindaklanjuti' : 'ditolak',
            'followed_up_by' => $request->user()->user_id,
            'followed_up_at' => now(),
        ]);

        return back()->with('status', $request->input('action') === 'complete'
            ? 'Usulan barang diselesaikan.'
            : 'Usulan barang ditolak.');
    }
}