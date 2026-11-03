{{--
    Catatan perbedaan Permintaan dan Permohonan: kapan modul ini dipakai, apa
    hasilnya, dan tautan ke modul satunya bila pengguna salah pintu.

    <x-modul.catatan :modul="ModulPengajuan::Permohonan" class="mb-6" />
--}}
@props(['modul'])

@php $lawan = $modul->lawan(); @endphp

<div {{ $attributes->class(['flex items-start gap-3 rounded-xl border px-4 py-3', $modul->catatanClass()]) }}>
    <span class="flex size-8 shrink-0 items-center justify-center rounded-lg {{ \App\Support\Warna::lembut($modul->warna()) }}">
        <x-ui.icon name="info" class="size-4" />
    </span>
    <div class="text-theme-sm min-w-0 flex-1 text-gray-600 dark:text-gray-300">
        <p>
            <span class="font-semibold text-gray-800 dark:text-white/90">{{ $modul->labelPendek() }}</span>
            dipakai bila {{ lcfirst($modul->dipakaiBila()) }}
            {{ $modul->hasil(auth()->user()) }}
        </p>
        <p class="mt-1 text-gray-500 dark:text-gray-400">
            {{ $lawan->ajakan() }}
            <a href="{{ $lawan->ruteBuat() }}" class="inline-flex items-center gap-1 font-medium {{ $lawan->tautanClass() }}">
                <x-ui.icon :name="$lawan->icon()" class="size-3.5" />
                {{ $lawan->labelBuat() }}
                <x-ui.icon name="arrow-right" class="size-3.5" />
            </a>
        </p>
    </div>
</div>
