@extends('layouts.app')

@section('content')
    {{-- Kasubbag Umum dan TI membuka halaman ini baca-saja (ADR 0041). --}}
    @php $bisaKelola = auth()->user()->can('kelola-pengadaan'); @endphp

    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <h2 class="text-xl font-semibold text-gray-800 dark:text-white/90">Penyedia Barang</h2>

        @if ($bisaKelola)
            <a href="{{ route('suppliers.create') }}"
                class="bg-brand-500 shadow-theme-xs hover:bg-brand-600 inline-flex items-center gap-2 rounded-lg px-4 py-3 text-sm font-medium text-white transition">
                <svg class="size-5 fill-current" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
                    <path fill-rule="evenodd" clip-rule="evenodd" d="M10 3.25a.75.75 0 01.75.75v5.25H16a.75.75 0 010 1.5h-5.25V16a.75.75 0 01-1.5 0v-5.25H4a.75.75 0 010-1.5h5.25V4a.75.75 0 01.75-.75z" />
                </svg>
                Tambah Penyedia
            </a>
        @endif
    </div>

    <x-ui.flash />

    {{-- Ringkasan --}}
    <div class="mb-6 grid grid-cols-2 gap-4 md:gap-6">
        @foreach ([
            ['label' => 'Total Penyedia', 'value' => $summary['total']],
            ['label' => 'Penyedia Aktif', 'value' => $summary['aktif']],
        ] as $card)
            <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                <span class="text-theme-sm text-gray-500 dark:text-gray-400">{{ $card['label'] }}</span>
                <h4 class="text-title-sm mt-2 font-bold text-gray-800 dark:text-white/90">{{ $card['value'] }}</h4>
            </div>
        @endforeach
    </div>

    <div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        {{-- Penyaring --}}
        <form method="GET" action="{{ route('suppliers.index') }}"
            class="grid grid-cols-1 gap-4 border-b border-gray-100 p-5 sm:grid-cols-2 dark:border-gray-800 sm:p-6">
            <x-form.text-field name="q" label="Cari" :value="$keyword" placeholder="Nama penyedia atau narahubung" />

            <x-form.select-field name="status" label="Status Penyedia"
                :options="['aktif' => 'Aktif', 'arsip' => 'Diarsipkan']" :selected="$status"
                placeholder="Semua penyedia" />

            <div class="flex items-center gap-3 sm:col-span-2">
                <button type="submit"
                    class="bg-brand-500 shadow-theme-xs hover:bg-brand-600 inline-flex items-center rounded-lg px-4 py-2.5 text-sm font-medium text-white transition">
                    Terapkan
                </button>
                @if ($keyword !== '' || $status !== '')
                    <a href="{{ route('suppliers.index') }}"
                        class="text-theme-sm inline-flex items-center rounded-lg px-4 py-2.5 font-medium text-gray-700 ring-1 ring-gray-300 ring-inset transition hover:bg-gray-50 dark:text-gray-400 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
                        Reset
                    </a>
                @endif
            </div>
        </form>

        {{-- Tabel --}}
        <div x-data="{ confirmOpen: false, confirmAction: '', confirmName: '' }">
            <div class="custom-scrollbar max-w-full overflow-x-auto">
                <table class="w-full min-w-[900px]">
                    <thead>
                        <tr class="border-b border-gray-100 dark:border-gray-800">
                            @foreach (array_filter(['Nama Penyedia', 'Narahubung', 'Kontak', 'Pengadaan', 'Status', $bisaKelola ? 'Aksi' : null]) as $heading)
                                <th class="px-5 py-3 text-left sm:px-6">
                                    <p class="text-theme-xs font-medium text-gray-500 dark:text-gray-400">{{ $heading }}</p>
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($suppliers as $row)
                            <tr class="border-b border-gray-100 dark:border-gray-800">
                                <td class="px-5 py-4 sm:px-6">
                                    <a href="{{ route('suppliers.show', $row) }}"
                                        class="text-theme-sm hover:text-brand-500 dark:hover:text-brand-400 font-medium text-gray-800 dark:text-white/90">
                                        {{ $row->supplier_name }}
                                    </a>
                                    <p class="text-theme-xs mt-0.5 text-gray-500 dark:text-gray-400">
                                        {{ Str::limit($row->address, 60) }}
                                    </p>
                                </td>
                                <td class="px-5 py-4 sm:px-6">
                                    <p class="text-theme-sm text-gray-500 dark:text-gray-400">
                                        {{ $row->contact_person ?: '—' }}
                                    </p>
                                </td>
                                <td class="px-5 py-4 sm:px-6">
                                    <p class="text-theme-sm text-gray-500 dark:text-gray-400">{{ $row->phone ?: '—' }}</p>
                                    @if ($row->email)
                                        <p class="text-theme-xs mt-0.5 text-gray-500 dark:text-gray-400">{{ $row->email }}</p>
                                    @endif
                                </td>
                                <td class="px-5 py-4 sm:px-6">
                                    {{-- Hanya pengadaan yang benar-benar berjalan.
                                         Yang dibatalkan dan yang ditolak dicacah
                                         terpisah, bukan disembunyikan
                                         (ADR 0026, ADR 0030). --}}
                                    <p class="text-theme-sm text-gray-500 dark:text-gray-400">
                                        {{ $row->procurements_count }}&times;
                                    </p>
                                    @if ($row->procurements_cancelled_count > 0)
                                        <p class="text-theme-xs mt-0.5 text-gray-400 dark:text-gray-500">
                                            {{ $row->procurements_cancelled_count }} dibatalkan
                                        </p>
                                    @endif
                                    @if ($row->procurements_rejected_count > 0)
                                        <p class="text-theme-xs mt-0.5 text-gray-400 dark:text-gray-500">
                                            {{ $row->procurements_rejected_count }} menunggu perbaikan
                                        </p>
                                    @endif
                                </td>
                                <td class="px-5 py-4 sm:px-6">
                                    <span @class([
                                        'text-theme-xs inline-block rounded-full px-2.5 py-1 font-medium',
                                        'bg-success-50 text-success-600 dark:bg-success-500/15 dark:text-success-500' => $row->is_active,
                                        'bg-gray-100 text-gray-600 dark:bg-white/5 dark:text-gray-400' => !$row->is_active,
                                    ])>
                                        {{ $row->is_active ? 'Aktif' : 'Diarsipkan' }}
                                    </span>
                                </td>
                                @if ($bisaKelola)
                                <td class="px-5 py-4 sm:px-6">
                                    <div class="flex items-center gap-2">
                                        <a href="{{ route('suppliers.edit', $row) }}"
                                            class="text-theme-xs rounded-lg px-3 py-1.5 font-medium text-gray-700 ring-1 ring-gray-300 ring-inset transition hover:bg-gray-50 dark:text-gray-400 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
                                            Ubah
                                        </a>

                                        <form method="POST" action="{{ route('suppliers.toggle-status', $row) }}">
                                            @csrf
                                            @method('PATCH')
                                            <button type="submit"
                                                class="text-theme-xs rounded-lg px-3 py-1.5 font-medium text-gray-700 ring-1 ring-gray-300 ring-inset transition hover:bg-gray-50 dark:text-gray-400 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
                                                {{ $row->is_active ? 'Arsipkan' : 'Aktifkan' }}
                                            </button>
                                        </form>

                                        <button type="button"
                                            @click="confirmOpen = true; confirmAction = @js(route('suppliers.destroy', $row)); confirmName = @js($row->supplier_name)"
                                            class="text-theme-xs text-error-600 dark:text-error-500 ring-error-200 dark:ring-error-500/30 hover:bg-error-50 dark:hover:bg-error-500/10 rounded-lg px-3 py-1.5 font-medium ring-1 ring-inset transition">
                                            Hapus
                                        </button>
                                    </div>
                                </td>
                                @endif
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ $bisaKelola ? 6 : 5 }}" class="px-5 py-10 text-center sm:px-6">
                                    <p class="text-theme-sm text-gray-500 dark:text-gray-400">
                                        Belum ada penyedia yang cocok.
                                    </p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{ $suppliers->links('vendor.pagination.tailadmin') }}

            {{-- Konfirmasi hapus --}}
            <div x-show="confirmOpen" x-cloak @keydown.escape.window="confirmOpen = false"
                class="fixed inset-0 z-99999 flex items-center justify-center p-5">
                <div @click="confirmOpen = false" class="fixed inset-0 bg-gray-900/50"></div>

                <div @click.stop class="relative w-full max-w-md rounded-2xl bg-white p-6 dark:bg-gray-900">
                    <h3 class="text-theme-xl font-semibold text-gray-800 dark:text-white/90">Hapus penyedia?</h3>
                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                        <span class="font-medium text-gray-700 dark:text-gray-300" x-text="confirmName"></span>
                        akan dihapus permanen.
                    </p>

                    <div class="mt-6 flex justify-end gap-3">
                        <button type="button" @click="confirmOpen = false"
                            class="rounded-lg px-4 py-2.5 text-sm font-medium text-gray-700 ring-1 ring-gray-300 ring-inset transition hover:bg-gray-50 dark:text-gray-400 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
                            Batal
                        </button>
                        <form method="POST" :action="confirmAction">
                            @csrf
                            @method('DELETE')
                            <button type="submit"
                                class="bg-error-500 hover:bg-error-600 rounded-lg px-4 py-2.5 text-sm font-medium text-white transition">
                                Ya, hapus
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
