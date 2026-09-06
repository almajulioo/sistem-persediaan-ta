<?php

namespace App\Http\Controllers\Pengadaan;

use App\Enums\ProcurementRequestSource;
use App\Enums\ProcurementRequestStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pengadaan\ApproveProcurementRequestRequest;
use App\Http\Requests\Pengadaan\StoreProcurementRequestRequest;
use App\Models\Item;
use App\Models\ProcurementRequest;
use App\Support\AuditLogger;
use App\Support\NotificationScanner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Usulan pengadaan — permintaan Pengelola Gudang kepada Pejabat Pengadaan.
 *
 * Mengusulkan dan memutuskan sengaja dipisah gate berbeda (lihat
 * routes/web.php): Pengelola Gudang mengusulkan lewat `kelola-gudang`,
 * Pejabat Pengadaan menyetujui/menolak lewat `kelola-pengadaan`, dan gate
 * `akses-pengadaan` meloloskan keduanya untuk membaca. Lihat ADR 0016.
 */
class ProcurementRequestController extends Controller
{
    private const PER_PAGE = 10;

    public function index(Request $request): View
    {
        $filters = [
            'status' => (string) $request->query('status', ''),
            'source' => (string) $request->query('source', ''),
        ];

        return view('pages.procurement-requests.index', [
            'title' => 'Usulan Pengadaan',
            'usulan' => ProcurementRequest::query()
                ->filter($filters)
                ->with(['user', 'details'])
                ->latest('created_at')
                ->paginate(self::PER_PAGE)
                ->withQueryString(),
            'filters' => $filters,
            'summary' => [
                'total' => ProcurementRequest::count(),
                'diajukan' => ProcurementRequest::where('status', ProcurementRequestStatus::Diajukan)->count(),
                'disetujui' => ProcurementRequest::where('status', ProcurementRequestStatus::Disetujui)->count(),
                'ditolak' => ProcurementRequest::where('status', ProcurementRequestStatus::Ditolak)->count(),
            ],
        ]);
    }

    public function create(Request $request): View
    {
        // Jalur pintas dari notifikasi Stok Habis/Menipis: barang datang
        // sudah terisi dan sumbernya otomatis PemantauanStok (ADR 0016).
        $prefillItem = $request->filled('item_id')
            ? Item::aktif()->find((string) $request->query('item_id'))
            : null;

        return view('pages.procurement-requests.create', [
            'title' => 'Ajukan Usulan Pengadaan',
            'items' => Item::aktif()->orderBy('item_code')->get(['item_id', 'item_code', 'item_name', 'unit', 'stock_current']),
            'prefillItem' => $prefillItem,
            'source' => $prefillItem !== null
                ? ProcurementRequestSource::PemantauanStok->value
                : ProcurementRequestSource::Manual->value,
        ]);
    }

    public function store(StoreProcurementRequestRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $procurementRequest = DB::transaction(function () use ($request, $data) {
            $procurementRequest = ProcurementRequest::create([
                'user_id' => $request->user()->user_id,
                'source' => $data['source'],
                'status' => ProcurementRequestStatus::Diajukan,
            ]);

            $procurementRequest->details()->createMany(
                array_map(fn (array $item) => [
                    'item_id' => $item['item_id'],
                    'amount_request' => $item['amount_request'],
                ], $data['items']),
            );

            return $procurementRequest;
        });

        AuditLogger::record($request->user(), "Mengajukan usulan pengadaan: {$procurementRequest->judul()}");
        NotificationScanner::periksaUsulan($procurementRequest);

        return redirect()
            ->route('procurement-requests.show', $procurementRequest)
            ->with('status', "{$procurementRequest->judul()} berhasil diajukan.");
    }

    public function show(ProcurementRequest $procurementRequest): View
    {
        return view('pages.procurement-requests.show', [
            'title' => $procurementRequest->judul(),
            'procurementRequest' => $procurementRequest->load(['user', 'details.item']),
        ]);
    }

    public function approve(ApproveProcurementRequestRequest $request, ProcurementRequest $procurementRequest): RedirectResponse
    {
        if (! $procurementRequest->isDiajukan()) {
            return back()->with('error', "{$procurementRequest->judul()} sudah diputuskan sebelumnya.");
        }

        DB::transaction(function () use ($request, $procurementRequest) {
            foreach ($request->validated()['granted'] as $detailId => $jumlah) {
                $procurementRequest->details()->whereKey($detailId)->update(['amount_granted' => (int) $jumlah]);
            }

            $procurementRequest->forceFill(['status' => ProcurementRequestStatus::Disetujui])->save();
        });

        AuditLogger::record($request->user(), "Menyetujui usulan pengadaan: {$procurementRequest->judul()}");
        NotificationScanner::catatUsulanDiputuskan($procurementRequest->fresh(['user', 'details']), $request->user());

        return redirect()
            ->route('procurement-requests.show', $procurementRequest)
            ->with('status', "{$procurementRequest->judul()} disetujui.");
    }

    public function reject(Request $request, ProcurementRequest $procurementRequest): RedirectResponse
    {
        if (! $procurementRequest->isDiajukan()) {
            return back()->with('error', "{$procurementRequest->judul()} sudah diputuskan sebelumnya.");
        }

        DB::transaction(function () use ($procurementRequest) {
            // amount_granted = 0 dicatat eksplisit untuk seluruh barang, bukan
            // dibiarkan null, karena null berarti "belum diputuskan" pada
            // model ini (lihat ProcurementRequestDetail::sudahDiputuskan()).
            $procurementRequest->details()->update(['amount_granted' => 0]);
            $procurementRequest->forceFill(['status' => ProcurementRequestStatus::Ditolak])->save();
        });

        AuditLogger::record($request->user(), "Menolak usulan pengadaan: {$procurementRequest->judul()}");
        NotificationScanner::catatUsulanDiputuskan($procurementRequest->fresh(['user', 'details']), $request->user());

        return redirect()
            ->route('procurement-requests.show', $procurementRequest)
            ->with('status', "{$procurementRequest->judul()} ditolak.");
    }
}
