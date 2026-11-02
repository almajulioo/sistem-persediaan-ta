@extends('layouts.app')

@section('content')
    <div class="mb-6 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 class="text-xl font-semibold text-gray-800 dark:text-white/90">Buat Permintaan Barang</h2>
            <nav class="mt-1.5" aria-label="Breadcrumb">
                <ol class="text-theme-sm flex items-center gap-1.5 text-gray-500 dark:text-gray-400">
                    <li><a href="{{ route('dashboard') }}" class="hover:text-brand-500">Dasbor</a></li>
                    <li aria-hidden="true">/</li>
                    <li><a href="{{ route('request.index') }}" class="hover:text-brand-500">Permintaan</a></li>
                    <li aria-hidden="true">/</li>
                    <li class="text-gray-800 dark:text-white/90">Buat</li>
                </ol>
            </nav>
        </div>
        <p class="text-theme-sm max-w-md text-gray-500 dark:text-gray-400">
            Minta barang yang sudah tersedia di gudang. Permintaan disetujui Kepala Unit, diproses gudang, lalu Anda konfirmasi saat barang diterima.
        </p>
    </div>

    <x-ui.flash />

    @if ($errors->any())
        <div class="bg-error-50 text-error-600 dark:bg-error-500/15 dark:text-error-500 mb-6 rounded-lg px-4 py-3 text-sm"
            role="alert" aria-live="assertive">
            Periksa kembali isian formulir. Terdapat {{ $errors->count() }} kesalahan.
            <ul class="mt-2 list-disc space-y-1 pl-5">
                @foreach ($errors->all() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @include('pages.request._form', [
        'isEdit' => false,
        'items' => $items,
        'users' => $users,
        'canCreateEmergency' => $canCreateEmergency,
        'ndTemplate' => $ndTemplate,
        'action' => route('request.store'),
    ])
@endsection
