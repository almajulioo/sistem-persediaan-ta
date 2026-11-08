<?php

namespace App\Http\Controllers\Pengadaan;

use App\Enums\AuditEvent;
use App\Enums\AuditModule;
use App\Enums\ProcurementRequestSource;
use App\Enums\ProcurementRequestStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pengadaan\ApproveProcurementRequestRequest;
use App\Http\Requests\Pengadaan\StoreProcurementRequestRequest;
use App\Models\ProcurementRequest;
use App\Support\AuditLogger;
use App\Support\KatalogBarang;
use App\Support\KirimanFormulir;
use App\Support\LaporanUsulanPengadaan;
use App\Support\NotificationScanner;
use App\Support\ProcurementDraftScanner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
        $filters = $this->filters($request);

        return view('pages.procurement-requests.index', [
            'title' => 'Usulan Pengadaan',
            'usulan' => ProcurementRequest::query()
                ->bukanDraf()
                ->filter($filters)
                ->with(['user', 'details'])
                ->latest('created_at')
                ->paginate(self::PER_PAGE)
                ->withQueryString(),
            'filters' => $filters,
            'summary' => [
                'total' => ProcurementRequest::query()->bukanDraf()->count(),
                'diajukan' => ProcurementRequest::where('status', ProcurementRequestStatus::Diajukan)->count(),
                'disetujui_menunggu_realisasi' => ProcurementRequest::where('status', ProcurementRequestStatus::DisetujuiMenungguRealisasi)->count(),
                'disetujui_selesai' => ProcurementRequest::where('status', ProcurementRequestStatus::DisetujuiSelesai)->count(),
                'ditolak' => ProcurementRequest::where('status', ProcurementRequestStatus::Ditolak)->count(),
            ],
            // Draf otomatis (ADR 0027) ditampilkan lewat banner tersendiri,
            // bukan sebagai baris di tabel usulan sungguhan.
            'draftBanner' => ProcurementRequest::query()->draf()->with('details')->first(),
        ]);
    }

    public function create(Request $request): View
    {
        // Jalur pintas per barang dari notifikasi Stok Habis/Menipis sudah
        // digantikan draf otomatis (ADR 0027) — formulir ini sekarang murni
        // manual.
        $katalog = KatalogBarang::untukPemilih();
        $perId = $katalog->keyBy('item_id');

        // Daftar dipulihkan dari kiriman sebelumnya bila validasi gagal,
        // supaya keterangan yang sudah diketik tidak hilang.
        $baris = collect($request->old('items', []))
            ->filter(fn ($row) => is_array($row) && $perId->has($row['item_id'] ?? ''))
            ->map(fn (array $row) => [
                ...$perId->get($row['item_id']),
                'amount_request' => max((int) ($row['amount_request'] ?? 1), 1),
                'source' => ProcurementRequestSource::Manual->value,
                'note' => (string) ($row['note'] ?? ''),
                'permohonan' => [],
            ])
            ->values();

        return view('pages.procurement-requests.create', [
            'title' => 'Ajukan Usulan Pengadaan',
            'katalog' => $katalog,
            'subkategori' => KatalogBarang::subkategori(),
            'baris' => $baris,
        ]);
    }

    public function store(StoreProcurementRequestRequest $request): RedirectResponse
    {
        $data = $request->validated();

        [$procurementRequest, $ulang] = KirimanFormulir::sekali($request, fn () => $this->simpanUsulan($request, $data));

        if ($ulang) {
            return $this->sudahDiajukan($procurementRequest);
        }

        AuditLogger::record(
            user: $request->user(),
            activity: "Mengajukan usulan pengadaan: {$procurementRequest->judul()}",
            module: AuditModule::UsulanPengadaan,
            event: AuditEvent::Ajukan,
            subject: $procurementRequest,
        );
        NotificationScanner::periksaUsulan($procurementRequest);
        // Barangnya kini dalam proses pengadaan; baris otomatisnya di draf
        // tidak boleh ikut diajukan dua kali (ADR 0047).
        ProcurementDraftScanner::perbaruiBarangDokumen($procurementRequest->details()->pluck('item_id'));

        return redirect()
            ->route('procurement-requests.show', $procurementRequest)
            ->with('status', "{$procurementRequest->judul()} berhasil diajukan.");
    }

    public function show(ProcurementRequest $procurementRequest): View|RedirectResponse
    {
        // URL lama/tebakan ke draf otomatis dialihkan ke halaman drafnya
        // sendiri (ADR 0027) — draf bukan usulan yang bisa ditampilkan di sini.
        if ($procurementRequest->isDraf()) {
            return redirect()->route('procurement-requests.draft.edit');
        }

        return view('pages.procurement-requests.show', [
            'title' => $procurementRequest->judul(),
            'procurementRequest' => $procurementRequest->load(['user', 'details.item', 'procurement']),
        ]);
    }

    /**
     * Daftar usulan sesuai penyaring di layar, urut menaik supaya laporan
     * terbaca sebagai kronologi (ADR 0048).
     */
    public function exportExcel(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);

        $usulan = ProcurementRequest::query()
            ->bukanDraf()
            ->filter($filters)
            ->with(['user', 'details.item'])
            ->oldest('created_at')
            ->oldest('procurement_request_id')
            ->get();

        return LaporanUsulanPengadaan::unduh(
            LaporanUsulanPengadaan::daftar($usulan, $filters, $request->user()),
            'laporan-usulan-pengadaan-'.now()->format('Ymd-His').'.xlsx',
        );
    }

    public function exportExcelDetail(Request $request, ProcurementRequest $procurementRequest): StreamedResponse
    {
        // Draf bukan dokumen usulan (ADR 0027), jadi tidak ada yang diekspor.
        abort_if($procurementRequest->isDraf(), 404);

        $kode = strtoupper(substr($procurementRequest->procurement_request_id, 0, 8));

        return LaporanUsulanPengadaan::unduh(
            LaporanUsulanPengadaan::rincian($procurementRequest->load(['user', 'details.item']), $request->user()),
            "usulan-pengadaan-{$kode}.xlsx",
        );
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

            $procurementRequest->forceFill(['status' => ProcurementRequestStatus::DisetujuiMenungguRealisasi])->save();
        });

        AuditLogger::record(
            user: $request->user(),
            activity: "Menyetujui usulan pengadaan: {$procurementRequest->judul()}",
            module: AuditModule::UsulanPengadaan,
            event: AuditEvent::Setuju,
            subject: $procurementRequest,
        );
        NotificationScanner::catatUsulanDiputuskan($procurementRequest->fresh(['user', 'details']), $request->user());
        // Barang yang tidak disetujui lepas dari proses pengadaan (ADR 0047).
        ProcurementDraftScanner::perbaruiBarangDokumen($procurementRequest->details()->pluck('item_id'));

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

        AuditLogger::record(
            user: $request->user(),
            activity: "Menolak usulan pengadaan: {$procurementRequest->judul()}",
            module: AuditModule::UsulanPengadaan,
            event: AuditEvent::Tolak,
            subject: $procurementRequest,
        );
        NotificationScanner::catatUsulanDiputuskan($procurementRequest->fresh(['user', 'details']), $request->user());
        // Seluruh barangnya lepas dari proses pengadaan dan kembali ke draf
        // bila stoknya masih kurang (ADR 0047).
        ProcurementDraftScanner::perbaruiBarangDokumen($procurementRequest->details()->pluck('item_id'));

        return redirect()
            ->route('procurement-requests.show', $procurementRequest)
            ->with('status', "{$procurementRequest->judul()} ditolak.");
    }

    /**
     * Penyaring yang dibaca sekali lalu dipakai bersama oleh layar dan ekspor
     * Excel, supaya isi berkas sama persis dengan yang dilihat pengguna.
     *
     * Penyaring sumber dibuang sejak sumber pindah ke tiap barang (ADR 0044):
     * satu usulan bisa memuat ketiga sumber sekaligus.
     *
     * @return array{status: string, from: string, to: string}
     */
    private function filters(Request $request): array
    {
        return [
            'status' => (string) $request->query('status', ''),
            'from' => $this->tanggal($request->query('from')),
            'to' => $this->tanggal($request->query('to')),
        ];
    }

    /**
     * Tanggal Y-m-d yang sah, atau kosong — query string sembarangan tidak
     * boleh sampai ke kalimat periode laporan yang mengurainya.
     */
    private function tanggal(mixed $nilai): string
    {
        $nilai = is_string($nilai) ? trim($nilai) : '';
        $tanggal = \DateTimeImmutable::createFromFormat('!Y-m-d', $nilai);

        return $tanggal !== false && $tanggal->format('Y-m-d') === $nilai ? $nilai : '';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function simpanUsulan(StoreProcurementRequestRequest $request, array $data): ProcurementRequest
    {
        $procurementRequest = ProcurementRequest::create([
            'user_id' => $request->user()->user_id,
            'status' => ProcurementRequestStatus::Diajukan,
        ]);

        // `is_auto` hanya bermakna di draf; diisi false supaya baris ini
        // tidak terbaca milik pemindaian stok.
        $procurementRequest->details()->createMany(
            array_map(fn (array $item) => [
                'item_id' => $item['item_id'],
                'source' => ProcurementRequestSource::Manual,
                'note' => $item['note'] ?? null,
                'amount_request' => $item['amount_request'],
                'is_auto' => false,
            ], $data['items']),
        );

        return $procurementRequest;
    }

    /**
     * Kiriman ulang formulir yang sudah pernah terkirim, misalnya lewat tombol
     * Kembali peramban: tidak membuat usulan baru, tidak dicatat di log.
     */
    private function sudahDiajukan(?ProcurementRequest $procurementRequest): RedirectResponse
    {
        if ($procurementRequest === null) {
            return redirect()->route('procurement-requests.index');
        }

        return redirect()
            ->route('procurement-requests.show', $procurementRequest)
            ->with('status', "{$procurementRequest->judul()} sudah diajukan sebelumnya.");
    }
}
