{{-- Kepala satu langkah kartu BAST: lingkaran bernomor (atau centang bila
     selesai), judul, dan peran yang mengerjakannya. Langkah yang sedang
     ditunggu diberi warna brand supaya urutannya terbaca tanpa penjelasan. --}}
<div class="flex items-start gap-3">
    <span @class([
        'flex size-8 shrink-0 items-center justify-center rounded-full text-sm font-semibold',
        'bg-success-500 text-white' => $selesai,
        'bg-brand-500 text-white' => ! $selesai && $aktif,
        'bg-gray-100 text-gray-500 dark:bg-white/5 dark:text-gray-400' => ! $selesai && ! $aktif,
    ])>
        @if ($selesai)
            <x-ui.icon name="check" class="size-4" />
        @else
            {{ $nomor }}
        @endif
    </span>
    <div class="min-w-0">
        <p @class([
            'text-theme-sm font-medium',
            'text-gray-800 dark:text-white/90' => $selesai || $aktif,
            'text-gray-500 dark:text-gray-400' => ! $selesai && ! $aktif,
        ])>{{ $judul }}</p>
        <p class="text-theme-xs text-gray-500 dark:text-gray-400">{{ $peran }}</p>
    </div>
</div>
