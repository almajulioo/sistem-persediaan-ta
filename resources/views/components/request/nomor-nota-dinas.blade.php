{{--
    Kolom nomor naskah permintaan yang diketik lengkap: nomor Nota Dinas oleh
    pemohon (ADR 0059) dan nomor BAST oleh petugas yang menyerahkan barang
    (dengan name="bast_number"). Di bawahnya ada saran nomor sesuai unit yang baru terisi bila diklik:
    nomor urut yang sudah diketik dipertahankan, lalu bagian sesudahnya
    diganti format unit. Bila belum ada nomor urut, kursor diletakkan di
    depan supaya pemohon langsung mengetiknya.

    `model`   : properti Alpine induk yang menyimpan isian, mis. "ndNumber".
    `akhiran` : ekspresi Alpine untuk bagian nomor setelah nomor urut, mis.
                "/B/ND/DJPKN-V.BLP.1.4/KEU.02.02/10/2026" (Request::nomorAkhiran()
                atau Request::nomorAkhiranBast()).
--}}
@props([
    'id' => 'nd_number',
    'name' => 'nd_number',
    'model',
    'akhiran',
    'label' => 'Nomor Nota Dinas',
    'placeholder' => 'mis. 51/B/ND/…',
    'help' => 'Ketik sesuai nomor di agenda unit.',
])

@php
    $hasError = $errors->has($name);
@endphp

<div x-data="{
        saranNd(nomor, akhiran) {
            if (! akhiran) return null;
            const isi = String(nomor ?? '').trim();
            const urut = (isi.match(/^\d{1,6}/) ?? [''])[0];
            const saran = urut + akhiran;
            return isi === saran ? null : { nomor: saran, label: (urut || '…') + akhiran, kursor: urut.length };
        },
        fokusSaranNd(saran) {
            this.$nextTick(() => {
                const input = document.getElementById(@js($id));
                input.focus();
                input.setSelectionRange(saran.kursor, saran.kursor);
            });
        },
    }">
    <label for="{{ $id }}" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
        {{ $label }}<span class="text-error-500">*</span>
    </label>
    <input id="{{ $id }}" type="text" name="{{ $name }}" x-model="{{ $model }}" required autocomplete="off" spellcheck="false"
        maxlength="{{ \App\Models\Request::ND_NUMBER_MAX }}" placeholder="{{ $placeholder }}"
        @if ($hasError) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif
        @class([
            'dark:bg-dark-900 shadow-theme-xs focus:border-brand-300 focus:ring-brand-500/10 dark:focus:border-brand-800 h-11 w-full rounded-lg border bg-transparent px-4 py-2.5 font-mono text-sm text-gray-800 placeholder:font-sans placeholder:text-gray-400 focus:ring-3 focus:outline-hidden dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30',
            'border-error-500' => $hasError,
            'border-gray-300 dark:border-gray-700' => ! $hasError,
        ])>

    <template x-if="saranNd({{ $model }}, {{ $akhiran }})">
        <div class="mt-2 flex flex-wrap items-center gap-x-2 gap-y-1">
            <span class="text-theme-xs text-gray-500 dark:text-gray-400">Saran sesuai unit:</span>
            <button type="button" title="Klik untuk memakai format ini"
                @click="const saran = saranNd({{ $model }}, {{ $akhiran }}); {{ $model }} = saran.nomor; fokusSaranNd(saran)"
                class="text-theme-xs max-w-full rounded-md border border-dashed border-gray-300 px-2 py-1 text-left font-mono break-all text-gray-700 transition hover:border-teal-400 hover:bg-teal-50 hover:text-teal-700 dark:border-gray-700 dark:text-gray-300 dark:hover:border-teal-500/50 dark:hover:bg-teal-500/10 dark:hover:text-teal-300"
                x-text="saranNd({{ $model }}, {{ $akhiran }}).label"></button>
        </div>
    </template>

    @if ($hasError)
        <p id="{{ $id }}-error" class="text-theme-xs text-error-500 mt-1.5">{{ $errors->first($name) }}</p>
    @elseif ($help)
        <p class="text-theme-xs mt-1.5 text-gray-500 dark:text-gray-400">{{ $help }}</p>
    @endif
</div>
