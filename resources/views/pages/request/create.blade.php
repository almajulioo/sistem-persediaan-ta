@extends('layouts.app')

@section('content')
    <div class="mb-6">
        <h2 class="text-xl font-semibold text-gray-800 dark:text-white/90">Tambah Barang</h2>
        <nav class="mt-1.5">
            <ol class="flex items-center gap-1.5">
                <li class="inline-flex items-center gap-1.5 text-sm text-gray-500 dark:text-gray-400">
                    Formulir Pengajuan Permintaan Barang
                </li>
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
        @include('pages.request._form', [
            'isEdit' => false,
            'items' => $items,
            'users' => $users,
            'canCreateEmergency' => $canCreateEmergency,
            'action' => route('request.store'),
        ])
    </div>
@endsection
