<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AuditEvent;
use App\Enums\AuditModule;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Jejak audit seluruh aktivitas pengguna, khusus Admin.
 *
 * Murni baca: tidak ada rute simpan, ubah, maupun hapus. Baris `audit_logs`
 * bersifat append-only, sejalan dengan buku mutasi pada ADR 0005.
 * Lihat ADR 0034.
 */
class ActivityLogController extends Controller
{
    private const PER_PAGE = 20;

    /** Batas aman ekspor supaya satu permintaan tidak menghabiskan memori. */
    private const EXPORT_LIMIT = 5000;

    public function index(Request $request): View
    {
        $filters = $this->filters($request);

        return view('pages.activity-logs.index', [
            'title' => 'Log Aktivitas',
            'logs' => $this->filteredQuery($filters)
                ->with(['user', 'subject'])
                ->paginate(self::PER_PAGE)
                ->withQueryString(),
            'filters' => $filters,
            'summary' => $this->summary($filters),
            'users' => User::orderBy('fullname')->pluck('fullname', 'user_id')->all(),
            'modules' => AuditModule::options(),
            'events' => AuditEvent::options(),
            'roles' => UserRole::options(),
        ]);
    }

    public function exportCsv(Request $request): StreamedResponse|RedirectResponse
    {
        $filters = $this->filters($request);
        $query = $this->filteredQuery($filters)->with(['user', 'subject']);

        if ($tooMany = $this->guardExportSize($query)) {
            return $tooMany;
        }

        $filename = 'log-aktivitas-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($query) {
            $handle = fopen('php://output', 'wb');

            // BOM UTF-8 supaya Excel membaca karakter Indonesia dengan benar.
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, [
                'Waktu', 'Nama Lengkap', 'Nama Pengguna', 'Peran', 'Modul',
                'Aksi', 'Aktivitas', 'Alamat IP', 'Jenis Objek', 'ID Objek',
            ], ';');

            $query->reorder()->orderBy('timestamp')->orderBy('log_id')
                ->chunk(500, function ($rows) use ($handle) {
                    foreach ($rows as $row) {
                        fputcsv($handle, array_map($this->aman(...), [
                            $row->timestamp->format('d/m/Y H:i:s'),
                            $row->pelaku(),
                            $row->user?->username ?? '',
                            $row->actor_role?->label() ?? '',
                            $row->module?->label() ?? '',
                            $row->event?->label() ?? '',
                            $row->activity,
                            $row->ip_address ?? '',
                            // Nama jenis apa adanya dari morph map, supaya
                            // kolomnya dapat dicocokkan dengan nama tabel.
                            $row->subject_type ?? '',
                            // UUID sengaja ikut meski tidak pernah tampil di
                            // layar: berkas ini bahan telusur pemeriksa, dan
                            // tanpa id objeknya satu baris log tidak dapat
                            // dicocokkan balik ke dokumen sumbernya.
                            $row->subject_id ?? '',
                        ]), ';');
                    }
                });

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Penyaring yang dibaca sekali lalu dipakai bersama oleh layar dan CSV
     * supaya isi berkas ekspor selalu identik dengan yang dilihat pengguna.
     *
     * @return array<string, string>
     */
    private function filters(Request $request): array
    {
        return [
            'q' => trim((string) $request->query('q', '')),
            'user' => (string) $request->query('user', ''),
            'role' => (string) $request->query('role', ''),
            'module' => (string) $request->query('module', ''),
            'event' => (string) $request->query('event', ''),
            'from' => (string) $request->query('from', ''),
            'to' => (string) $request->query('to', ''),
        ];
    }

    /**
     * @param  array<string, string>  $filters
     */
    private function filteredQuery(array $filters): Builder
    {
        // Pemecah seri `log_id` bukan hiasan: HasUuids menghasilkan UUID
        // berurut waktu, sehingga baris yang lahir pada detik yang sama tetap
        // terurut benar — hal yang tidak dijamin pengurutan waktu saja.
        return AuditLog::query()
            ->filter($filters)
            ->orderByDesc('timestamp')
            ->orderByDesc('log_id');
    }

    /**
     * @param  array<string, string>  $filters
     * @return array<string, int>
     */
    private function summary(array $filters): array
    {
        $base = AuditLog::query()->filter($filters);

        return [
            'total' => (clone $base)->count(),
            'pengguna' => (clone $base)->distinct()->count('user_id'),
            'hari_ini' => (clone $base)->whereDate('timestamp', today())->count(),
            // Memisahkan aksi yang sungguh mengubah data dari lalu lintas
            // masuk, keluar, dan unduh yang jumlahnya jauh lebih besar.
            'perubahan' => (clone $base)->whereIn('event', AuditEvent::mengubahData())->count(),
        ];
    }

    /** Tolak ekspor yang terlalu besar sebelum data dimuat ke memori. */
    private function guardExportSize(Builder $query): ?RedirectResponse
    {
        if ((clone $query)->count() <= self::EXPORT_LIMIT) {
            return null;
        }

        return back()->with('error', sprintf(
            'Data yang dipilih melebihi %s baris. Persempit rentang tanggal terlebih dahulu.',
            number_format(self::EXPORT_LIMIT, 0, ',', '.'),
        ));
    }

    /**
     * Netralkan sel yang akan dibaca Excel sebagai rumus.
     *
     * Sebagian kalimat log memuat nama berkas unggahan — satu satunya teks di
     * sini yang berasal dari luar dan memang tidak dipercaya, alasan yang sama
     * yang membuat DocumentStorage menyimpannya dengan nama UUID (ADR 0022).
     * Nama seperti `=HYPERLINK(...)` akan dijalankan Excel begitu Admin membuka
     * berkasnya, jadi awalannya dilucuti dengan kutip tunggal yang tidak ikut
     * tampil di sel.
     */
    private function aman(string $nilai): string
    {
        return preg_match('/^[=+\-@\t\r]/', $nilai) === 1 ? "'".$nilai : $nilai;
    }
}
