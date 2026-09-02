@extends('layouts.app')

@section('content')
    <div class="mb-6">
        <h2 class="text-xl font-semibold text-gray-800 dark:text-white/90">Tambah Barang</h2>
        <nav class="mt-1.5">
            <ol class="flex items-center gap-1.5">
                <li>
                    <a href="{{ route('items.index') }}"
                        class="inline-flex items-center gap-1.5 text-sm text-gray-500 dark:text-gray-400">
                        Master Barang
                        <svg class="stroke-current" width="17" height="16" viewBox="0 0 17 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M6.0765 12.667L10.2432 8.50033L6.0765 4.33366" stroke="" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                    </a>
                </li>
                <li class="text-sm text-gray-800 dark:text-white/90">Tambah Barang</li>
            </ol>
        </nav>
    </div>

    @if ($errors->any())
        <div class="bg-error-50 text-error-600 dark:bg-error-500/15 dark:text-error-500 mb-6 rounded-lg px-4 py-3 text-sm"
            role="alert" aria-live="assertive">
            Periksa kembali isian formulir. Terdapat {{ $errors->count() }} kesalahan.
        </div>
    @endif

    <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] md:p-6">
        @include('pages.items._form', [
            'item' => $item,
            'isEdit' => false,
            'action' => route('items.store'),
        ])
    </div>
@endsection
