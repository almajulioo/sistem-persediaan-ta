@extends('layouts.app')

@section('content')
    <div class="mb-6">
        <h2 class="text-xl font-semibold text-gray-800 dark:text-white/90">Usulkan Barang Baru</h2>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
            Untuk barang yang perlu diadakan tetapi belum terdaftar di master barang. Usulan ini dikirim ke
            Pengelola Gudang, yang memeriksa apakah barangnya benar-benar baru lalu mendaftarkannya dengan
            kode barang dan batas stok. Setelah terdaftar, barangnya dapat dimasukkan ke realisasi pengadaan.
        </p>
    </div>

    <x-ui.flash />

    <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] md:p-6">
        <form action="{{ route('item-proposals.store') }}" method="POST">
            @csrf

            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                <x-form.text-field name="item_name" label="Nama Barang" required
                    placeholder="mis. Spidol Whiteboard Warna Emas"
                    help="Tulis sejelas mungkin. Kode barangnya nanti ditetapkan Pengelola Gudang." />

                <x-form.select-field name="unit" label="Satuan" :options="$satuan" required
                    placeholder="Pilih satuan"
                    help="Satuan pembelian barangnya, mis. buah atau box." />
            </div>

            <div class="mt-5">
                <label for="purpose_note" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                    Kegunaan Barang<span class="text-error-500">*</span>
                </label>
                <textarea id="purpose_note" name="purpose_note" rows="3" required
                    placeholder="mis. untuk menandai berkas pemeriksaan pada rapat pleno; belum ada spidol warna selain hitam dan merah"
                    class="dark:bg-dark-900 shadow-theme-xs focus:border-brand-300 focus:ring-brand-500/10 dark:focus:border-brand-800 w-full rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 text-sm text-gray-800 placeholder:text-gray-400 focus:ring-3 focus:outline-hidden dark:border-gray-700 dark:text-white/90 dark:placeholder:text-white/30">{{ old('purpose_note') }}</textarea>
                @error('purpose_note')
                    <p class="text-theme-xs text-error-500 mt-1.5">{{ $message }}</p>
                @enderror
                <p class="text-theme-xs mt-1.5 text-gray-500 dark:text-gray-400">
                    Wajib diisi. Inilah bahan yang dipakai Pengelola Gudang untuk menilai apakah barangnya
                    memang belum ada, atau hanya nama lain dari barang yang sudah terdaftar.
                </p>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <a href="{{ route('item-proposals.index') }}"
                    class="rounded-lg px-4 py-2.5 text-sm font-medium text-gray-700 ring-1 ring-gray-300 ring-inset transition hover:bg-gray-50 dark:text-gray-400 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
                    Batal
                </a>
                <button type="submit"
                    class="bg-brand-500 shadow-theme-xs hover:bg-brand-600 inline-flex items-center justify-center rounded-lg px-6 py-2.5 text-sm font-medium text-white transition">
                    Ajukan Usulan
                </button>
            </div>
        </form>
    </div>
@endsection
