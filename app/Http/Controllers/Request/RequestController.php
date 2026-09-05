<?php

namespace App\Http\Controllers\Request;

use App\Http\Controllers\Controller;
use App\Http\Requests\Request\StorePermintaanBarangRequest;
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
            ->latest('date')
            ->latest('created_at')
            ->paginate(15)
            ->withQueryString();

        $summary = [
            'pending' => ModelsRequest::where('status', 'pending')->count(),
            'bulan_ini' => ModelsRequest::whereBetween('date', [now()->startOfMonth(), now()->endOfMonth()])->count(),
            'selesai_bulan_ini' => ModelsRequest::whereIn('status', ['selesai', 'completed'])
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
            'status' => 'pending',
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
}
