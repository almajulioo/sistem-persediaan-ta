{{--
    Badge bulat berwarna kategori, dengan ikon di depan labelnya (titik bila
    tanpa ikon). Dipakai status dan jenis permintaan/permohonan, misalnya
    `<x-ui.pill :warna="$row->status->warna()" :ikon="$row->status->icon()">Diajukan</x-ui.pill>`.

    $warna : nama warna App\Support\Warna
    $ikon  : nama ikon x-ui.icon, opsional
--}}
@props(['warna', 'ikon' => null])

<span {{ $attributes->class(['text-theme-xs inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 font-medium',\App\Support\Warna::lembut($warna)]) }}>
    @if ($ikon)
        <x-ui.icon :name="$ikon" class="size-3.5" />
    @else
        <span class="size-1.5 rounded-full bg-current"></span>
    @endif
    {{ $slot }}
</span>
