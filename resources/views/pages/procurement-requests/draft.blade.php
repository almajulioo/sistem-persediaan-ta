@extends('layouts.app')

@section('content')
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h2 class="text-xl font-semibold text-gray-800 dark:text-white/90">Draf Usulan Pengadaan</h2>
            @if ($draft)
                <div class="mt-1.5 flex flex-wrap items-center gap-2">
                    <span class="text-theme-xs inline-block rounded-full px-2.5 py-1 font-medium {{ \App\Enums\ProcurementRequestStatus::Draf->badgeClass() }}">
                        {{ \App\Enums\ProcurementRequestStatus::Draf->label() }}
                    </span>
                    <span class="text-theme-sm text-gray-500 dark:text-gray-400">
                        Dibuat otomatis {{ $draft->created_at->translatedFormat('d M Y, H:i') }}
                    </span>
                </div>
            @endif
        </div>

        <a href="{{ route('procurement-requests.index') }}"
            class="text-theme-sm rounded-lg px-4 py-2.5 font-medium text-gray-700 ring-1 ring-gray-300 ring-inset transition hover:bg-gray-50 dark:text-gray-400 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
            Kembali ke Daftar Usulan
        </a>
    </div>

    <x-ui.flash />

    @if ($errors->any())
        <div class="bg-error-50 text-error-600 dark:bg-error-500/15 dark:text-error-500 mb-6 rounded-lg px-4 py-3 text-sm"
            role="alert" aria-live="assertive">
            <ul class="list-disc space-y-1 pl-5">
                @foreach ($errors->all() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if (! $draft)
        <div class="rounded-2xl border border-gray-200 bg-white p-10 text-center dark:border-gray-800 dark:bg-white/[0.03]">
            <p class="text-theme-sm text-gray-500 dark:text-gray-400">
                Tidak ada draf saat ini.
            </p>
        </div>
    @else
        {{-- Ketiga formulir membawa `draft_id`, supaya aksinya berlaku pada
             draf yang sedang ditampilkan, bukan draf lain yang kebetulan ada
             sekarang (ADR 0045). --}}
        <x-form.sekali-kirim>
            <form action="{{ route('procurement-requests.draft.update') }}" method="POST">
                @csrf
                @method('PUT')
                <input type="hidden" name="draft_id" value="{{ $draft->procurement_request_id }}">

                <x-pengadaan.editor-barang :katalog="$katalog" :subkategori="$subkategori" :baris="$baris"
                    judul="Barang pada Draf" kosong="Belum ada barang. Menyimpan daftar kosong akan membuang draf ini.">
                    <x-slot:footer>
                        <p x-show="dirty" style="display: none;" class="text-theme-xs text-warning-600 dark:text-warning-400">
                            Ada perubahan yang belum disimpan. Simpan perubahan sebelum mengajukan usulan ini.
                        </p>

                        <div class="flex flex-wrap items-center justify-between gap-3">
                            {{-- Kedua tombol ini mengirim formulirnya sendiri di bawah,
                                 karena formulir tidak boleh bersarang. --}}
                            <button type="submit" form="buang-draf" :disabled="terkirim"
                                class="text-theme-sm text-error-600 dark:text-error-500 ring-error-200 dark:ring-error-500/30 hover:bg-error-50 dark:hover:bg-error-500/10 rounded-lg px-4 py-2.5 font-medium ring-1 ring-inset transition disabled:cursor-not-allowed disabled:opacity-50">
                                Buang Draf
                            </button>

                            <div class="flex flex-wrap gap-3">
                                <button type="submit" :disabled="terkirim"
                                    :class="dirty ? 'bg-brand-500 text-white ring-brand-500 hover:bg-brand-600' : 'text-gray-700 ring-gray-300 hover:bg-gray-50 dark:text-gray-400 dark:ring-gray-700 dark:hover:bg-white/[0.03]'"
                                    class="rounded-lg px-4 py-2.5 text-sm font-medium ring-1 ring-inset transition disabled:cursor-not-allowed disabled:opacity-50">
                                    Simpan Perubahan
                                </button>
                                <button type="submit" form="ajukan-draf" :disabled="dirty || terkirim"
                                    class="bg-brand-500 shadow-theme-xs hover:bg-brand-600 inline-flex items-center justify-center gap-2 rounded-lg px-5 py-2.5 text-sm font-medium text-white transition disabled:cursor-not-allowed disabled:opacity-50">
                                    Ajukan Usulan Ini
                                    <x-ui.icon name="arrow-right" class="size-4" />
                                </button>
                            </div>
                        </div>
                    </x-slot:footer>
                </x-pengadaan.editor-barang>
            </form>

            <form id="buang-draf" method="POST" action="{{ route('procurement-requests.draft.discard') }}"
                onsubmit="return confirm('Buang draf ini?')">
                @csrf
                @method('DELETE')
                <input type="hidden" name="draft_id" value="{{ $draft->procurement_request_id }}">
            </form>

            <form id="ajukan-draf" method="POST" action="{{ route('procurement-requests.draft.finalize') }}">
                @csrf
                <input type="hidden" name="draft_id" value="{{ $draft->procurement_request_id }}">
            </form>
        </x-form.sekali-kirim>
    @endif

    {{-- Barang di bawah minimum yang sengaja tidak dimasukkan pemindaian
         karena pengadaannya sudah berjalan (ADR 0047). Tanpa daftar ini Gudang
         akan mengira barangnya terlewat lalu menambahkannya manual. --}}
    @if ($dalamProses->isNotEmpty())
        <div class="mt-6 rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]"
            x-data="{ buka: false }">
            <button type="button" @click="buka = ! buka" :aria-expanded="buka" aria-controls="dalam-proses"
                class="flex w-full items-center justify-between gap-3 px-5 py-4 text-left sm:px-6">
                <h3 class="text-base font-medium text-gray-800 dark:text-white/90">
                    Sedang dalam Proses Pengadaan
                    <span class="text-theme-xs ml-1.5 inline-block rounded-full bg-gray-100 px-2.5 py-0.5 font-medium text-gray-600 dark:bg-gray-500/15 dark:text-gray-400">
                        {{ $dalamProses->count() }}
                    </span>
                </h3>
                <x-ui.icon name="chevron-right" class="size-5 text-gray-500 transition-transform dark:text-gray-400"
                    ::class="buka && 'rotate-90'" />
            </button>

            <div id="dalam-proses" x-show="buka" style="display: none;"
                class="custom-scrollbar max-w-full overflow-x-auto border-t border-gray-100 dark:border-gray-800">
                <table class="w-full min-w-[640px]">
                    <thead>
                        <tr class="border-b border-gray-100 dark:border-gray-800">
                            @foreach (['Barang', 'Stok', 'Dokumen'] as $heading)
                                <th class="px-5 py-3 text-left sm:px-6">
                                    <p class="text-theme-xs font-medium text-gray-500 dark:text-gray-400">{{ $heading }}</p>
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($dalamProses as $baris)
                            <tr class="border-b border-gray-100 align-top last:border-b-0 dark:border-gray-800">
                                <td class="px-5 py-4 sm:px-6">
                                    <p class="text-theme-sm font-medium text-gray-800 dark:text-white/90">{{ $baris['item']->item_name }}</p>
                                    <p class="text-theme-xs text-gray-500 dark:text-gray-400">{{ $baris['item']->item_code }}</p>
                                </td>
                                <td class="px-5 py-4 sm:px-6">
                                    <p class="text-theme-sm whitespace-nowrap text-gray-500 dark:text-gray-400">
                                        {{ number_format($baris['item']->stock_current, 0, ',', '.') }} {{ $baris['item']->unit->label() }}
                                    </p>
                                </td>
                                <td class="px-5 py-4 sm:px-6">
                                    <div class="flex flex-col gap-1.5">
                                        @foreach ($baris['dokumen'] as $dokumen)
                                            <div class="flex flex-wrap items-center gap-2">
                                                <a href="{{ $dokumen['url'] }}"
                                                    class="text-theme-sm text-brand-500 hover:text-brand-600 dark:text-brand-400 font-medium">
                                                    {{ $dokumen['judul'] }}
                                                </a>
                                                <span class="text-theme-xs inline-block rounded-full px-2.5 py-0.5 font-medium {{ $dokumen['status']->badgeClass() }}">
                                                    {{ $dokumen['status']->label() }}
                                                </span>
                                            </div>
                                        @endforeach
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
@endsection
