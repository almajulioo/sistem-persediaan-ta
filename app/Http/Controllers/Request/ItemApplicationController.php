<?php

namespace App\Http\Controllers\Request;

use App\Enums\AuditEvent;
use App\Enums\AuditModule;
use App\Enums\ItemApplicationStatus;
use App\Enums\SatuanBarang;
use App\Http\Controllers\Controller;
use App\Http\Requests\Request\RejectItemApplicationRequest;
use App\Http\Requests\Request\StoreItemApplicationRequest;
use App\Models\Item;
use App\Models\ItemApplication;
use App\Services\ItemApplicationWorkflowService;
use App\Support\AuditLogger;
use App\Support\NotificationScanner;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Permohonan barang baru dan tambah stok.
 *
 * Satu-satunya pintu dari sub sistem Permintaan ke pengadaan: formulir
 * permintaan tidak lagi menghitung selisih stok menjadi draf usulan maupun
 * menerima barang yang belum terdaftar. Keputusan ada di
 * ItemApplicationWorkflowService.
 */
class ItemApplicationController extends Controller
{
    public function index(Request $request): View
    {
        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'status' => (string) $request->query('status', ''),
        ];

        // Angka di tiap tab mengikuti pencarian yang sedang aktif.
        $searched = fn () => ItemApplication::query()
            ->terlihatOleh($request->user())
            ->filter(['q' => $filters['q']]);

        $jumlahStatus = $searched()
            ->selectRaw('status, count(*) as jumlah')
            ->groupBy('status')
            ->pluck('jumlah', 'status');
        $tabs = ['' => ['label' => 'Semua', 'jumlah' => (int) $jumlahStatus->sum()]];
        foreach (ItemApplicationStatus::cases() as $status) {
            $tabs[$status->value] = ['label' => $status->label(), 'jumlah' => (int) ($jumlahStatus[$status->value] ?? 0)];
        }

        $applications = $searched()
            ->with(['user', 'details.item'])
            ->filter(['status' => $filters['status']])
            ->latest('date')
            ->latest('created_at')
            ->paginate(15)
            ->withQueryString();

        return view('pages.request.applications.index', [
            'title' => 'Permohonan',
            'applications' => $applications,
            'filters' => $filters,
            'tabs' => $tabs,
        ]);
    }

    public function create(): View
    {
        return view('pages.request.applications.create', [
            'title' => 'Ajukan Permohonan',
            'items' => Item::aktif()->orderBy('item_name')->get(['item_id', 'item_code', 'item_name', 'unit', 'stock_current']),
            'satuan' => SatuanBarang::options(),
        ]);
    }

    public function store(StoreItemApplicationRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $application = DB::transaction(function () use ($request, $validated): ItemApplication {
            $application = ItemApplication::create([
                'application_number' => 'PMH-'.now()->format('YmdHis').'-'.Str::upper(Str::random(4)),
                'user_id' => $request->user()->user_id,
                'date' => now(),
                'purpose' => $validated['purpose'],
                'status' => ItemApplicationStatus::Diajukan,
            ]);

            $application->details()->createMany(array_map(
                fn (array $item): array => [
                    'type' => $item['type'],
                    'item_id' => $item['item_id'],
                    'item_name' => $item['item_name'],
                    'unit' => $item['unit'],
                    'note' => $item['note'],
                    'quantity' => $item['quantity'],
                ],
                $validated['items'],
            ));

            return $application;
        });

        AuditLogger::record(
            user: $request->user(),
            activity: "Mengajukan permohonan {$application->application_number}: {$application->ringkasan()}",
            module: AuditModule::Permohonan,
            event: AuditEvent::Ajukan,
            subject: $application,
        );
        NotificationScanner::periksaPermohonan($application->fresh(['user', 'details']));

        return redirect()
            ->route('item-applications.show', $application)
            ->with('status', "Permohonan {$application->application_number} berhasil diajukan dan menunggu ditinjau Pengelola Gudang.");
    }

    public function show(ItemApplication $itemApplication, Request $request): View
    {
        abort_unless(
            ItemApplication::query()->whereKey($itemApplication->getKey())->terlihatOleh($request->user())->exists(),
            403,
        );

        return view('pages.request.applications.show', [
            'title' => 'Detail Permohonan',
            'application' => $itemApplication->load([
                'user',
                'decidedBy',
                'details.item',
                'details.itemProposal.item',
                'details.procurementRequest',
            ]),
        ]);
    }

    public function approve(
        ItemApplication $itemApplication,
        Request $request,
        ItemApplicationWorkflowService $workflow,
    ): RedirectResponse {
        try {
            $workflow->approve($itemApplication, $request->user());
        } catch (AuthorizationException $exception) {
            return redirect()->route('item-applications.show', $itemApplication)->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('item-applications.show', $itemApplication)
            ->with('status', "Permohonan {$itemApplication->application_number} disetujui dan diteruskan.");
    }

    public function reject(
        RejectItemApplicationRequest $request,
        ItemApplication $itemApplication,
        ItemApplicationWorkflowService $workflow,
    ): RedirectResponse {
        try {
            $workflow->reject($itemApplication, $request->user(), $request->validated()['decision_note']);
        } catch (AuthorizationException $exception) {
            return redirect()->route('item-applications.show', $itemApplication)->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('item-applications.show', $itemApplication)
            ->with('status', "Permohonan {$itemApplication->application_number} ditolak.");
    }
}
