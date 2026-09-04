<?php

namespace App\View\Components\header;

use App\Enums\NotificationLevel;
use App\Models\Notification;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\View\Component;

/**
 * Lonceng notifikasi pada header, tampil di seluruh halaman aplikasi.
 *
 * Datanya diambil di sini, bukan disuntikkan tiap controller, supaya tidak ada
 * halaman yang bisa lupa menyediakannya. Yang dibaca hanya notifikasi yang
 * masih aktif dan dibatasi jumlahnya, sehingga biayanya tetap dua kueri ringan
 * per permintaan.
 */
class NotificationDropdown extends Component
{
    private const MAKS_PER_BAGIAN = 5;

    /** @var Collection<int, Notification> */
    public Collection $perluTindakan;

    /** @var Collection<int, Notification> */
    public Collection $aktivitas;

    public int $belumDibaca;

    public bool $adaYangMendesak;

    public function __construct()
    {
        $user = auth()->user();

        if ($user === null) {
            $this->perluTindakan = collect();
            $this->aktivitas = collect();
            $this->belumDibaca = 0;
            $this->adaYangMendesak = false;

            return;
        }

        $aktif = Notification::query()
            ->untuk($user)
            ->aktif()
            ->terpenting()
            ->limit(self::MAKS_PER_BAGIAN * 2)
            ->get();

        // Keadaan yang sedang berlangsung dipisahkan dari peristiwa yang sudah
        // lewat: yang pertama menuntut tindakan, yang kedua sekadar kabar.
        $this->perluTindakan = $aktif
            ->filter(fn (Notification $n) => $n->bersifatKeadaan())
            ->take(self::MAKS_PER_BAGIAN)
            ->values();

        $this->aktivitas = $aktif
            ->reject(fn (Notification $n) => $n->bersifatKeadaan())
            ->take(self::MAKS_PER_BAGIAN)
            ->values();

        $this->belumDibaca = Notification::query()->untuk($user)->belumDibaca()->count();

        $this->adaYangMendesak = $aktif->contains(
            fn (Notification $n) => $n->level === NotificationLevel::Mendesak,
        );
    }

    public function kosong(): bool
    {
        return $this->perluTindakan->isEmpty() && $this->aktivitas->isEmpty();
    }

    public function render(): View|Closure|string
    {
        return view('components.header.notification-dropdown');
    }
}
