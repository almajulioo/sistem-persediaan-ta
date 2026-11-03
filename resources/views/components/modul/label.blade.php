{{--
    Label kecil berwarna identitas modul, penanda baris atau bagian milik
    Permintaan atau Permohonan, mis. di Aktivitas Terbaru dasbor.
--}}
@props(['modul'])

<span {{ $attributes->class(['text-theme-xs inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 font-semibold', \App\Support\Warna::lembut($modul->warna())]) }}>
    <x-ui.icon :name="$modul->icon()" class="size-3.5" />
    {{ $modul->labelPendek() }}
</span>
