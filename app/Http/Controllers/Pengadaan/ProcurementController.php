<?php

namespace App\Http\Controllers\Pengadaan;

use App\Enums\ProcurementStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pengadaan\StoreProcurementRequest;
use App\Models\Procurement;
use App\Models\ProcurementRequest;
use App\Models\Supplier;
use App\Support\AuditLogger;
use App\Support\NotificationScanner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Realisasi pengadaan — dibuat Pejabat Pengadaan dari satu usulan yang sudah
 * disetujui, menautkannya ke penyedia. Berhenti di status MenungguPenerimaan
 * sampai Pengelola Gudang mengonfirmasi penerimaan lewat
 * ProcurementReceiptController; stok baru bertambah saat itu. Lihat ADR 0008,
 * ADR 0015, dan ADR 0016.
 */
class ProcurementController extends Controller
{
    private const PER_PAGE = 10;

    public function index(Request $request): View
    {
        $filters = [
            'status' => (string) $request->query('status', ''),
        ];

        return view('pages.procurements.index', [
            'title' => 'Realisasi Pengadaan',
            'procurements' => Procurement::query()
                ->filter($filters)
                ->with(['supplier', 'details'])
                ->latest('procurement_date')
                ->latest('created_at')
                ->paginate(self::PER_PAGE)
                ->withQueryString(),
            'filters' => $filters,
            'summary' => [
                'total' => Procurement::count(),
                'menunggu' => Procurement::where('status', ProcurementStatus::MenungguPenerimaan)->count(),
                'diterima' => Procurement::where('status', ProcurementStatus::Diterima)->count(),
            ],
        ]);
    }

    /**
     * Formulir hanya bisa dibuka dari satu usulan yang sudah disetujui dan
     * belum punya realisasi — tombol "Realisasikan" pada halaman usulan.
     */
    public function create(Request $request): View|RedirectResponse
    {
        $procurementRequest = ProcurementRequest::with('details.item')
            ->find((string) $request->query('procurement_request_id', ''));

        if ($procurementRequest === null) {
            return redirect()
                ->route('procurement-requests.index')
                ->with('error', 'Usulan pengadaan tidak ditemukan.');
        }

        if (! $procurementRequest->isDisetujui() || $procurementRequest->procurement()->exists()) {
            return redirect()
                ->route('procurement-requests.show', $procurementRequest)
                ->with('error', 'Usulan ini tidak dapat direalisasikan. Realisasi hanya dari usulan yang sudah disetujui dan belum memiliki realisasi.');
        }

        return view('pages.procurements.create', [
            'title' => 'Realisasi Pengadaan',
            'procurementRequest' => $procurementRequest,
            'rincianDisetujui' => $procurementRequest->details->filter(fn ($detail) => $detail->disetujui())->values(),
            'suppliers' => Supplier::aktif()->orderBy('supplier_name')->get(['supplier_id', 'supplier_name']),
        ]);
    }

    public function store(StoreProcurementRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $rincian = $request->rincianDisetujui();

        $procurement = DB::transaction(function () use ($request, $data, $rincian) {
            $procurement = Procurement::create([
                'procurement_request_id' => $data['procurement_request_id'],
                'supplier_id' => $data['supplier_id'],
                'procurement_date' => $data['procurement_date'],
                'status' => ProcurementStatus::MenungguPenerimaan,
                'user_id' => $request->user()->user_id,
            ]);

            $procurement->details()->createMany(
                array_map(fn (array $item) => [
                    'item_id' => $rincian->get($item['procurement_request_detail_id'])->item_id,
                    'amount' => $item['amount'],
                    'unit_price' => $item['unit_price'],
                ], $data['items']),
            );

            return $procurement;
        });

        AuditLogger::record($request->user(), "Membuat realisasi pengadaan: {$procurement->judul()}");
        NotificationScanner::periksaRealisasi($procurement->fresh('supplier'));

        return redirect()
            ->route('procurements.show', $procurement)
            ->with('status', "{$procurement->judul()} berhasil dibuat dan menunggu penerimaan barang.");
    }

    public function show(Procurement $procurement): View
    {
        return view('pages.procurements.show', [
            'title' => $procurement->judul(),
            'procurement' => $procurement->load([
                'user', 'supplier', 'procurementRequest', 'details.item', 'receipt.receivedBy',
            ]),
        ]);
    }
}
