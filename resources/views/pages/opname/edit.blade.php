@extends('layouts.app')

@php
    $ringkasan = $opname->ringkasan();

    // Keadaan awal tiap baris untuk Alpine: selisih dihitung di sisi klien
    // supaya petugas langsung melihatnya saat mengetik.
    $baris = $opname->details->mapWithKeys(fn ($d) => [
        $d->opname_detail_id => [
            'stored' => $d->stock_stored,
            'real' => old("details.{$d->opname_detail_id}.stock_real", $d->stock_real) ?? '',
        ],
    ]);
@endphp

@section('content')
    <div class="mb-6 flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 class="text-xl font-semibold text-gray-800 dark:text-white/90">{{ $opname->judul() }}</h2>
            <nav class="mt-1.5">
                <ol class="flex items-center gap-1.5">
                    <li>
                        <a href="{{ route('opname.index') }}"
                            class="inline-flex items-center gap-1.5 text-sm text-gray-500 dark:text-gray-400">
                            Stok Opname
                            <svg class="stroke-current" width="17" height="16" viewBox="0 0 17 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M6.0765 12.667L10.2432 8.50033L6.0765 4.33366" stroke="" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                        </a>
                    </li>
                    <li class="text-sm text-gray-800 dark:text-white/90">Pencacahan</li>
                </ol>
            </nav>
        </div>

        <span class="text-theme-xs inline-flex items-center rounded-full px-2.5 py-1 font-medium {{ $opname->status->badgeClass() }}">
            {{ $opname->status->label() }}
        </span>
    </div>

    <x-ui.flash />

    @if ($errors->any())
        <div class="bg-error-50 text-error-600 dark:bg-error-500/15 dark:text-error-500 mb-6 rounded-lg px-4 py-3 text-sm"
            role="alert" aria-live="assertive">
            Periksa kembali isian. Terdapat {{ $errors->count() }} kesalahan &mdash; {{ $errors->first() }}
        </div>
    @endif

    <form method="POST" action="{{ route('opname.update', $opname) }}" novalidate
        x-data="{
            baris: @js($baris),
            selisih(id) {
                const b = this.baris[id];
                if (b.real === '' || b.real === null) return null;
                return Number(b.real) - Number(b.stored);
            },
            get belumDicacah() {
                return Object.keys(this.baris).filter(id => this.baris[id].real === '' || this.baris[id].real === null).length;
            },
        }">
        @csrf
        @method('PUT')

        {{-- Ringkasan berjalan --}}
        <div class="mb-6 grid grid-cols-2 gap-4 xl:grid-cols-4 md:gap-6">
            <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                <span class="text-theme-sm text-gray-500 dark:text-gray-400">Total Barang</span>
                <h4 class="text-title-sm mt-2 font-bold text-gray-800 dark:text-white/90">{{ $ringkasan['barang'] }}</h4>
            </div>
            <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                <span class="text-theme-sm text-gray-500 dark:text-gray-400">Belum Dicacah</span>
                <h4 class="text-title-sm mt-2 font-bold"
                    :class="belumDicacah > 0 ? 'text-warning-600 dark:text-orange-400' : 'text-success-600 dark:text-success-500'"
                    x-text="belumDicacah"></h4>
            </div>
            <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                <span class="text-theme-sm text-gray-500 dark:text-gray-400">Tanggal Cacah</span>
                <h4 class="mt-2 text-lg font-bold text-gray-800 dark:text-white/90">
                    {{ $opname->opname_date->translatedFormat('d M Y') }}
                </h4>
            </div>
            <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                <span class="text-theme-sm text-gray-500 dark:text-gray-400">Petugas</span>
                <h4 class="mt-2 text-sm font-medium text-gray-800 dark:text-white/90">
                    {{ $opname->user?->fullname ?? '—' }}
                </h4>
            </div>
        </div>

        <div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-5 sm:px-6">
                <h3 class="text-base font-medium text-gray-800 dark:text-white/90">Hasil Pencacahan Fisik</h3>

                <div class="flex flex-wrap items-center gap-2">
                    <button type="submit"
                        class="rounded-lg px-4 py-3 text-sm font-medium text-gray-700 ring-1 ring-gray-300 ring-inset transition hover:bg-gray-50 dark:text-gray-400 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
                        Simpan Draf
                    </button>
                    <button type="submit" form="form-posting"
                        x-bind:disabled="belumDicacah > 0"
                        x-bind:title="belumDicacah > 0 ? 'Lengkapi seluruh hasil cacah terlebih dahulu' : ''"
                        class="bg-brand-500 shadow-theme-xs hover:bg-brand-600 rounded-lg px-5 py-3 text-sm font-medium text-white transition disabled:cursor-not-allowed disabled:opacity-50">
                        Posting Penyesuaian
                    </button>
                </div>
            </div>

            <div class="custom-scrollbar max-w-full overflow-x-auto border-t border-gray-100 dark:border-gray-800">
                <table class="w-full min-w-[1120px]">
                    <thead>
                        <tr class="border-b border-gray-100 dark:border-gray-800">
                            @foreach (['Kode', 'Nama Barang', 'Satuan', 'Stok Tercatat', 'Stok Fisik', 'Selisih', 'Alasan Selisih', 'Keterangan'] as $heading)
                                <th class="px-5 py-3 text-left sm:px-6">
                                    <p class="text-theme-xs font-medium text-gray-500 dark:text-gray-400">{{ $heading }}</p>
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($opname->details as $detail)
                            @php $id = $detail->opname_detail_id; @endphp
                            <tr class="border-b border-gray-100 dark:border-gray-800">
                                <td class="px-5 py-3 sm:px-6">
                                    <span class="text-theme-sm font-medium text-gray-800 dark:text-white/90">
                                        {{ $detail->item->item_code }}
                                    </span>
                                </td>
                                <td class="px-5 py-3 sm:px-6">
                                    <span class="text-theme-sm text-gray-800 dark:text-white/90">{{ $detail->item->item_name }}</span>
                                </td>
                                <td class="px-5 py-3 sm:px-6">
                                    <span class="text-theme-sm text-gray-500 dark:text-gray-400">{{ $detail->item->unit->label() }}</span>
                                </td>
                                <td class="px-5 py-3 sm:px-6">
                                    <span class="text-theme-sm font-medium text-gray-800 dark:text-white/90">
                                        {{ number_format($detail->stock_stored, 0, ',', '.') }}
                                    </span>
                                </td>
                                <td class="px-5 py-3 sm:px-6">
                                    <input type="number" min="0" max="999999"
                                        name="details[{{ $id }}][stock_real]"
                                        x-model="baris[@js($id)].real"
                                        class="dark:bg-dark-900 shadow-theme-xs focus:border-brand-300 focus:ring-brand-500/10 h-10 w-24 rounded-lg border bg-transparent px-3 text-sm text-gray-800 focus:ring-3 focus:outline-hidden dark:bg-gray-900 dark:text-white/90 {{ $errors->has("details.{$id}.stock_real") ? 'border-error-500' : 'border-gray-300 dark:border-gray-700' }}" />
                                </td>
                                <td class="px-5 py-3 sm:px-6">
                                    <template x-if="selisih(@js($id)) === null">
                                        <span class="text-theme-sm text-gray-400">—</span>
                                    </template>
                                    <template x-if="selisih(@js($id)) !== null">
                                        <span class="text-theme-sm font-semibold"
                                            :class="{
                                                'text-success-600 dark:text-success-500': selisih(@js($id)) > 0,
                                                'text-error-600 dark:text-error-500': selisih(@js($id)) < 0,
                                                'text-gray-500 dark:text-gray-400': selisih(@js($id)) === 0,
                                            }"
                                            x-text="selisih(@js($id)) > 0 ? '+' + selisih(@js($id)) : selisih(@js($id))"></span>
                                    </template>
                                </td>
                                <td class="px-5 py-3 sm:px-6">
                                    <select name="details[{{ $id }}][adjustment_reason]"
                                        x-bind:disabled="selisih(@js($id)) === null || selisih(@js($id)) === 0"
                                        class="dark:bg-dark-900 shadow-theme-xs focus:border-brand-300 focus:ring-brand-500/10 h-10 w-48 rounded-lg border bg-transparent px-3 text-sm text-gray-800 focus:ring-3 focus:outline-hidden disabled:opacity-40 dark:bg-gray-900 dark:text-white/90 {{ $errors->has("details.{$id}.adjustment_reason") ? 'border-error-500' : 'border-gray-300 dark:border-gray-700' }}">
                                        <option value="">Pilih alasan</option>
                                        @foreach (\App\Enums\AlasanSelisih::options() as $value => $label)
                                            <option value="{{ $value }}"
                                                @selected(old("details.{$id}.adjustment_reason", $detail->adjustment_reason?->value) === $value)>
                                                {{ $label }}
                                            </option>
                                        @endforeach
                                    </select>
                                </td>
                                <td class="px-5 py-3 sm:px-6">
                                    <input type="text" maxlength="500"
                                        name="details[{{ $id }}][adjustment_note]"
                                        value="{{ old("details.{$id}.adjustment_note", $detail->adjustment_note) }}"
                                        x-bind:disabled="selisih(@js($id)) === null || selisih(@js($id)) === 0"
                                        placeholder="Rincian penyebab selisih"
                                        class="dark:bg-dark-900 shadow-theme-xs focus:border-brand-300 focus:ring-brand-500/10 h-10 w-64 rounded-lg border bg-transparent px-3 text-sm text-gray-800 placeholder:text-gray-400 focus:ring-3 focus:outline-hidden disabled:opacity-40 dark:bg-gray-900 dark:text-white/90 {{ $errors->has("details.{$id}.adjustment_note") ? 'border-error-500' : 'border-gray-300 dark:border-gray-700' }}" />
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </form>

    {{-- Formulir posting terpisah supaya isian pencacahan tidak ikut terkirim --}}
    <form id="form-posting" method="POST" action="{{ route('opname.post', $opname) }}" class="hidden">
        @csrf
    </form>

    {{-- Dipertahankan: posting memakai data yang tersimpan, bukan isian di
         layar, jadi isian yang belum disimpan akan hilang. --}}
    <p class="text-theme-xs mt-4 text-center text-gray-400 dark:text-gray-500">
        Simpan draf sebelum memposting.
    </p>
@endsection
