<?php

namespace App\Http\Controllers;

use App\Enums\NotificationLevel;
use App\Models\Notification;
use App\Support\Notifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Kotak notifikasi milik pengguna yang sedang masuk.
 *
 * Tidak memakai gate peran: setiap pengguna berhak atas notifikasinya sendiri,
 * dan penyaringan terjadi lewat `user_id`, bukan lewat otorisasi rute. Yang
 * dijaga adalah kepemilikan tiap baris.
 */
class NotificationController extends Controller
{
    private const PER_PAGE = 20;

    public function index(Request $request): View
    {
        $filters = $this->filters($request);
        $user = $request->user();

        return view('pages.notifications.index', [
            'title' => 'Notifikasi',
            'notifications' => Notification::query()
                ->untuk($user)
                ->filter($filters)
                ->terpenting()
                ->paginate(self::PER_PAGE)
                ->withQueryString(),
            'filters' => $filters,
            'summary' => $this->summary($request),
        ]);
    }

    /**
     * Tandai satu notifikasi dibaca lalu antarkan ke tujuan tindak lanjutnya.
     */
    public function read(Request $request, Notification $notification): RedirectResponse
    {
        $this->pastikanMilikSendiri($request, $notification);

        Notifier::tandaiDibaca($notification);

        return redirect()->to($this->tujuanAman($notification));
    }

    public function readAll(Request $request): RedirectResponse
    {
        $jumlah = Notifier::tandaiSemuaDibaca($request->user());

        return back()->with('status', $jumlah > 0
            ? "{$jumlah} notifikasi ditandai sudah dibaca."
            : 'Tidak ada notifikasi yang belum dibaca.');
    }

    private function pastikanMilikSendiri(Request $request, Notification $notification): void
    {
        // 404, bukan 403: keberadaan notifikasi milik orang lain pun tidak perlu
        // dikonfirmasi kepada yang bukan pemiliknya.
        abort_unless($notification->user_id === $request->user()->user_id, 404);
    }

    /**
     * `action_url` selalu ditulis sistem sebagai lintasan relatif, tetapi tetap
     * diperiksa sebelum dipakai supaya tidak pernah menjadi celah pengalihan ke
     * situs luar.
     */
    private function tujuanAman(Notification $notification): string
    {
        $tujuan = (string) $notification->action_url;

        $aman = str_starts_with($tujuan, '/') && ! str_starts_with($tujuan, '//');

        return $aman ? $tujuan : route('notifications.index', absolute: false);
    }

    /**
     * @return array<string, string>
     */
    private function filters(Request $request): array
    {
        return [
            'type' => (string) $request->query('type', ''),
            'level' => (string) $request->query('level', ''),
            'status' => (string) $request->query('status', ''),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function summary(Request $request): array
    {
        $base = fn () => Notification::query()->untuk($request->user());

        return [
            'mendesak' => $base()->aktif()->where('level', NotificationLevel::Mendesak)->count(),
            'perhatian' => $base()->aktif()->where('level', NotificationLevel::Perhatian)->count(),
            'belum_dibaca' => $base()->belumDibaca()->count(),
            'aktif' => $base()->aktif()->count(),
        ];
    }
}
