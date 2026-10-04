@extends('layouts.app')

@section('content')
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <h2 class="text-xl font-semibold text-gray-800 dark:text-white/90">Notifikasi</h2>

        @if ($summary['belum_dibaca'] > 0)
            <form method="POST" action="{{ route('notifications.read-all') }}">
                @csrf
                <button type="submit"
                    class="inline-flex items-center gap-2 rounded-lg px-4 py-3 text-sm font-medium text-gray-700 ring-1 ring-gray-300 ring-inset transition hover:bg-gray-50 dark:text-gray-400 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
                    Tandai Semua Dibaca
                </button>
            </form>
        @endif
    </div>

    <x-ui.flash />

    <div class="mb-6 grid grid-cols-2 gap-4 xl:grid-cols-4 md:gap-6">
        @foreach ([
            ['label' => 'Mendesak', 'value' => $summary['mendesak'], 'class' => 'text-error-600 dark:text-error-500'],
            ['label' => 'Perlu Perhatian', 'value' => $summary['perhatian'], 'class' => 'text-warning-600 dark:text-orange-400'],
            ['label' => 'Belum Dibaca', 'value' => $summary['belum_dibaca'], 'class' => 'text-gray-800 dark:text-white/90'],
            ['label' => 'Total', 'value' => $summary['total'], 'class' => 'text-gray-800 dark:text-white/90'],
        ] as $card)
            <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                <span class="text-theme-sm text-gray-500 dark:text-gray-400">{{ $card['label'] }}</span>
                <h4 class="text-title-sm mt-2 font-bold {{ $card['class'] }}">
                    {{ number_format($card['value'], 0, ',', '.') }}
                </h4>
            </div>
        @endforeach
    </div>

    <div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        {{-- Penyaring --}}
        <form method="GET" action="{{ route('notifications.index') }}"
            class="grid grid-cols-1 gap-4 border-b border-gray-100 p-5 sm:grid-cols-2 xl:grid-cols-4 dark:border-gray-800 sm:p-6">
            <x-form.select-field name="type" label="Jenis" :options="\App\Enums\NotificationType::options()" :selected="$filters['type']"
                placeholder="Semua jenis" />

            <x-form.select-field name="level" label="Tingkat" :options="\App\Enums\NotificationLevel::options()" :selected="$filters['level']"
                placeholder="Semua tingkat" />

            <x-form.select-field name="status" label="Status" :options="[
                'belum_dibaca' => 'Belum dibaca',
                'sudah_dibaca' => 'Sudah dibaca',
            ]" :selected="$filters['status']" placeholder="Semua status" />

            {{-- Sejajar dua bagian lonceng — keadaan dan peristiwa — supaya
                 tautan "Lihat N lainnya" di sana mendarat pada daftar yang sama. --}}
            <x-form.select-field name="sifat" label="Sifat" :options="[
                'keadaan' => 'Perlu tindakan',
                'peristiwa' => 'Aktivitas',
            ]" :selected="$filters['sifat']" placeholder="Semua sifat" />

            <div class="flex items-end gap-3">
                <button type="submit"
                    class="bg-brand-500 shadow-theme-xs hover:bg-brand-600 inline-flex items-center rounded-lg px-4 py-2.5 text-sm font-medium text-white transition">
                    Terapkan
                </button>
                @if (collect($filters)->filter()->isNotEmpty())
                    <a href="{{ route('notifications.index') }}"
                        class="text-theme-sm inline-flex items-center rounded-lg px-4 py-2.5 font-medium text-gray-700 ring-1 ring-gray-300 ring-inset transition hover:bg-gray-50 dark:text-gray-400 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
                        Reset
                    </a>
                @endif
            </div>
        </form>

        {{-- Daftar. Notifikasi memuat kalimat utuh, jadi disajikan sebagai daftar
             alih-alih tabel supaya isinya tetap terbaca. --}}
        <ul>
            @forelse ($notifications as $notification)
                <li
                    class="flex flex-wrap items-start gap-4 border-b border-gray-100 p-5 sm:flex-nowrap sm:px-6 dark:border-gray-800">
                    <span class="mt-2 size-2.5 shrink-0 rounded-full {{ $notification->level->dotClass() }}"
                        title="{{ $notification->level->label() }}"></span>

                    <div class="min-w-0 grow">
                        <div class="flex flex-wrap items-center gap-2">
                            <span @class([
                                'text-theme-sm text-gray-800 dark:text-white/90',
                                'font-semibold' => !$notification->sudahDibaca(),
                            ])>
                                {{ $notification->title }}
                            </span>

                            <span
                                class="text-theme-xs inline-block rounded-full px-2.5 py-1 font-medium {{ $notification->level->badgeClass() }}">
                                {{ $notification->type->label() }}
                            </span>
                        </div>

                        <p class="text-theme-sm mt-1 text-gray-500 dark:text-gray-400">{{ $notification->body }}</p>

                        <p class="text-theme-xs mt-1.5 text-gray-500 dark:text-gray-400">
                            {{ $notification->waktu() }}
                        </p>
                    </div>

                    <form method="POST" action="{{ route('notifications.read', $notification) }}" class="shrink-0">
                        @csrf
                        @method('PATCH')
                        <button type="submit"
                            class="text-theme-sm inline-flex items-center rounded-lg px-4 py-2.5 font-medium text-gray-700 ring-1 ring-gray-300 ring-inset transition hover:bg-gray-50 dark:text-gray-400 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
                            {{ $notification->action_url ? 'Tindak Lanjuti' : 'Tandai Dibaca' }}
                        </button>
                    </form>
                </li>
            @empty
                <li class="px-5 py-10 text-center sm:px-6">
                    <p class="text-theme-sm text-gray-500 dark:text-gray-400">
                        Tidak ada notifikasi yang cocok.
                    </p>
                </li>
            @endforelse
        </ul>

        {{ $notifications->links('vendor.pagination.tailadmin') }}
    </div>
@endsection
