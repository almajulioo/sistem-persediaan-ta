<?php

namespace App\Http\Controllers\Request;

use App\Http\Controllers\Controller;
use App\Enums\UserRole;
use App\Http\Requests\Request\StorePermintaanBarangRequest;
use App\Http\Requests\Request\UpdatePermintaanBarangRequest;
use Illuminate\Http\Request;
use App\Models\Item;
use App\Models\Request as ModelsRequest;
use App\Models\RequestDetail;
use Illuminate\Support\Facades\DB;

class RequestController extends Controller
{
    
    public function index(Request $request)
    {
        $keyword = trim((string) $request->query('q', ''));
        $type = (string) $request->query('type', '');
        $status = (string) $request->query('status', '');
        $dateFrom = (string) $request->query('date_from', '');
        $dateTo = (string) $request->query('date_to', '');

        $isKepalaUnit = $request->user()->hasRole(UserRole::KepalaUnit);
        $isStaffUmum = $request->user()->hasRole(UserRole::StaffUmum);
        $isAdmin = $request->user()->isAdmin();

        $requests = ModelsRequest::with('user')
            ->when($keyword !== '', function ($query) use ($keyword) {
                $query->where(function ($query) use ($keyword) {
                    $query->where('request_number', 'like', "%{$keyword}%")
                        ->orWhereHas('user', fn ($userQuery) => $userQuery->where('fullname', 'like', "%{$keyword}%"));
                });
            })
            ->when($type !== '', fn ($query) => $query->where('type', $type))
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->when($dateFrom !== '', fn ($query) => $query->whereDate('date', '>=', $dateFrom))
            ->when($dateTo !== '', fn ($query) => $query->whereDate('date', '<=', $dateTo))
            ->when($isKepalaUnit, fn ($query) => $query->where('status', 'submitted')) // TODO: Kepala Unit hanya melihat permintaan dari unitnya sendiri, bukan semua unit.
            ->when($isStaffUmum, fn ($query) => $query->where('status', 'approved_by_head'))
            ->latest('date')
            ->latest('created_at')
            ->paginate(15)
            ->withQueryString();

        $summary = [
            'pending' => ModelsRequest::whereIn('status', [
                'submitted', 
                'approved_by_head', 
                'processed_by_staff'
            ])->count(),
            'bulan_ini' => ModelsRequest::whereBetween('date', [now()->startOfMonth(), now()->endOfMonth()])->count(),
            'selesai_bulan_ini' => ModelsRequest::whereIn('status', ['completed'])
                ->whereBetween('date', [now()->startOfMonth(), now()->endOfMonth()])
                ->count(),
        ];

        return view('pages.request.index', [
            'title' => 'Request',
            'requests' => $requests,
            'summary' => $summary,
            'keyword' => $keyword,
            'type' => $type,
            'status' => $status,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'isKepalaUnit' => $isKepalaUnit,
            'isStaffUmum' => $isStaffUmum,
            'isAdmin' => $isAdmin,
        ]);
    }
    public function create()
    {
        $items = Item::where('is_active', true)->get();
        return view('pages.request.create', [
            'title' => 'Request',
            'items' => $items
        ]);
    }

    public function edit(ModelsRequest $request)
    {
        $request->load('item');
        $items = Item::where('is_active', true)->get();
        return view('pages.request.edit', [
            'title' => 'Ubah Kuantitas Permintaan Barang',
            'request' => $request,
            'items' => $items
        ]);
    }

    public function show(ModelsRequest $request)
    {
        $request->load(['user', 'item']);

        return view('pages.request.show', [
            'title' => 'Detail Permintaan',
            'requestData' => $request,
        ]);
    }

    public function store(StorePermintaanBarangRequest $request)
    {
        $validatedData = $request->validated();
        
        DB::transaction(function () use ($request, $validatedData) {
        $itemRequest = ModelsRequest::create([
            'user_id' => $request->user()->user_id,
            'date' => now(),
            'type' => 'normal',
            'status' => 'submitted',
            'request_number' => 'REQ-' . time(),
        ]);

        $details = [];

        foreach ($validatedData['items'] as $item) {
            $details[] = [
                'request_id' => $itemRequest->request_id,
                'item_id' => $item['item_id'],
                'quantity_requested' => $item['qty'],
                'quantity_approved' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        RequestDetail::insert($details);
    });

        return redirect()->route('request.create')->with('success', 'Permintaan barang berhasil dibuat.');
    }

    public function update(UpdatePermintaanBarangRequest $httpRequest, ModelsRequest $request)
    {
        if ($request->status !== 'approved_by_head') {
            return redirect()
                ->route('request.index')
                ->with('error', 'Permintaan ini tidak dapat diubah.');
        }

        $validatedData = $httpRequest->validated();

        DB::transaction(function () use ($request, $validatedData) {
            $request->item()->delete();

            $details = [];
            foreach ($validatedData['items'] as $item) {
                $details[] = [
                    'request_id' => $request->request_id,
                    'item_id' => $item['item_id'],
                    'quantity_requested' => $item['qty'],
                    'quantity_approved' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            RequestDetail::insert($details);
        });

        return redirect()
            ->route('request.index')
            ->with('success', 'Permintaan barang berhasil diperbarui.');
    }

    public function respondByRole(ModelsRequest $request, Request $httpRequest)
    {
        $action = $httpRequest->input('action');
        $user = $httpRequest->user();

        if (!in_array($action, ['approve', 'reject'])) {
            return redirect()->back()->with('error', 'Aksi tidak valid.');
        }

        if ($action === 'reject') {
            $request->update(['status' => 'rejected']);
            return redirect()->back()->with('success', 'Permintaan telah ditolak.');
        }

        if($action === 'approve') {
            if ($user->hasRole(UserRole::KepalaUnit) && $request->status === 'submitted') {
                $request->update(['status' => 'approved_by_head']);
            } elseif ($user->hasRole(UserRole::StaffUmum) && $request->status === 'approved_by_head') {
                $request->update(['status' => 'processed_by_staff']);
            } elseif ($user->isAdmin() && $request->status === 'processed_by_staff') {
                $request->update(['status' => 'completed']);
            } else {
                return redirect()->back()->with('error', 'Anda tidak memiliki izin untuk menyetujui permintaan ini.');
            }
        }
        $request->save();

        return redirect()->route('request.index')->with('success', 'Permintaan berhasil diproses.');
    }
}
