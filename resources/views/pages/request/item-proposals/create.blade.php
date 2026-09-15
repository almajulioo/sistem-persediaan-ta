@extends('layouts.app')

@section('content')
    <div class="mb-6">
        <h2 class="text-xl font-semibold text-gray-800 dark:text-white/90">Usulkan Barang Baru</h2>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Ajukan barang yang belum tersedia pada daftar master barang.</p>
    </div>

    @if ($errors->any())
        <div class="mb-6 rounded-lg bg-error-50 px-4 py-3 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-500">
            Periksa kembali isian formulir.
        </div>
    @endif

    <div class="w-full rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] md:p-6">
        <form method="POST" action="{{ route('request.item-proposals.store') }}">
            @csrf
            <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
                <div class="md:col-span-2">
                    <x-form.text-field name="item_name" label="Nama Barang" :value="old('item_name')" required
                        placeholder="mis. Toner Printer LaserJet" />
                </div>
                <x-form.select-field name="unit" label="Satuan" :options="\App\Enums\SatuanBarang::options()"
                    :selected="old('unit')" required placeholder="Pilih satuan" />
                <div class="md:col-span-2">
                    <label for="reason" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">Alasan/Keterangan</label>
                    <textarea id="reason" name="reason" rows="4" maxlength="1000"
                        class="w-full rounded-lg border border-gray-300 px-4 py-3 text-sm text-gray-800 outline-none focus:border-brand-300 focus:ring-2 focus:ring-brand-500/20 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90"
                        placeholder="Jelaskan kebutuhan barang ini">{{ old('reason') }}</textarea>
                    @error('reason') <p class="mt-1.5 text-xs text-error-500">{{ $message }}</p> @enderror
                </div>
            </div>
            <div class="mt-8 flex justify-end gap-3 border-t border-gray-100 pt-6 dark:border-gray-800">
                <a href="{{ route('request.item-proposals.index') }}" class="rounded-lg px-4 py-3 text-sm font-medium text-gray-700 ring-1 ring-gray-300 ring-inset dark:text-gray-400 dark:ring-gray-700">Batal</a>
                <button type="submit" class="rounded-lg bg-brand-500 px-5 py-3 text-sm font-medium text-white hover:bg-brand-600">Ajukan Usulan</button>
            </div>
        </form>
    </div>
@endsection
