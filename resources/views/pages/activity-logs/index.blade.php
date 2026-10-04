@extends('layouts.app')

@section('content')
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <h2 class="text-xl font-semibold text-gray-800 dark:text-white/90">Log Aktivitas</h2>

        <a href="{{ route('activity-logs.csv', request()->query()) }}"
            class="inline-flex items-center gap-2 rounded-lg px-4 py-3 text-sm font-medium text-gray-700 ring-1 ring-gray-300 ring-inset transition hover:bg-gray-50 dark:text-gray-400 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
            <svg class="size-4 fill-current" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
                <path d="M10 2.5a.75.75 0 01.75.75v7.19l2.22-2.22a.75.75 0 111.06 1.06l-3.5 3.5a.75.75 0 01-1.06 0l-3.5-3.5a.75.75 0 111.06-1.06l2.22 2.22V3.25A.75.75 0 0110 2.5zM3.5 13.75a.75.75 0 01.75.75v1.25h11.5V14.5a.75.75 0 011.5 0v1.5a1.25 1.25 0 01-1.25 1.25H4.0A1.25 1.25 0 012.75 16v-1.5a.75.75 0 01.75-.75z" />
            </svg>
            Ekspor CSV
        </a>
    </div>

    <x-ui.flash />

    {{-- Rekap mengikuti penyaring yang sedang aktif, bukan seluruh tabel --}}
    <div class="mb-6 grid grid-cols-2 gap-4 xl:grid-cols-4 md:gap-6">
        {{-- "Perubahan Data" tidak menghitung masuk, keluar, dan unduh —
             ketiganya tidak mengubah data apa pun. --}}
        @foreach ([
            ['label' => 'Total Aktivitas', 'value' => $summary['total']],
            ['label' => 'Pengguna Terlibat', 'value' => $summary['pengguna']],
            ['label' => 'Aktivitas Hari Ini', 'value' => $summary['hari_ini']],
            ['label' => 'Perubahan Data', 'value' => $summary['perubahan']],
        ] as $card)
            <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                <span class="text-theme-sm text-gray-500 dark:text-gray-400">{{ $card['label'] }}</span>
                <h4 class="text-title-sm mt-2 font-bold text-gray-800 dark:text-white/90">
                    {{ number_format($card['value'], 0, ',', '.') }}
                </h4>
            </div>
        @endforeach
    </div>

    <div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        {{-- Penyaring --}}
        <form method="GET" action="{{ route('activity-logs.index') }}"
            class="grid grid-cols-1 gap-4 border-b border-gray-100 p-5 sm:grid-cols-2 xl:grid-cols-4 dark:border-gray-800 sm:p-6">
            <x-form.text-field name="q" label="Cari" :value="$filters['q']"
                placeholder="Kata pada aktivitas atau nama pengguna" />

            <x-form.select-field name="user" label="Pengguna" :options="$users"
                :selected="$filters['user']" placeholder="Semua pengguna" />

            <x-form.select-field name="role" label="Peran" :options="$roles"
                :selected="$filters['role']" placeholder="Semua peran" />

            <x-form.select-field name="module" label="Modul" :options="$modules"
                :selected="$filters['module']" placeholder="Semua modul" />

            <x-form.select-field name="event" label="Aksi" :options="$events"
                :selected="$filters['event']" placeholder="Semua aksi" />

            <x-form.text-field name="from" label="Tanggal Awal" type="date" :value="$filters['from']" />

            <x-form.text-field name="to" label="Tanggal Akhir" type="date" :value="$filters['to']" />

            <div class="flex items-center gap-3 sm:col-span-2 xl:col-span-4">
                <button type="submit"
                    class="bg-brand-500 shadow-theme-xs hover:bg-brand-600 inline-flex items-center rounded-lg px-4 py-2.5 text-sm font-medium text-white transition">
                    Terapkan
                </button>
                @if (collect($filters)->filter()->isNotEmpty())
                    <a href="{{ route('activity-logs.index') }}"
                        class="text-theme-sm inline-flex items-center rounded-lg px-4 py-2.5 font-medium text-gray-700 ring-1 ring-gray-300 ring-inset transition hover:bg-gray-50 dark:text-gray-400 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
                        Reset
                    </a>
                @endif
            </div>
        </form>

        {{-- Tabel --}}
        <div class="custom-scrollbar max-w-full overflow-x-auto">
            <table class="w-full min-w-[1220px]">
                <thead>
                    <tr class="border-b border-gray-100 dark:border-gray-800">
                        {{-- Peran = peran pelaku saat aksi dilakukan, bukan perannya
                             sekarang; Objek hanya tertaut bila dokumennya masih ada. --}}
                        @foreach (['Waktu', 'Pengguna', 'Peran', 'Modul', 'Aksi', 'Aktivitas', 'Objek'] as $heading)
                            <th class="px-5 py-3 text-left sm:px-6">
                                <p class="text-theme-xs font-medium text-gray-500 dark:text-gray-400">{{ $heading }}</p>
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @forelse ($logs as $log)
                        <tr class="border-b border-gray-100 dark:border-gray-800">
                            <td class="px-5 py-4 sm:px-6">
                                <span class="text-theme-sm font-medium whitespace-nowrap text-gray-800 dark:text-white/90">
                                    {{ $log->timestamp->format('d/m/Y') }}
                                </span>
                                <p class="text-theme-xs mt-0.5 text-gray-500 dark:text-gray-400">
                                    {{ $log->timestamp->format('H:i:s') }}
                                </p>
                            </td>

                            <td class="px-5 py-4 sm:px-6">
                                <span class="text-theme-sm text-gray-800 dark:text-white/90">{{ $log->pelaku() }}</span>
                                @if ($log->user)
                                    <p class="text-theme-xs mt-0.5 text-gray-500 dark:text-gray-400">{{ $log->user->username }}</p>
                                @endif
                            </td>

                            <td class="px-5 py-4 sm:px-6">
                                @if ($log->actor_role)
                                    <span class="text-theme-xs inline-block rounded-full px-2.5 py-1 font-medium {{ $log->actor_role->badgeClass() }}">
                                        {{ $log->actor_role->label() }}
                                    </span>
                                @else
                                    <span class="text-theme-sm text-gray-500 dark:text-gray-400">—</span>
                                @endif
                            </td>

                            <td class="px-5 py-4 sm:px-6">
                                @if ($log->module)
                                    <span class="text-theme-xs inline-block rounded-full px-2.5 py-1 font-medium {{ $log->module->badgeClass() }}">
                                        {{ $log->module->label() }}
                                    </span>
                                @else
                                    <span class="text-theme-sm text-gray-500 dark:text-gray-400">—</span>
                                @endif
                            </td>

                            <td class="px-5 py-4 sm:px-6">
                                @if ($log->event)
                                    <span class="text-theme-xs inline-block rounded-full px-2.5 py-1 font-medium {{ $log->event->badgeClass() }}">
                                        {{ $log->event->label() }}
                                    </span>
                                @else
                                    <span class="text-theme-sm text-gray-500 dark:text-gray-400">—</span>
                                @endif
                            </td>

                            <td class="px-5 py-4 sm:px-6">
                                <p class="text-theme-sm text-gray-800 dark:text-white/90">{{ $log->activity }}</p>
                                <p class="text-theme-xs mt-0.5 text-gray-500 dark:text-gray-400">{{ $log->alamat() }}</p>
                            </td>

                            <td class="px-5 py-4 sm:px-6">
                                @if ($tautan = $log->tautanSubjek())
                                    <a href="{{ $tautan }}"
                                        class="text-theme-sm text-brand-500 hover:text-brand-600 dark:text-brand-400">
                                        Lihat dokumen
                                    </a>
                                @else
                                    {{-- Aksi tanpa dokumen, atau dokumennya memang sudah dihapus --}}
                                    <span class="text-theme-sm text-gray-500 dark:text-gray-400">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-5 py-10 text-center sm:px-6">
                                <p class="text-theme-sm text-gray-500 dark:text-gray-400">
                                    Tidak ada aktivitas yang cocok.
                                </p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{ $logs->links('vendor.pagination.tailadmin') }}
    </div>
@endsection
