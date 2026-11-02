@extends('layouts.app')

@section('content')
    <div class="mb-6">
        <h2 class="text-xl font-semibold text-gray-800 dark:text-white/90">Ubah Kuantitas Permintaan</h2>
        <nav class="mt-1.5" aria-label="Breadcrumb">
            <ol class="text-theme-sm flex items-center gap-1.5 text-gray-500 dark:text-gray-400">
                <li><a href="{{ route('request.index') }}" class="hover:text-brand-500">Permintaan</a></li>
                <li aria-hidden="true">/</li>
                <li><a href="{{ route('request.show', $request) }}" class="hover:text-brand-500">{{ $request->request_number }}</a></li>
                <li aria-hidden="true">/</li>
                <li class="text-gray-800 dark:text-white/90">Ubah Kuantitas</li>
            </ol>
        </nav>
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
        'isEdit' => true,
        'items' => $items,
        'action' => route('request.update', $request),
    ])
@endsection
