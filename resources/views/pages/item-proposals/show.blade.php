@extends('layouts.app')

@section('content')
    <div class="mb-6 flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 class="text-xl font-semibold text-gray-800 dark:text-white/90">{{ $proposal->item_name }}</h2>
            <nav class="mt-1">
                <ol class="flex items-center gap-1.5 text-sm">
                    <li>
                        <a href="{{ route('item-proposals.index') }}" class="text-gray-500 hover:text-brand-500 dark:text-gray-400">
                            Usulan Barang Baru
                        </a>
                    </li>
                    <li class="text-gray-400">/</li>
                    <li class="text-sm text-gray-800 dark:text-white/90">{{ $proposal->item_name }}</li>
                </ol>
            </nav>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <span class="text-theme-xs inline-block rounded-full px-2.5 py-1 font-medium {{ $proposal->status->badgeClass() }}">
                {{ $proposal->status->label() }}
            </span>

            {{-- Menyetujui berarti mendaftarkan barangnya: tombolnya menuju
                 formulir barang yang sudah terisi, bukan aksi tersendiri.
                 Persetujuan tanpa baris `items` tidak berarti apa pun, dan
                 kode barang serta batas stoknya memang hanya diketahui
                 Pengelola Gudang (ADR 0032). --}}
            @if ($proposal->isDiajukan())
                @can('kelola-gudang')
                    <a href="{{ route('items.create', ['item_proposal_id' => $proposal->item_proposal_id]) }}"
                        class="bg-brand-500 shadow-theme-xs hover:bg-brand-600 inline-flex items-center rounded-lg px-4 py-2.5 text-sm font-medium text-white transition">
                        Setujui &amp; Buat Barang
                    </a>
                    <button type="button" x-data @click="$dispatch('buka-tolak-usulan-barang')"
                        class="text-error-500 hover:bg-error-50 ring-error-300 dark:ring-error-500/30 dark:hover:bg-error-500/10 inline-flex items-center rounded-lg px-4 py-2.5 text-sm font-medium ring-1 ring-inset transition">
                        Tolak
                    </button>
                @endcan
            @elseif ($proposal->isDisetujui() && $proposal->item)
                <a href="{{ route('items.show', $proposal->item) }}"
                    class="inline-flex items-center rounded-lg px-4 py-2.5 text-sm font-medium text-gray-700 ring-1 ring-gray-300 ring-inset transition hover:bg-gray-50 dark:text-gray-400 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
                    Lihat Barang {{ $proposal->item->item_code }}
                </a>
            @endif
        </div>
    </div>

    <x-ui.flash />

    <div class="grid grid-cols-12 gap-4 md:gap-6"
        x-data="{ tolakTerbuka: @js($errors->has('decision_note')) }"
        @buka-tolak-usulan-barang.window="tolakTerbuka = true">

        {{-- Identitas usulan --}}
        <div class="col-span-12 space-y-4 md:space-y-6 xl:col-span-5">
            <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] md:p-6">
                <dl class="space-y-3">
                    @foreach ([
                        'Nama Diusulkan' => $proposal->item_name,
                        'Satuan' => $proposal->unit->label(),
                        'Pengusul' => $proposal->proposedBy?->fullname ?? '—',
                        'Diajukan' => $proposal->created_at->translatedFormat('d F Y, H:i'),
                    ] as $label => $value)
                        <div class="flex items-start justify-between gap-4">
                            <dt class="text-theme-sm text-gray-500 dark:text-gray-400">{{ $label }}</dt>
                            <dd class="text-theme-sm text-right font-medium text-gray-800 dark:text-white/90">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>
            </div>

            {{-- Hasil keputusan Pengelola Gudang. --}}
            @if ($proposal->sudahDiputuskan())
                <div @class([
                    'rounded-2xl border bg-white p-5 dark:bg-white/[0.03] md:p-6',
                    'border-error-200 dark:border-error-500/30' => $proposal->isDitolak(),
                    'border-gray-200 dark:border-gray-800' => ! $proposal->isDitolak(),
                ])>
                    <h3 class="text-base font-medium text-gray-800 dark:text-white/90">
                        {{ $proposal->isDitolak() ? 'Ditolak' : 'Disetujui' }}
                    </h3>

                    <dl class="mt-3 space-y-3">
                        @foreach ([
                            'Diputuskan Oleh' => $proposal->decidedBy?->fullname ?? '—',
                            'Tanggal' => $proposal->decided_at?->translatedFormat('d F Y, H:i') ?? '—',
                            'Barang Terdaftar' => $proposal->item?->item_code ?? '—',
                        ] as $label => $value)
                            <div class="flex items-start justify-between gap-4">
                                <dt class="text-theme-sm text-gray-500 dark:text-gray-400">{{ $label }}</dt>
                                <dd class="text-theme-sm text-right font-medium text-gray-800 dark:text-white/90">{{ $value }}</dd>
                            </div>
                        @endforeach
                    </dl>

                    @if ($proposal->decision_note)
                        <p class="text-theme-xs mt-4 border-t border-gray-100 pt-4 text-gray-500 dark:border-gray-800 dark:text-gray-400">
                            {{ $proposal->decision_note }}
                        </p>
                    @endif
                </div>
            @endif
        </div>

        {{-- Kegunaan barang --}}
        <div class="col-span-12 xl:col-span-7">
            <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] md:p-6">
                <h3 class="text-base font-medium text-gray-800 dark:text-white/90">Kegunaan Barang</h3>
                <p class="text-theme-sm mt-3 whitespace-pre-line text-gray-600 dark:text-gray-300">{{ $proposal->purpose_note }}</p>

                @if ($proposal->isDiajukan())
                    {{-- Satu barang fisik dengan dua baris di master barang merusak
                         batas minimum, opname, dan kartu persediaannya (ADR 0032). --}}
                    <div class="bg-warning-50 dark:bg-warning-500/15 mt-5 rounded-lg p-4">
                        <p class="text-theme-xs text-warning-700 dark:text-warning-400">
                            Pastikan barangnya belum terdaftar dengan nama lain sebelum menyetujui.
                        </p>
                    </div>
                @endif
            </div>
        </div>

        {{-- Dialog penolakan, terbuka sendiri bila validasinya gagal supaya
             pesan galatnya tidak tersembunyi. --}}
        @can('kelola-gudang')
            @if ($proposal->isDiajukan())
                <div x-show="tolakTerbuka" x-cloak
                    class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
                    <div @click.outside="tolakTerbuka = false"
                        class="w-full max-w-lg rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-gray-900 md:p-6">
                        <h3 class="text-base font-medium text-gray-800 dark:text-white/90">Tolak Usulan Barang</h3>
                        <p class="text-theme-xs mt-1 text-gray-500 dark:text-gray-400">
                            Usulan "{{ $proposal->item_name }}" tidak akan didaftarkan ke master barang.
                        </p>

                        <form method="POST" action="{{ route('item-proposals.reject', $proposal) }}" class="mt-5">
                            @csrf
                            @method('PATCH')

                            <div>
                                <label for="decision_note" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                                    Alasan Penolakan<span class="text-error-500">*</span>
                                </label>
                                <textarea id="decision_note" name="decision_note" rows="3" required
                                    placeholder="mis. barangnya sudah terdaftar sebagai ATK-014 Spidol Permanen Emas"
                                    class="dark:bg-dark-900 shadow-theme-xs focus:border-brand-300 focus:ring-brand-500/10 dark:focus:border-brand-800 w-full rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 text-sm text-gray-800 placeholder:text-gray-400 focus:ring-3 focus:outline-hidden dark:border-gray-700 dark:text-white/90 dark:placeholder:text-white/30">{{ old('decision_note') }}</textarea>
                                @error('decision_note')
                                    <p class="text-theme-xs text-error-500 mt-1.5">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="mt-6 flex justify-end gap-3">
                                <button type="button" @click="tolakTerbuka = false"
                                    class="rounded-lg px-4 py-2.5 text-sm font-medium text-gray-700 ring-1 ring-gray-300 ring-inset transition hover:bg-gray-50 dark:text-gray-400 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
                                    Batal
                                </button>
                                <button type="submit"
                                    class="bg-error-500 shadow-theme-xs hover:bg-error-600 inline-flex items-center justify-center rounded-lg px-6 py-2.5 text-sm font-medium text-white transition">
                                    Tolak Usulan
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            @endif
        @endcan
    </div>
@endsection
