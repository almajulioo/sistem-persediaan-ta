@extends('layouts.app')

@section('content')
    <div class="mb-6">
        <h2 class="text-xl font-semibold text-gray-800 dark:text-white/90">Mulai Stok Opname</h2>
        <nav class="mt-1.5">
            <ol class="flex items-center gap-1.5">
                <li>
                    <a href="{{ route('opname.index') }}"
                        class="inline-flex items-center gap-1.5 text-sm text-gray-500 dark:text-gray-400">
                        Stok Opname
                        <svg class="stroke-current" width="17" height="16" viewBox="0 0 17 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M6.0765 12.667L10.2432 8.50033L6.0765 4.33366" stroke="" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                    </a>
                </li>
                <li class="text-sm text-gray-800 dark:text-white/90">Mulai Opname</li>
            </ol>
        </nav>
    </div>

    <x-ui.flash />

    @if ($errors->any())
        <div class="bg-error-50 text-error-600 dark:bg-error-500/15 dark:text-error-500 mb-6 rounded-lg px-4 py-3 text-sm"
            role="alert" aria-live="assertive">
            {{ $errors->first() }}
        </div>
    @endif

    <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] md:p-6">
        <form method="POST" action="{{ route('opname.store') }}" novalidate>
            @csrf

            <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
                <x-form.select-field name="period_type" label="Jenis Periode"
                    :options="\App\Enums\PeriodeOpname::options()" :selected="old('period_type')"
                    required placeholder="Pilih periode" />

                <x-form.text-field name="period_year" label="Tahun" type="number"
                    :value="old('period_year', now()->year)" required min="2000" max="2100" />

                <x-form.text-field name="opname_date" label="Tanggal Pencacahan" type="date"
                    :value="old('opname_date', now()->format('Y-m-d'))" required
                    max="{{ now()->format('Y-m-d') }}" />
            </div>

            <div class="mt-5">
                <label for="notes" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                    Catatan
                </label>
                <textarea id="notes" name="notes" rows="3" maxlength="500"
                    placeholder="mis. Opname rutin triwulanan bersama Subbagian Umum dan TU"
                    class="dark:bg-dark-900 shadow-theme-xs focus:border-brand-300 focus:ring-brand-500/10 dark:focus:border-brand-800 w-full rounded-lg border bg-transparent px-4 py-2.5 text-sm text-gray-800 placeholder:text-gray-400 focus:ring-3 focus:outline-hidden dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30 {{ $errors->has('notes') ? 'border-error-500' : 'border-gray-300 dark:border-gray-700' }}">{{ old('notes') }}</textarea>
                @error('notes')
                    <p class="text-theme-xs text-error-500 mt-1.5">{{ $message }}</p>
                @enderror
            </div>

            <div class="mt-8 flex items-center justify-end gap-3 border-t border-gray-100 pt-6 dark:border-gray-800">
                <a href="{{ route('opname.index') }}"
                    class="rounded-lg px-4 py-3 text-sm font-medium text-gray-700 ring-1 ring-gray-300 ring-inset transition hover:bg-gray-50 dark:text-gray-400 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
                    Batal
                </a>
                <button type="submit"
                    class="bg-brand-500 shadow-theme-xs hover:bg-brand-600 rounded-lg px-5 py-3 text-sm font-medium text-white transition">
                    Buat Draf Opname
                </button>
            </div>
        </form>
    </div>
@endsection
