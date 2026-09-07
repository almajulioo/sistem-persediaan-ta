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
use App\Support\RequestDocumentGenerator;
use App\Support\NotificationScanner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

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
        $isPengelolaGudang = $request->user()->hasRole(UserRole::PengelolaGudang);
        $isAdmin = $request->user()->isAdmin();
        $isUser = $request->user()->hasRole(UserRole::Pengguna);

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
            ->when($isKepalaUnit, function ($query) use ($request) {
                $query->where('status', 'submitted')
                    ->whereHas('user', fn ($userQuery) => $userQuery->where('unit', $request->user()->unit));
            })
            ->when($isPengelolaGudang, fn ($query) => $query->where('status', 'approved_by_head'))
            ->when($isUser, fn ($query) => $query->where('user_id', $request->user()->user_id))
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
            'isPengelolaGudang' => $isPengelolaGudang,
            'isAdmin' => $isAdmin,
            'isUser' => $isUser,
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

    public function notaDinas(ModelsRequest $request)
    {
        $notaDinas = $request->requestDocuments()
            ->where('document_type', 'nota_dinas')
            ->with('user')
            ->latest('created_at')
            ->first();

        $viewer = auth()->user();
        $canView = $viewer->hasRole(UserRole::Admin)
            || $viewer->hasRole(UserRole::PengelolaGudang)
            || ($viewer->hasRole(UserRole::Pengguna)
                && $notaDinas?->user_id === $viewer->user_id)
            || ($viewer->hasRole(UserRole::KepalaUnit)
                && filled($viewer->unit)
                && $viewer->unit === $notaDinas?->user?->unit);

        abort_unless($canView, 403);

        abort_unless($notaDinas && Storage::disk('local')->exists($notaDinas->file_path), 404);

        return response()->file(Storage::disk('local')->path($notaDinas->file_path), [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$notaDinas->document_number.'.pdf"',
        ]);
    }

    public function store(StorePermintaanBarangRequest $request, RequestDocumentGenerator $documentGenerator)
    {
        $validatedData = $request->validated();
        
        $itemRequest = DB::transaction(function () use ($request, $validatedData) {
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

            return $itemRequest;
        });

        $documentGenerator->generateNotaDinas($itemRequest);
        NotificationScanner::catatPermintaanDiajukan($itemRequest->fresh('user'));

        return redirect()->route('request.index')->with('status', 'Permintaan barang berhasil dibuat.');
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
            ->with('status', 'Permintaan barang berhasil diperbarui.');
    }

    public function respondByRole(ModelsRequest $request, Request $httpRequest)
    {
        $action = $httpRequest->input('action');
        $user = $httpRequest->user();
        $request->loadMissing('user');

        if (!in_array($action, ['approve', 'reject'])) {
            return redirect()->back()->with('error', 'Aksi tidak valid.');
        }

        $canRespond = ($user->hasRole(UserRole::KepalaUnit)
                && $request->status === 'submitted'
                && $user->unit === $request->user->unit)
            || ($user->hasRole(UserRole::PengelolaGudang)
                && $request->status === 'approved_by_head')
            || ($user->isAdmin() && $request->status === 'processed_by_staff');

        if (!$canRespond) {
            return redirect()->back()->with('error', 'Anda tidak memiliki izin untuk memproses permintaan ini.');
        }

        if ($action === 'reject') {
            $request->update(['status' => 'rejected']);
            NotificationScanner::catatPermintaanDiputuskan($request->fresh('user'));
            return redirect()->back()->with('status', 'Permintaan telah ditolak.');
        }

        if($action === 'approve') {
            if ($user->hasRole(UserRole::KepalaUnit)) {
                $request->update(['status' => 'approved_by_head']);
            } elseif ($user->hasRole(UserRole::PengelolaGudang)) {
                $request->update(['status' => 'processed_by_staff']);
            } elseif ($user->isAdmin()) {
                $request->update(['status' => 'completed']);
            }
        }
        NotificationScanner::catatPermintaanDiputuskan($request->fresh('user'));
        $request->save();

        return redirect()->route('request.index')->with('status', 'Permintaan berhasil diproses.');
    }
}
