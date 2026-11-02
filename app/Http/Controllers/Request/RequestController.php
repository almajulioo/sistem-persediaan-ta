<?php

namespace App\Http\Controllers\Request;

use App\Http\Controllers\Controller;
use App\Enums\UserRole;
use App\Enums\UnitKerja;
use App\Enums\ItemProposalStatus;
use App\Enums\RequestHistoryAction;
use App\Http\Requests\Request\StorePermintaanBarangRequest;
use App\Http\Requests\Request\StorePermintaanUmumRequest;
use App\Http\Requests\Request\UpdatePermintaanBarangRequest;
use App\Http\Requests\Request\UpdatePermintaanUmumRequest;
use App\Http\Requests\Request\MergePermintaanUmumRequest;
use App\Http\Requests\Request\RevertRequestStatusRequest;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use App\Models\Item;
use App\Models\Request as ModelsRequest;
use App\Models\RequestDetail;
use App\Models\RequestHistory;
use App\Models\RequestDocument;
use App\Models\User;
use App\Services\RequestWorkflowService;
use App\Support\AuditLogger;
use App\Enums\AuditEvent;
use App\Enums\AuditModule;
use App\Exceptions\InsufficientStockException;
use App\Support\NotificationScanner;
use App\Support\RequestDocumentGenerator;
use App\Support\StockReservation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class RequestController extends Controller
{
    /**
     * Tab pada daftar permintaan: kelompok status sebagaimana dibaca pemohon.
     * Filter `status` tunggal tetap ada untuk tautan dari dasbor.
     */
    private const TAHAP_DAFTAR = [
        'berjalan' => ['label' => 'Berjalan', 'status' => ['submitted', 'approved_by_head', 'processed_by_staff', 'in_issuance']],
        'diserahkan' => ['label' => 'Diserahkan', 'status' => ['delivered']],
        'selesai' => ['label' => 'Selesai', 'status' => ['completed']],
        'ditolak' => ['label' => 'Ditolak', 'status' => ['rejected']],
    ];

    public function index(Request $request)
    {
        $keyword = trim((string) $request->query('q', ''));
        $type = (string) $request->query('type', '');
        $status = (string) $request->query('status', '');
        $tahap = (string) $request->query('tahap', '');
        $tahap = array_key_exists($tahap, self::TAHAP_DAFTAR) ? $tahap : '';
        $dateFrom = (string) $request->query('date_from', '');
        $dateTo = (string) $request->query('date_to', '');

        $isKepalaUnit = $request->user()->hasRole(UserRole::KepalaUnit);
        $isPengelolaGudang = $request->user()->hasRole(UserRole::PengelolaGudang);
        $isAdmin = $request->user()->isAdmin();
        $isKasubbag = $request->user()->hasRole(UserRole::KasubbagUmumTi);
        $isUser = $request->user()->hasRole(UserRole::Pengguna);

        // Seluruh penyaring kecuali tab dan status, supaya angka di tiap tab
        // menghitung isi tab itu dengan penyaring yang sedang aktif.
        $filtered = fn () => ModelsRequest::query()
            ->where('type', '!=', 'umum')
            ->terlihatOleh($request->user())
            ->when($keyword !== '', function ($query) use ($keyword) {
                $query->where(function ($query) use ($keyword) {
                    $query->where('request_number', 'like', "%{$keyword}%")
                        ->orWhere('nd_number', 'like', "%{$keyword}%")
                        ->orWhereHas('user', fn ($userQuery) => $userQuery->where('fullname', 'like', "%{$keyword}%"));
                });
            })
            ->when($type !== '', fn ($query) => $query->where('type', $type))
            ->when($dateFrom !== '', fn ($query) => $query->whereDate('date', '>=', $dateFrom))
            ->when($dateTo !== '', fn ($query) => $query->whereDate('date', '<=', $dateTo));

        $jumlahStatus = $filtered()
            ->selectRaw('status, count(*) as jumlah')
            ->groupBy('status')
            ->pluck('jumlah', 'status');
        $tabs = ['' => ['label' => 'Semua', 'jumlah' => (int) $jumlahStatus->sum()]]
            + array_map(fn (array $tab) => [
                'label' => $tab['label'],
                'jumlah' => (int) $jumlahStatus->only($tab['status'])->sum(),
            ], self::TAHAP_DAFTAR);

        $requests = $filtered()
            ->with(['user', 'item'])
            ->when($tahap !== '', fn ($query) => $query->whereIn('status', self::TAHAP_DAFTAR[$tahap]['status']))
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->latest('date')
            ->latest('created_at')
            ->paginate(15)
            ->withQueryString();

        $visible = fn () => ModelsRequest::where('type', '!=', 'umum')->terlihatOleh($request->user());
        $summary = [
            'pending' => $visible()->whereIn('status', [...self::TAHAP_DAFTAR['berjalan']['status'], 'delivered'])->count(),
            'bulan_ini' => $visible()->whereBetween('date', [now()->startOfMonth(), now()->endOfMonth()])->count(),
            'selesai_bulan_ini' => $visible()->where('status', 'completed')
                ->whereBetween('date', [now()->startOfMonth(), now()->endOfMonth()])
                ->count(),
        ];

        return view('pages.request.index', [
            'title' => 'Permintaan Barang',
            'requests' => $requests,
            'summary' => $summary,
            'tabs' => $tabs,
            'tahap' => $tahap,
            'keyword' => $keyword,
            'type' => $type,
            'status' => $status,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'isKepalaUnit' => $isKepalaUnit,
            'isPengelolaGudang' => $isPengelolaGudang,
            'isAdmin' => $isAdmin,
            'isKasubbag' => $isKasubbag,
            'isUser' => $isUser,
        ]);
    }

    public function create(Request $request)
    {
        // Formulir menampilkan dan membatasi jumlah dengan stok tersedia,
        // yaitu stok fisik dikurangi yang ditahan permintaan lain.
        $items = Item::where('is_active', true)->orderBy('item_name')->get();
        $reserved = StockReservation::reservedFor($items->pluck('item_id')->all());
        $items = $items->map(fn (Item $item) => [
            'item_id' => $item->item_id,
            'item_code' => $item->item_code,
            'item_name' => $item->item_name,
            'unit' => $item->unit?->value,
            'stock_current' => (int) $item->stock_current,
            'reserved' => $reserved->get($item->item_id, 0),
            'available' => max(0, (int) $item->stock_current - $reserved->get($item->item_id, 0)),
        ]);
        $canCreateEmergency = $request->user()->hasRole(UserRole::Admin, UserRole::KasubbagUmumTi, UserRole::PengelolaGudang);
        $users = $canCreateEmergency
            ? User::query()->where('is_active', true)->orderBy('fullname')->get(['user_id', 'fullname', 'username', 'unit'])
                // Formulir menampilkan dan mencari unit lewat labelnya. Nomor
                // Nota Dinas permintaan darurat mengikuti unit peminta.
                ->map(fn (User $user) => [
                    ...$user->only(['user_id', 'fullname', 'username']),
                    'unit' => $user->unit?->label(),
                    'nd_template' => $this->notaDinasTemplate($user),
                ])
            : collect();

        return view('pages.request.create', [
            'title' => 'Request',
            'items' => $items,
            'users' => $users,
            'canCreateEmergency' => $canCreateEmergency,
            'ndTemplate' => $this->notaDinasTemplate($request->user()),
        ]);
    }

    /**
     * Pola nomor Nota Dinas untuk pratinjau di formulir; `__SEQ__` diganti
     * nomor urut yang diketik. Formatnya tetap satu sumber di
     * ModelsRequest::documentNumber().
     */
    private function notaDinasTemplate(User $user): string
    {
        return (new ModelsRequest(['date' => now()]))
            ->setRelation('user', $user)
            ->documentNumber('ND', '__SEQ__');
    }

    public function createUmum(Request $request)
    {
        $this->assertCanManageUmum($request->user());

        return view('pages.request.umum.create', [
            'title' => 'Buat Permintaan Umum',
            'items' => Item::where('is_active', true)->orderBy('item_name')->get(),
        ]);
    }

    public function storeUmum(StorePermintaanUmumRequest $request)
    {
        $validatedData = $request->validated();
        $actor = $request->user();

        $itemRequest = DB::transaction(function () use ($validatedData, $actor) {
            $itemRequest = ModelsRequest::create([
                'user_id' => $actor->user_id,
                'date' => now(),
                'type' => 'umum',
                'status' => 'submitted',
                'request_number' => 'REQ-UMUM-'.now()->format('YmdHis').'-'.Str::upper(Str::random(4)),
            ]);

            $now = now();
            RequestDetail::insert(array_map(
                fn (array $item) => [
                    'request_id' => $itemRequest->request_id,
                    'item_id' => $item['item_id'],
                    'quantity_requested' => $item['qty'],
                    'quantity_approved' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                $validatedData['items'],
            ));

            RequestHistory::catat($itemRequest, $actor, RequestHistoryAction::Diajukan, null, 'submitted');

            return $itemRequest;
        });

        NotificationScanner::periksaPermintaan($itemRequest->fresh('user'));

        return redirect()->route('request.umum.index')->with('status', 'Permintaan umum berhasil dibuat.');
    }

    /**
     * Gudang dan Admin melihat seluruh permintaan umum beserta aksinya;
     * pemohon dari Subbagian Umum dan TI hanya melihat miliknya, baca-saja.
     */
    public function umumIndex(Request $request)
    {
        $user = $request->user();
        $canManage = $user->hasRole(UserRole::Admin, UserRole::PengelolaGudang);
        abort_unless($canManage || $user->unit === UnitKerja::SubbagianUmumTi, 403);

        // Tab: antrean yang menunggu, yang ditolak, dan histori yang digabung.
        $tabStatus = ['' => 'submitted', 'ditolak' => 'rejected', 'histori' => 'merged'];
        $view = (string) $request->query('view', '');
        $view = array_key_exists($view, $tabStatus) ? $view : '';

        $visible = fn () => ModelsRequest::query()
            ->where('type', 'umum')
            ->when(! $canManage, fn ($query) => $query->where('user_id', $user->user_id));

        $jumlahStatus = $visible()->selectRaw('status, count(*) as jumlah')->groupBy('status')->pluck('jumlah', 'status');
        $tabs = [
            '' => ['label' => 'Menunggu', 'jumlah' => (int) ($jumlahStatus['submitted'] ?? 0)],
            'ditolak' => ['label' => 'Ditolak', 'jumlah' => (int) ($jumlahStatus['rejected'] ?? 0)],
            'histori' => ['label' => 'Digabung', 'jumlah' => (int) ($jumlahStatus['merged'] ?? 0)],
        ];

        $requests = $visible()
            ->with(['user', 'item.item', 'mergedInto'])
            ->where('status', $tabStatus[$view])
            ->latest('date')
            ->latest('created_at')
            ->paginate(15)
            ->withQueryString();

        return view('pages.request.umum.index', [
            'title' => 'Permintaan Umum',
            'requests' => $requests,
            'view' => $view,
            'tabs' => $tabs,
            'isHistory' => $view === 'histori',
            'canManage' => $canManage,
        ]);
    }

    public function editUmum(ModelsRequest $request)
    {
        abort_unless(
            $request->type === 'umum'
                && $request->status === 'submitted'
                && auth()->user()->hasRole(UserRole::Admin, UserRole::PengelolaGudang),
            404,
        );

        return view('pages.request.umum.create', [
            'title' => 'Ubah Permintaan Umum',
            'items' => Item::where('is_active', true)->orderBy('item_name')->get(),
            'requestData' => $request->load('item.item'),
            'isEdit' => true,
        ]);
    }

    public function updateUmum(UpdatePermintaanUmumRequest $httpRequest, ModelsRequest $request)
    {
        $validatedData = $httpRequest->validated();

        DB::transaction(function () use ($validatedData, $request, $httpRequest): void {
            abort_unless($request->fresh()->status === 'submitted', 409);

            $changes = $httpRequest->changes();

            $details = collect($validatedData['items'])
                ->filter(fn (array $item): bool => (int) $item['qty'] > 0)
                ->map(fn (array $item): array => [
                    'request_id' => $request->request_id,
                    'item_id' => $item['item_id'],
                    'quantity_requested' => $item['qty'],
                    'quantity_approved' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
                ->values()
                ->all();

            $request->item()->delete();
            if ($details !== []) {
                RequestDetail::insert($details);
            }

            if ($changes !== []) {
                RequestHistory::catat(
                    $request,
                    $httpRequest->user(),
                    RequestHistoryAction::KuantitasDiubah,
                    'submitted',
                    'submitted',
                    $validatedData['adjustment_note'] ?? null,
                    $changes,
                );
            }
        });

        return redirect()->route('request.umum.index')->with('status', 'Permintaan umum berhasil diperbarui.');
    }

    public function rejectUmum(Request $httpRequest, ModelsRequest $request)
    {
        abort_unless($httpRequest->user()->hasRole(UserRole::Admin, UserRole::PengelolaGudang), 403);
        abort_unless($request->type === 'umum' && $request->status === 'submitted', 409);

        $note = trim((string) $httpRequest->validate(
            ['note' => ['required', 'string', 'max:500'], 'reject_request_id' => ['nullable']],
            ['note.required' => 'Alasan penolakan wajib diisi supaya pemohon tahu mengapa permintaannya ditolak.'],
        )['note']);

        DB::transaction(function () use ($request, $httpRequest, $note): void {
            $request->update(['status' => 'rejected']);
            RequestHistory::catat($request, $httpRequest->user(), RequestHistoryAction::Ditolak, 'submitted', 'rejected', $note);
        });

        NotificationScanner::periksaPermintaan($request->fresh('user'));

        return redirect()->route('request.umum.index')->with('status', 'Permintaan umum ditolak.');
    }

    public function mergeUmum(MergePermintaanUmumRequest $httpRequest)
    {
        $requestIds = $httpRequest->validated()['request_ids'];
        $kasubbag = User::where('role', UserRole::KasubbagUmumTi->value)
            ->where('is_active', true)
            ->firstOrFail();

        $actor = $httpRequest->user();

        $mergedRequest = DB::transaction(function () use ($requestIds, $kasubbag, $actor): ModelsRequest {
            $requests = ModelsRequest::whereIn('request_id', $requestIds)
                ->where('type', 'umum')
                ->where('status', 'submitted')
                ->with('item')
                ->lockForUpdate()
                ->get();

            abort_unless($requests->count() === count(array_unique($requestIds)), 409);

            $items = $requests->flatMap->item
                ->filter(fn (RequestDetail $detail): bool => (int) $detail->quantity_requested > 0)
                ->groupBy('item_id')
                ->map(fn ($details): int => $details->sum('quantity_requested'));

            abort_unless($items->isNotEmpty(), 422, 'Tidak ada barang yang dapat digabung.');

            $mergedRequest = ModelsRequest::create([
                'user_id' => $kasubbag->user_id,
                'date' => now(),
                'type' => 'emergency',
                'status' => 'submitted',
                'request_number' => 'REQ-' . time() . '-' . Str::upper(Str::random(4)),
            ]);

            $now = now();
            RequestDetail::insert($items->map(fn (int $quantity, string $itemId): array => [
                'request_id' => $mergedRequest->request_id,
                'item_id' => $itemId,
                'quantity_requested' => $quantity,
                'quantity_approved' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ])->values()->all());

            ModelsRequest::whereIn('request_id', $requests->pluck('request_id'))->update([
                'status' => 'merged',
                'merged_into_request_id' => $mergedRequest->request_id,
            ]);

            RequestHistory::catat(
                $mergedRequest,
                $actor,
                RequestHistoryAction::Diajukan,
                null,
                'submitted',
                'Gabungan dari permintaan umum: '.$requests->pluck('request_number')->implode(', ').'.',
            );

            foreach ($requests as $original) {
                RequestHistory::catat(
                    $original,
                    $actor,
                    RequestHistoryAction::Digabung,
                    'submitted',
                    'merged',
                    "Digabung ke permintaan darurat {$mergedRequest->request_number}.",
                );
            }

            return $mergedRequest;
        });

        app(RequestDocumentGenerator::class)->generateNotaDinas($mergedRequest->fresh(['user', 'item.itemProposal']));

        ModelsRequest::with('user')
            ->where('merged_into_request_id', $mergedRequest->request_id)
            ->get()
            ->push($mergedRequest->fresh('user'))
            ->each(fn (ModelsRequest $request) => NotificationScanner::periksaPermintaan($request));

        return redirect()->route('request.index')->with('status', 'Permintaan umum berhasil digabung menjadi permintaan darurat.');
    }

    private function assertCanManageUmum(User $user): void
    {
        abort_unless(
            $user->isAdmin()
                || $user->hasRole(UserRole::PengelolaGudang)
                || $user->unit === UnitKerja::SubbagianUmumTi,
            403,
        );
    }

    public function edit(ModelsRequest $request)
    {
        if ($request->hasItemProposals()) {
            return redirect()
                ->route('request.show', $request)
                ->with('error', 'Putuskan seluruh usulan barang terlebih dahulu sebelum mengubah permintaan.');
        }

        $request->load('item');
        $items = Item::where('is_active', true)->get();
        return view('pages.request.edit', [
            'title' => 'Ubah Kuantitas Permintaan Barang',
            'request' => $request,
            'items' => $items
        ]);
    }

    public function show(ModelsRequest $request, Request $httpRequest)
    {
        $viewer = $httpRequest->user();
        $request->load(['user', 'item', 'histories.user']);
        $notaDinas = $request->requestDocuments()
            ->where('document_type', 'nota_dinas')
            ->latest('created_at')
            ->first();
        $bast = $request->requestDocuments()
            ->where('document_type', 'bast')
            ->latest('created_at')
            ->first();

        return view('pages.request.show', [
            'title' => 'Detail Permintaan',
            'requestData' => $request,
            'hasNotaDinas' => $notaDinas && Storage::disk('local')->exists($notaDinas->file_path),
            'hasBast' => $bast && Storage::disk('local')->exists($bast->file_path),
            'isKepalaUnit' => $viewer->hasRole(UserRole::KepalaUnit),
            'isPengelolaGudang' => $viewer->hasRole(UserRole::PengelolaGudang),
            'isAdmin' => $viewer->isAdmin(),
            'isKasubbag' => $viewer->hasRole(UserRole::KasubbagUmumTi),
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
        $canView = $viewer->hasRole(UserRole::Admin, UserRole::KasubbagUmumTi)
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
            'Content-Disposition' => 'inline; filename="'.$this->documentFilename('nota-dinas', $notaDinas->document_number, $request).'.pdf"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }

    public function bast(ModelsRequest $request)
    {
        $bast = $request->requestDocuments()
            ->where('document_type', 'bast')
            ->with('user')
            ->latest('created_at')
            ->first();

        $viewer = auth()->user();
        $canView = $viewer->hasRole(UserRole::Admin, UserRole::KasubbagUmumTi)
            || $viewer->hasRole(UserRole::PengelolaGudang)
            || ($viewer->hasRole(UserRole::Pengguna) && $bast?->user_id === $viewer->user_id)
            || ($viewer->hasRole(UserRole::KepalaUnit)
                && filled($viewer->unit)
                && $viewer->unit === $bast?->user?->unit);

        abort_unless($canView, 403);
        abort_unless($bast && Storage::disk('local')->exists($bast->file_path), 404);

        return response()->file(Storage::disk('local')->path($bast->file_path), [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'Content-Disposition' => 'inline; filename="'.$this->documentFilename('bast', $bast->document_number, $request).'.docx"',
        ]);
    }

    /**
     * Nomor naskah memuat garis miring, yang tidak sah di nama berkas.
     */
    private function documentFilename(string $prefix, ?string $documentNumber, ModelsRequest $request): string
    {
        return $prefix.'-'.Str::slug(str_replace('/', '-', $documentNumber ?? $request->request_number));
    }

    public function verifyNotaDinas(RequestDocument $requestDocument, string $token)
    {
        abort_unless(
            $requestDocument->document_type === 'nota_dinas'
                && filled($requestDocument->verification_token_hash)
                && hash_equals($requestDocument->verification_token_hash, hash('sha256', $token)),
            404,
        );

        $requestDocument->load(['request.user', 'approvedBy']);

        return view('pages.request.verify-nota-dinas', [
            'title' => 'Verifikasi Nota Dinas',
            'document' => $requestDocument,
        ]);
    }

    /**
     * Permintaan hanya untuk barang terdaftar yang stoknya cukup (dijaga di
     * StorePermintaanBarangRequest). Kekurangan stok dan barang yang belum
     * terdaftar dimohonkan lewat menu Permohonan, tidak lagi dihitung otomatis
     * dari permintaan.
     *
     * Nomor Nota Dinas dan BAST ditetapkan di sini dari nomor urut yang diisi
     * pemohon, sehingga Nota Dinas sudah bernomor sejak pertama dicetak.
     */
    public function store(StorePermintaanBarangRequest $request, RequestWorkflowService $workflow)
    {
        $validatedData = $request->validated();

        $itemRequest = DB::transaction(function () use ($request, $validatedData, $workflow) {
            $isEmergency = (bool) ($validatedData['emergency'] ?? false);

            $itemRequest = ModelsRequest::create([
                'user_id' => $isEmergency
                    ? $validatedData['requester_id']
                    : $request->user()->user_id,
                'date' => now(),
                'type' => $isEmergency ? 'emergency' : 'normal',
                'status' => 'submitted',
                'request_number' => 'REQ-' . time(),
            ]);

            // Nomor yang sudah dipakai menggagalkan seluruh transaksi dengan
            // galat validasi pada kolom nomor urut.
            $workflow->assignNotaDinasNumber($itemRequest, $validatedData['nd_sequence']);

            $now = now();
            RequestDetail::insert(array_map(
                fn (array $item) => [
                    'request_id' => $itemRequest->request_id,
                    'item_id' => $item['item_id'],
                    'quantity_requested' => $item['qty'],
                    'quantity_approved' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                $validatedData['items'],
            ));

            RequestHistory::catat($itemRequest, $request->user(), RequestHistoryAction::Diajukan, null, 'submitted');

            return $itemRequest;
        });

        if ($itemRequest->type !== 'umum') {
            app(RequestDocumentGenerator::class)->generateNotaDinas($itemRequest->fresh(['user', 'item.itemProposal']));
        }

        AuditLogger::record(
            user: $request->user(),
            activity: "Membuat permintaan barang: {$itemRequest->request_number}",
            module: AuditModule::Permintaan,
            event: AuditEvent::Tambah,
            subject: $itemRequest,
        );
        NotificationScanner::catatPermintaanDiajukan($itemRequest->fresh('user'));

        return redirect()->route('request.index')->with('status', 'Permintaan barang berhasil dibuat.');
    }

    public function update(
        UpdatePermintaanBarangRequest $httpRequest,
        ModelsRequest $request,
        RequestWorkflowService $workflow,
    )
    {
        if ($request->hasItemProposals()) {
            return redirect()
                ->route('request.show', $request)
                ->with('error', 'Putuskan seluruh usulan barang terlebih dahulu sebelum mengubah permintaan.');
        }

        if ($request->status !== 'approved_by_head') {
            return redirect()
                ->route('request.index')
                ->with('error', 'Permintaan ini tidak dapat diubah.');
        }

        $validatedData = $httpRequest->validated();

        $error = DB::transaction(function () use ($request, $validatedData, $workflow, $httpRequest): ?string {
            $submittedItems = collect($validatedData['items'])
                ->keyBy('item_id');
            $existingDetails = $request->item()->get()->keyBy('item_id');

            // Hanya kuantitas barang yang diminta yang boleh diubah.
            if ($submittedItems->keys()->diff($existingDetails->keys())->isNotEmpty()) {
                return 'Barang yang tidak ada dalam permintaan tidak dapat ditambahkan.';
            }

            $changes = [];

            foreach ($existingDetails as $itemId => $detail) {
                $approved = (int) ($submittedItems->get($itemId)['qty'] ?? 0);
                $item = Item::query()->lockForUpdate()->findOrFail($itemId);

                if ($approved > (int) $detail->quantity_requested) {
                    return 'Jumlah disetujui tidak boleh melebihi jumlah yang diminta.';
                }

                // Stok tersedia, bukan stok fisik: permintaan lain yang masih
                // berjalan menahan jatahnya (StockReservation).
                if ($approved > StockReservation::availableFor($item, $request->request_id)) {
                    return "Jumlah disetujui untuk {$item->item_name} melebihi stok tersedia setelah dikurangi tahanan permintaan lain.";
                }

                if ($approved !== (int) $detail->quantity_requested) {
                    $changes[] = [
                        'item_name' => $item->item_name,
                        'before' => (int) $detail->quantity_requested,
                        'after' => $approved,
                    ];
                }

                $detail->update(['quantity_approved' => $approved]);
            }

            // Nomor dari pemohon sudah tercetak sejak diajukan; Nota Dinas
            // dicetak ulang hanya bila Pengelola Gudang membetulkannya, atau
            // bila permintaan lama/gabungan belum bernomor.
            $renumbers = $request->ndSequence() !== $validatedData['nd_sequence'];

            if ($renumbers) {
                $workflow->assignNotaDinasNumber($request, $validatedData['nd_sequence']);
            }

            // Permintaan darurat diselesaikan langsung dari tahap ini, jadi
            // statusnya tetap; tombol Selesaikan lalu menyerahkan jumlah yang
            // baru. Permintaan normal maju ke tahap berikutnya.
            $isEmergency = $request->type === 'emergency';
            $toStatus = $isEmergency ? 'approved_by_head' : 'processed_by_staff';
            $request->update(['status' => $toStatus]);

            if ($renumbers) {
                app(RequestDocumentGenerator::class)->renumberNotaDinas($request);
            }

            if ($changes !== [] || ! $isEmergency) {
                RequestHistory::catat(
                    $request,
                    $httpRequest->user(),
                    $changes === [] ? RequestHistoryAction::Disetujui : RequestHistoryAction::KuantitasDiubah,
                    'approved_by_head',
                    $toStatus,
                    $validatedData['adjustment_note'] ?? null,
                    $changes,
                );
            }

            return null;
        });

        if ($error !== null) {
            return redirect()->back()->withInput()->with('error', $error);
        }

        $note = $validatedData['adjustment_note'] ?? null;

        AuditLogger::record(
            user: $httpRequest->user(),
            activity: "Mengubah permintaan barang: {$request->request_number} (status menjadi {$request->statusLabel()})"
                .($note !== null ? ". Alasan: {$note}" : ''),
            module: AuditModule::Permintaan,
            event: AuditEvent::Ubah,
            subject: $request,
        );
        NotificationScanner::periksaPermintaan($request->fresh('user'));

        if ($request->type === 'emergency') {
            return redirect()
                ->route('request.show', $request)
                ->with('status', 'Kuantitas permintaan darurat diperbarui. Tekan Selesaikan untuk menyerahkan barang.');
        }

        return redirect()
            ->route('request.index')
            ->with('status', 'Permintaan barang berhasil diperbarui.');
    }

    public function removeRejectedProposal(RequestDetail $requestDetail, Request $httpRequest)
    {
        $actor = $httpRequest->user();
        abort_unless(
            $actor->isAdmin()
                || $actor->hasRole(UserRole::KasubbagUmumTi, UserRole::PengelolaGudang),
            403,
        );

        $request = $requestDetail->request;
        abort_unless(
            $requestDetail->item_proposal_id !== null
                && $requestDetail->itemProposal?->status === ItemProposalStatus::Ditolak,
            404,
        );

        $requestDetail->delete();

        return redirect()
            ->route('request.show', $request)
            ->with('status', 'Usulan barang yang ditolak berhasil dihapus dari permintaan.');
    }

    public function respondByRole(
        ModelsRequest $request,
        Request $httpRequest,
        RequestWorkflowService $workflow,
    )
    {
        $action = $httpRequest->input('action');
        $user = $httpRequest->user();

        if (!in_array($action, ['approve', 'reject'])) {
            return redirect()->back()->with('error', 'Aksi tidak valid.');
        }

        try {
            $workflow->respond(
                $request,
                $user,
                $action,
                $httpRequest->input('nd_sequence'),
                $httpRequest->input('note'),
            );
        } catch (AuthorizationException $exception) {
            return redirect()->back()->with('error', $exception->getMessage());
        } catch (ValidationException $exception) {
            return redirect()->back()
                ->withInput()
                ->withErrors($exception->errors())
                ->with('error', $exception->validator->errors()->first());
        } catch (InsufficientStockException $exception) {
            return redirect()->back()->with(
                'error',
                "Stok {$exception->item->item_name} tidak cukup untuk diserahkan. "
                    ."Tersedia {$exception->available}, dibutuhkan {$exception->requested}.",
            );
        }

        $message = $action === 'reject' ? 'Permintaan telah ditolak.' : 'Permintaan berhasil diproses.';

        // Kepala Unit menyetujui langsung dari antrean di dasbor.
        if ($httpRequest->input('kembali') === 'dasbor') {
            return redirect()->route('dashboard')->with('status', $message);
        }

        if ($action === 'reject') {
            return redirect()->back()->with('status', $message);
        }

        return redirect()->route('request.index')->with('status', $message);
    }

    /**
     * Konfirmasi penerimaan, laporan masalah, tanggapan Gudang, dan penutupan
     * atas nama pemohon. Siapa boleh melakukan apa diperiksa di
     * RequestWorkflowService::receipt(), karena pemohon bisa berperan apa saja.
     */
    public function receipt(
        ModelsRequest $request,
        Request $httpRequest,
        RequestWorkflowService $workflow,
    )
    {
        $action = (string) $httpRequest->input('action');

        if (! in_array($action, ['confirm', 'report', 'reply', 'close'], true)) {
            return redirect()->back()->with('error', 'Aksi tidak valid.');
        }

        try {
            $workflow->receipt($request, $httpRequest->user(), $action, $httpRequest->input('note'));
        } catch (AuthorizationException $exception) {
            return redirect()->back()->with('error', $exception->getMessage());
        } catch (ValidationException $exception) {
            return redirect()->back()
                ->withInput()
                ->withErrors($exception->errors())
                ->with('error', $exception->validator->errors()->first());
        }

        return redirect()->route('request.show', $request)->with('status', match ($action) {
            'confirm' => 'Terima kasih, penerimaan barang telah dikonfirmasi.',
            'report' => 'Laporan masalah terkirim ke Pengelola Gudang.',
            'reply' => 'Tanggapan terkirim ke pemohon.',
            default => 'Permintaan ditutup atas nama pemohon.',
        });
    }

    public function revertStatus(
        RevertRequestStatusRequest $httpRequest,
        ModelsRequest $request,
        RequestWorkflowService $workflow,
    )
    {
        try {
            $workflow->revert($request, $httpRequest->user(), $httpRequest->validated()['reason']);
        } catch (AuthorizationException $exception) {
            return redirect()->back()->with('error', $exception->getMessage());
        }

        return redirect()->back()->with('status', 'Status permintaan berhasil dikembalikan.');
    }
}
