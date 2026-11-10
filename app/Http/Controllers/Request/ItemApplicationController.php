<?php

namespace App\Http\Controllers\Request;

use App\Enums\AuditEvent;
use App\Enums\AuditModule;
use App\Enums\ItemApplicationStatus;
use App\Enums\SatuanBarang;
use App\Http\Controllers\Controller;
use App\Http\Requests\Request\RejectItemApplicationRequest;
use App\Http\Requests\Request\StoreItemApplicationRequest;
use App\Models\ItemApplication;
use App\Services\ItemApplicationWorkflowService;
use App\Support\AuditLogger;
use App\Support\ItemApplicationDocumentGenerator;
use App\Support\KatalogBarang;
use App\Support\NotificationScanner;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Permohonan barang baru dan tambah stok.
 *
 * Satu-satunya pintu dari sub sistem Permintaan ke pengadaan: formulir
 * permintaan tidak lagi menghitung selisih stok menjadi draf usulan maupun
 * menerima barang yang belum terdaftar. Persetujuan bertahapnya, termasuk
 * siapa yang berhak di tiap tahap, ada di ItemApplicationWorkflowService.
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
        $tabs = ['' => ['label' => 'Semua', 'ikon' => 'layers', 'warna' => 'gray', 'jumlah' => (int) $jumlahStatus->sum()]];
        foreach (ItemApplicationStatus::cases() as $status) {
            $tabs[$status->value] = [
                'label' => $status->label(),
                'ikon' => $status->icon(),
                'warna' => $status->warna(),
                'jumlah' => (int) ($jumlahStatus[$status->value] ?? 0),
            ];
        }

        // Kartu ringkasan tidak mengikuti pencarian, sama dengan daftar permintaan.
        $visible = fn () => ItemApplication::query()->terlihatOleh($request->user());
        $bulanIni = [now()->startOfMonth(), now()->endOfMonth()];
        $summary = [
            'menunggu' => $visible()->whereIn('status', array_filter(ItemApplicationStatus::cases(), fn (ItemApplicationStatus $status) => $status->menunggu()))->count(),
            'bulan_ini' => $visible()->whereBetween('date', $bulanIni)->count(),
            'disetujui_bulan_ini' => $visible()->where('status', ItemApplicationStatus::Disetujui)->whereBetween('date', $bulanIni)->count(),
        ];

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
            'summary' => $summary,
        ]);
    }

    public function create(Request $request): View
    {
        return view('pages.request.applications.create', [
            'title' => 'Ajukan Permohonan',
            'items' => KatalogBarang::untukPemilih(),
            'subcategories' => KatalogBarang::subkategori(),
            'satuan' => SatuanBarang::options(),
            // `__SEQ__` diganti nomor urut yang diketik, untuk pratinjau.
            'ndTemplate' => (new ItemApplication(['date' => now()]))
                ->setRelation('user', $request->user())
                ->nomorNotaDinas('__SEQ__'),
        ]);
    }

    /**
     * Nota Dinas terbit di sini tanpa tanda tangan, sudah bernomor dari nomor
     * urut yang diisi pemohon, sama dengan alur permintaan.
     */
    public function store(
        StoreItemApplicationRequest $request,
        ItemApplicationWorkflowService $workflow,
        ItemApplicationDocumentGenerator $documents,
    ): RedirectResponse {
        $validated = $request->validated();

        $application = DB::transaction(function () use ($request, $validated, $workflow): ItemApplication {
            $application = ItemApplication::create([
                'application_number' => 'PMH-'.now()->format('YmdHis').'-'.Str::upper(Str::random(4)),
                'user_id' => $request->user()->user_id,
                'date' => now(),
                'purpose' => $validated['purpose'],
                'status' => ItemApplicationStatus::Diajukan,
            ]);

            // Nomor yang sudah dipakai menggagalkan seluruh transaksi dengan
            // galat validasi pada kolom nomor urut.
            $workflow->assignNotaDinasNumber($application, $validated['nd_sequence']);

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

        $documents->generateNotaDinas($application->fresh(['user', 'details.item']));

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
            ->with('status', sprintf(
                'Permohonan %s berhasil diajukan dan menunggu persetujuan %s.',
                $application->application_number,
                $request->user()->unit?->sebutanKepala() ?? 'pimpinan unit',
            ));
    }

    public function show(ItemApplication $itemApplication, Request $request): View
    {
        $this->assertTerlihat($itemApplication, $request);

        return view('pages.request.applications.show', [
            'title' => 'Detail Permohonan',
            'application' => $itemApplication->load([
                'user',
                'headApprovedBy',
                'reviewedBy',
                'decidedBy',
                'details.item',
                'details.itemProposal.item',
                'details.procurementRequest',
            ]),
            'hasNotaDinas' => $this->hasNotaDinas($itemApplication),
        ]);
    }

    public function notaDinas(ItemApplication $itemApplication, Request $request): BinaryFileResponse
    {
        $this->assertTerlihat($itemApplication, $request);

        abort_unless($this->hasNotaDinas($itemApplication), 404);

        $filename = 'nota-dinas-'.Str::slug(str_replace('/', '-', $itemApplication->nd_number));

        return response()->file(Storage::disk('local')->path($itemApplication->notaDinasPath()), [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'Content-Disposition' => 'inline; filename="'.$filename.'.docx"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }

    /**
     * Halaman publik tujuan QR pada Nota Dinas yang sudah disetujui.
     */
    public function verifyNotaDinas(ItemApplication $itemApplication, string $token): View
    {
        abort_unless(
            filled($itemApplication->nd_verification_hash)
                && hash_equals($itemApplication->nd_verification_hash, hash('sha256', $token)),
            404,
        );

        $itemApplication->load(['user', 'headApprovedBy']);

        return view('pages.request.verifikasi-dokumen', [
            'title' => 'Verifikasi Nota Dinas',
            'jenis' => 'Nota Dinas Permohonan Barang',
            'keterangan' => 'Nota dinas ini tercatat sebagai dokumen yang disetujui secara digital oleh sistem.',
            'bagian' => [
                ['judul' => 'Dokumen', 'rincian' => [
                    'Nomor dokumen' => $itemApplication->nd_number ?? 'Belum diberi nomor',
                    'Nomor permohonan' => $itemApplication->application_number,
                ]],
                ['judul' => 'Pemohon', 'rincian' => [
                    'Nama' => $itemApplication->user?->fullname ?? '-',
                    'Unit kerja' => $itemApplication->user?->unit?->label() ?? '-',
                ]],
                ['judul' => 'Persetujuan', 'rincian' => [
                    'Disetujui oleh' => $itemApplication->headApprovedBy?->fullname ?? '-',
                    'Jabatan' => $itemApplication->user?->unit?->jabatanKepala() ?? '-',
                    'Waktu persetujuan' => RequestController::waktuPersetujuan($itemApplication->head_approved_at),
                ]],
            ],
        ]);
    }

    private function assertTerlihat(ItemApplication $itemApplication, Request $request): void
    {
        abort_unless(
            ItemApplication::query()->whereKey($itemApplication->getKey())->terlihatOleh($request->user())->exists(),
            403,
        );
    }

    /**
     * Permohonan dari sebelum alur Nota Dinas berlaku tidak punya dokumen.
     */
    private function hasNotaDinas(ItemApplication $itemApplication): bool
    {
        return $itemApplication->nd_number !== null
            && Storage::disk('local')->exists($itemApplication->notaDinasPath());
    }

    public function approve(
        ItemApplication $itemApplication,
        Request $request,
        ItemApplicationWorkflowService $workflow,
    ): RedirectResponse {
        try {
            $application = $workflow->approve($itemApplication, $request->user());
        } catch (AuthorizationException $exception) {
            return redirect()->route('item-applications.show', $itemApplication)->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('item-applications.show', $itemApplication)
            ->with('status', "Permohonan {$application->application_number} disetujui. {$application->status->description()}");
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
