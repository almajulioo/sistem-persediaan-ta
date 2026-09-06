@extends('layouts.app')

@section('content')
    @php
        // Batas atas tiap baris tetap jumlah yang disetujui usulan. Dikunci
        // item_id karena procurement_details tidak menyimpan tautan balik ke
        // baris usulannya — aman, satu barang hanya muncul sekali per usulan.
        $disetujui = $procurement->procurementRequest?->details->keyBy('item_id') ?? collect();
    @endphp

    <div class="mb-6">
        <h2 class="text-xl font-semibold text-gray-800 dark:text-white/90">Ubah {{ $procurement->judul() }}</h2>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
            Memperbaiki penyedia, tanggal, jumlah pesan, atau harga satuan yang keliru diketik. Barang belum
            dikonfirmasi diterima, sehingga stok belum tersentuh dan dokumen ini masih boleh diperbaiki.
        </p>
    </div>

    <x-ui.flash />

    <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] md:p-6">
        <form action="{{ route('procurements.update', $procurement) }}" method="POST">
            @csrf
            @method('PUT')

            {{-- Usulan sumber tidak dapat dipindah: ia mengunci identitas
                 dokumen ini beserta batas jumlah yang boleh dipesan. --}}
            <div class="bg-gray-50 dark:bg-white/[0.03] mb-5 rounded-lg p-4">
                <p class="text-theme-xs text-gray-500 dark:text-gray-400">Usulan Sumber</p>
                <p class="text-theme-sm mt-0.5 font-medium text-gray-800 dark:text-white/90">
                    {{ $procurement->procurementRequest?->judul() ?? '—' }}
                    <span class="text-theme-xs font-normal text-gray-500 dark:text-gray-400">
                        &mdash; tidak dapat dipindah ke usulan lain
                    </span>
                </p>
            </div>

            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                <x-form.select-field name="supplier_id" label="Penyedia"
                    :options="$suppliers->pluck('supplier_name', 'supplier_id')->all()"
                    :selected="$procurement->supplier_id" required placeholder="Pilih penyedia" />

                <x-form.text-field name="procurement_date" label="Tanggal Realisasi" type="date"
                    :value="$procurement->procurement_date->toDateString()" required />
            </div>

            <div class="mt-6">
                <label class="mb-3 block text-sm font-medium text-gray-700 dark:text-gray-400">
                    Barang yang Direalisasikan
                </label>

                <div class="custom-scrollbar max-w-full overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-800">
                    <table class="w-full min-w-[640px]">
                        <thead>
                            <tr class="border-b border-gray-100 dark:border-gray-800">
                                @foreach (['Barang', 'Disetujui', 'Jumlah Pesan', 'Harga Satuan (Rp)'] as $heading)
                                    <th class="px-5 py-3 text-left sm:px-6">
                                        <p class="text-theme-xs font-medium text-gray-500 dark:text-gray-400">{{ $heading }}</p>
                                    </th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($procurement->details as $index => $detail)
                                @php $granted = $disetujui->get($detail->item_id)?->amount_granted; @endphp
                                <tr class="border-b border-gray-100 dark:border-gray-800">
                                    <td class="px-5 py-4 sm:px-6">
                                        <input type="hidden" name="items[{{ $index }}][procurement_detail_id]"
                                            value="{{ $detail->procurement_detail_id }}">
                                        <p class="text-theme-sm font-medium text-gray-800 dark:text-white/90">
                                            {{ $detail->item?->item_name ?? 'Barang tidak dikenal' }}
                                        </p>
                                        <p class="text-theme-xs text-gray-500 dark:text-gray-400">{{ $detail->item?->item_code }}</p>
                                    </td>
                                    <td class="px-5 py-4 sm:px-6">
                                        <p class="text-theme-sm text-gray-500 dark:text-gray-400">
                                            {{ $granted !== null ? number_format($granted, 0, ',', '.') : '—' }}
                                            {{ $detail->item?->unit->label() }}
                                        </p>
                                    </td>
                                    <td class="px-5 py-4 sm:px-6">
                                        <input type="number" min="1" @if ($granted !== null) max="{{ $granted }}" @endif
                                            name="items[{{ $index }}][amount]"
                                            value="{{ old("items.$index.amount", $detail->amount) }}"
                                            class="h-10 w-28 rounded-lg border border-gray-300 bg-transparent px-3 text-sm text-gray-800 focus:border-brand-300 focus:outline-none focus:ring-2 focus:ring-brand-500/20 dark:border-gray-700 dark:text-white/90" />
                                        @error("items.$index.amount")
                                            <p class="text-theme-xs text-error-500 mt-1">{{ $message }}</p>
                                        @enderror
                                    </td>
                                    <td class="px-5 py-4 sm:px-6">
                                        <input type="number" min="0" name="items[{{ $index }}][unit_price]"
                                            value="{{ old("items.$index.unit_price", $detail->unit_price) }}"
                                            class="h-10 w-32 rounded-lg border border-gray-300 bg-transparent px-3 text-sm text-gray-800 focus:border-brand-300 focus:outline-none focus:ring-2 focus:ring-brand-500/20 dark:border-gray-700 dark:text-white/90" />
                                        @error("items.$index.unit_price")
                                            <p class="text-theme-xs text-error-500 mt-1">{{ $message }}</p>
                                        @enderror
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @error('items')
                    <p class="text-theme-xs text-error-500 mt-2">{{ $message }}</p>
                @enderror

                <p class="text-theme-xs mt-3 text-gray-500 dark:text-gray-400">
                    Barang tidak dapat ditambah maupun dihapus di sini &mdash; susunannya ditetapkan usulan yang
                    sudah disetujui.
                </p>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <a href="{{ route('procurements.show', $procurement) }}"
                    class="rounded-lg px-4 py-2.5 text-sm font-medium text-gray-700 ring-1 ring-gray-300 ring-inset transition hover:bg-gray-50 dark:text-gray-400 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
                    Batal
                </a>
                <button type="submit"
                    class="bg-brand-500 shadow-theme-xs hover:bg-brand-600 inline-flex items-center justify-center rounded-lg px-6 py-2.5 text-sm font-medium text-white transition">
                    Simpan Perubahan
                </button>
            </div>
        </form>
    </div>
@endsection
