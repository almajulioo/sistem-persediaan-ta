@extends('layouts.app')

@section('content')
    <div class="mb-6">
        <h2 class="text-xl font-semibold text-gray-800 dark:text-white/90">Ubah Pengguna</h2>
        <nav class="mt-1.5">
            <ol class="flex items-center gap-1.5">
                <li>
                    <a href="{{ route('users.index') }}"
                        class="inline-flex items-center gap-1.5 text-sm text-gray-500 dark:text-gray-400">
                        Manajemen Pengguna
                        <svg class="stroke-current" width="17" height="16" viewBox="0 0 17 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M6.0765 12.667L10.2432 8.50033L6.0765 4.33366" stroke="" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                    </a>
                </li>
                <li class="text-sm text-gray-800 dark:text-white/90">{{ $user->fullname }}</li>
            </ol>
        </nav>
    </div>

    <x-ui.flash />

    @if ($errors->any())
        <div class="bg-error-50 text-error-600 dark:bg-error-500/15 dark:text-error-500 mb-6 rounded-lg px-4 py-3 text-sm"
            role="alert" aria-live="assertive">
            Periksa kembali isian formulir. Terdapat {{ $errors->count() }} kesalahan.
        </div>
    @endif

    <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] md:p-6">
        {{-- Ringkas identitas akun --}}
        <div class="mb-6 flex flex-wrap items-center justify-between gap-4 border-b border-gray-100 pb-6 dark:border-gray-800">
            <div class="flex items-center gap-4">
                <x-ui.user-avatar :user="$user" class="size-12" />
                <div>
                    <p class="font-medium text-gray-800 dark:text-white/90">{{ $user->fullname }}</p>
                    <p class="text-theme-xs text-gray-500 dark:text-gray-400">
                        Dibuat {{ $user->created_at?->translatedFormat('d F Y') ?? '-' }}
                        &middot; Login terakhir
                        {{ $user->last_login_at?->translatedFormat('d F Y, H:i') ?? 'belum pernah' }}
                    </p>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                {{-- Moderasi saja: Admin dapat menghapus foto, tidak mengunggahnya (ADR 0038). --}}
                @if ($user->punyaFoto())
                    <x-profile.hapus-foto
                        :action="route('users.avatar.destroy', $user)"
                        :pesan="'Foto profil '.$user->fullname.' akan dihapus dan diganti avatar inisial. Pemilik akun dapat mengunggah foto baru kapan saja.'" />
                @endif

                <span class="text-theme-xs inline-flex items-center rounded-full px-2.5 py-1 font-medium {{ $user->role->badgeClass() }}">
                    {{ $user->role->label() }}
                </span>
            </div>
        </div>

        @include('pages.users._form', [
            'user' => $user,
            'isEdit' => true,
            'action' => route('users.update', $user),
        ])
    </div>
@endsection
