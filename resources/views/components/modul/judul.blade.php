{{--
    Judul halaman modul Permintaan atau Permohonan, dengan ubin ikon berwarna
    identitas modulnya. Isi slot (tombol aksi) tampil di kanan, slot `bawah`
    (mis. breadcrumb) menggantikan deskripsi.

    <x-modul.judul :modul="ModulPengajuan::Permintaan" deskripsi="...">
        <a href="...">Buat Permintaan</a>
    </x-modul.judul>
--}}
@props(['modul', 'judul' => null, 'deskripsi' => null])

<div {{ $attributes->class('mb-6 flex flex-wrap items-center justify-between gap-4') }}>
    <div class="flex min-w-0 items-center gap-4">
        <span class="shadow-theme-xs flex size-12 shrink-0 items-center justify-center rounded-2xl text-white {{ \App\Support\Warna::isi($modul->warna()) }}">
            <x-ui.icon :name="$modul->icon()" class="size-6" />
        </span>
        <div class="min-w-0">
            <h2 class="text-xl font-semibold text-gray-800 dark:text-white/90">{{ $judul ?? $modul->label() }}</h2>
            @isset($bawah)
                {{ $bawah }}
            @else
                <p class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">{{ $deskripsi ?? $modul->ringkas() }}</p>
            @endisset
        </div>
    </div>

    @if ($slot->isNotEmpty())
        <div class="flex flex-wrap items-center gap-2">{{ $slot }}</div>
    @endif
</div>
