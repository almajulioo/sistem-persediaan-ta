@extends('layouts.app')

@section('content')
    <div class="mb-6">
        <h2 class="text-xl font-semibold text-gray-800 dark:text-white/90">Ubah Barang</h2>
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
                <li class="text-sm text-gray-800 dark:text-white/90">{{ $item->item_name }}</li>
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
        <div class="mb-6 flex flex-wrap items-center justify-between gap-4 border-b border-gray-100 pb-6 dark:border-gray-800">
            <div>
                <p class="font-medium text-gray-800 dark:text-white/90">{{ $item->item_code }}</p>
                <p class="text-theme-xs text-gray-500 dark:text-gray-400">
                    Didaftarkan {{ $item->created_at?->translatedFormat('d F Y') ?? '-' }}
                    &middot; {{ $item->stockMutations()->count() }} mutasi tercatat
                </p>
            </div>

            <span class="text-theme-xs inline-flex items-center rounded-full px-2.5 py-1 font-medium {{ $item->stockStatus()->badgeClass() }}">
                {{ $item->stockStatus()->label() }}
            </span>
        </div>

        @include('pages.items._form', [
            'item' => $item,
            'isEdit' => true,
            'action' => route('items.update', $item),
        ])
    </div>
@endsection
