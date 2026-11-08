@extends('layouts.app')

@section('content')
    <div class="mb-6">
        <h2 class="text-xl font-semibold text-gray-800 dark:text-white/90">Ajukan Usulan Pengadaan</h2>
        <nav class="mt-1.5" aria-label="Breadcrumb">
            <ol class="text-theme-sm flex items-center gap-1.5 text-gray-500 dark:text-gray-400">
                <li><a href="{{ route('procurement-requests.index') }}" class="hover:text-brand-500">Usulan Pengadaan</a></li>
                <li aria-hidden="true">/</li>
                <li class="text-gray-800 dark:text-white/90">Ajukan</li>
            </ol>
        </nav>
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

    <x-form.sekali-kirim>
        <form action="{{ route('procurement-requests.store') }}" method="POST">
            @csrf
            <x-form.token-kiriman />

            <x-pengadaan.editor-barang :katalog="$katalog" :subkategori="$subkategori" :baris="$baris"
                :tampil-sumber="false">
                <x-slot:footer>
                    <button type="submit" :disabled="rows.length === 0 || terkirim"
                        class="bg-brand-500 shadow-theme-xs hover:bg-brand-600 inline-flex w-full items-center justify-center gap-2 rounded-lg px-5 py-3 text-sm font-medium text-white transition disabled:cursor-not-allowed disabled:opacity-50">
                        Ajukan Usulan
                        <x-ui.icon name="arrow-right" class="size-4" />
                    </button>
                    <a href="{{ route('procurement-requests.index') }}"
                        class="block text-center text-sm font-medium text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300">
                        Batal
                    </a>
                </x-slot:footer>
            </x-pengadaan.editor-barang>
        </form>
    </x-form.sekali-kirim>
@endsection
