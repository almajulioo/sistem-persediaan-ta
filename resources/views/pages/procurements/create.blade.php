@extends('layouts.app')

@section('content')
    <div class="mb-6">
        <h2 class="text-xl font-semibold text-gray-800 dark:text-white/90">
            {{ $procurementRequest ? 'Realisasi Pengadaan' : 'Realisasi Pengadaan Langsung' }}
        </h2>
        @if ($procurementRequest)
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Dari {{ $procurementRequest->judul() }}</p>
        @endif
    </div>

    <x-ui.flash />

    @php
        // Dihitung dulu di @php, bukan langsung di dalam @json(): direktif itu
        // memecah argumennya dengan explode(',', ...) tanpa memperhatikan
        // kurung/array bersarang, sehingga ekspresi dengan banyak koma di
        // dalamnya terpotong diam-diam.
        $usulanItemIds = $rincianDisetujui->pluck('item_id')->values();

        // Barang tambahan yang sempat diketik sebelum galat validasi
        // dipulihkan dari old(), supaya tidak hilang begitu saja.
        $tambahanAwal = collect(old('items', []))
            ->filter(fn ($baris) => ! empty($baris['item_id'] ?? null))
            ->map(function ($baris) use ($katalog) {
                $item = $katalog->firstWhere('item_id', $baris['item_id']);

                return [
                    'item_id' => $baris['item_id'],
                    'item_name' => $item ? "{$item['item_code']} — {$item['item_name']}" : 'Barang tidak dikenal',
                    'amount' => $baris['amount'] ?? '',
                    'unit_price' => $baris['unit_price'] ?? '',
                ];
            })
            ->values();
    @endphp

    <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] md:p-6"
        x-data="realisasiForm()">
        <form action="{{ route('procurements.store') }}" method="POST">
            @csrf
            <x-form.token-kiriman />
            @if ($procurementRequest)
                <input type="hidden" name="procurement_request_id" value="{{ $procurementRequest->procurement_request_id }}">
            @endif

            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                <x-form.select-field name="supplier_id" label="Penyedia"
                    :options="$suppliers->pluck('supplier_name', 'supplier_id')->all()" required
                    placeholder="Pilih penyedia" />

                <x-form.text-field name="procurement_date" label="Tanggal Realisasi" type="date"
                    :value="now()->toDateString()" required />
            </div>

            @unless ($procurementRequest)
                {{-- Dasar pengadaan hanya ada di sini. Pada realisasi berusulan
                     tempatnya dokumen usulan itu sendiri (ADR 0031). --}}
                <div class="mt-6 rounded-xl border border-gray-200 p-5 dark:border-gray-800">
                    <h4 class="text-theme-sm font-medium text-gray-800 dark:text-white/90">Dasar Pengadaan</h4>

                    <div class="mt-4">
                        <x-form.select-field name="basis_reason" label="Alasan" required
                            :options="\App\Enums\AlasanPengadaan::options()" placeholder="Pilih dasar pengadaan" />
                    </div>

                    <div class="mt-4">
                        <label for="basis_note" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                            Keterangan<span class="text-error-500">*</span>
                        </label>
                        <textarea id="basis_note" name="basis_note" rows="3" required
                            placeholder="Mis. spidol untuk rapat pleno tanggal 20 September, stok habis dan belum masuk usulan bulan ini"
                            class="dark:bg-dark-900 shadow-theme-xs focus:border-brand-300 focus:ring-brand-500/10 dark:focus:border-brand-800 w-full rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 text-sm text-gray-800 placeholder:text-gray-400 focus:ring-3 focus:outline-hidden dark:border-gray-700 dark:text-white/90 dark:placeholder:text-white/30">{{ old('basis_note') }}</textarea>
                        @error('basis_note')
                            <p class="text-theme-xs text-error-500 mt-1.5">{{ $message }}</p>
                        @enderror
                    </div>
                </div>
            @endunless

            @if ($procurementRequest)
            <div class="mt-6">
                <label class="mb-3 block text-sm font-medium text-gray-700 dark:text-gray-400">
                    Barang dari Usulan
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
                            @foreach ($rincianDisetujui as $index => $detail)
                                <tr class="border-b border-gray-100 dark:border-gray-800">
                                    <td class="px-5 py-4 sm:px-6">
                                        <input type="hidden" name="items[{{ $index }}][procurement_request_detail_id]"
                                            value="{{ $detail->procurement_request_detail_id }}">
                                        <p class="text-theme-sm font-medium text-gray-800 dark:text-white/90">
                                            {{ $detail->item?->item_name ?? 'Barang tidak dikenal' }}
                                        </p>
                                        <p class="text-theme-xs text-gray-500 dark:text-gray-400">{{ $detail->item?->item_code }}</p>
                                    </td>
                                    <td class="px-5 py-4 sm:px-6">
                                        <p class="text-theme-sm text-gray-500 dark:text-gray-400">
                                            {{ number_format($detail->amount_granted, 0, ',', '.') }} {{ $detail->item?->unit->label() }}
                                        </p>
                                    </td>
                                    <td class="px-5 py-4 sm:px-6">
                                        <input type="number" min="1"
                                            name="items[{{ $index }}][amount]"
                                            value="{{ old("items.$index.amount", $detail->amount_granted) }}"
                                            class="h-10 w-28 rounded-lg border border-gray-300 bg-transparent px-3 text-sm text-gray-800 focus:border-brand-300 focus:outline-none focus:ring-2 focus:ring-brand-500/20 dark:border-gray-700 dark:text-white/90" />
                                        @error("items.$index.amount")
                                            <p class="text-theme-xs text-error-500 mt-1">{{ $message }}</p>
                                        @enderror
                                    </td>
                                    <td class="px-5 py-4 sm:px-6">
                                        <input type="number" min="0" name="items[{{ $index }}][unit_price]"
                                            value="{{ old("items.$index.unit_price") }}" placeholder="0"
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
            </div>
            @endif

            <div class="mt-8">
                <label class="mb-3 block text-sm font-medium text-gray-700 dark:text-gray-400">
                    {{ $procurementRequest ? 'Tambah Barang di Luar Usulan' : 'Daftar Barang' }}
                </label>

                <div class="grid grid-cols-1 gap-3 sm:grid-cols-[1fr_140px_160px_auto] sm:items-end">
                    {{-- Barang yang sudah ada di daftar tetap tampil, tetapi tidak dapat dipilih lagi. --}}
                    <x-request.pemilih-barang :subkategori="$subkategori" class="min-w-0" pilih="selectItem(item)" nonaktif="sudahDipakai(item)">
                        <x-slot:badge>
                            <span class="rounded-full bg-gray-100 px-2 py-0.5 font-medium text-gray-600 dark:bg-white/5 dark:text-gray-300"
                                x-text="sudahDipakai(item) ? 'Sudah di daftar' : `Stok ${item.stock_current}`"></span>
                        </x-slot:badge>
                        <x-slot:kosong>
                            <a href="{{ route('item-proposals.create') }}" target="_blank" rel="noopener"
                                class="mt-1 block font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400">
                                Usulkan barang baru
                            </a>
                        </x-slot:kosong>
                    </x-request.pemilih-barang>

                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">Jumlah</label>
                        <input type="number" min="1" x-model="inputAmount" placeholder="0"
                            class="h-11 w-full rounded-lg border border-gray-300 bg-transparent px-4 text-sm text-gray-800 focus:border-brand-300 focus:outline-none focus:ring-2 focus:ring-brand-500/20 dark:border-gray-700 dark:text-white/90" />
                    </div>

                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">Harga Satuan (Rp)</label>
                        <input type="number" min="0" x-model="inputPrice" placeholder="0"
                            class="h-11 w-full rounded-lg border border-gray-300 bg-transparent px-4 text-sm text-gray-800 focus:border-brand-300 focus:outline-none focus:ring-2 focus:ring-brand-500/20 dark:border-gray-700 dark:text-white/90" />
                    </div>

                    <button type="button" @click="addItem"
                        class="bg-brand-500 shadow-theme-xs hover:bg-brand-600 inline-flex h-11 items-center justify-center rounded-lg px-4 text-sm font-medium text-white transition">
                        Tambah
                    </button>
                </div>

                <p x-show="errorMessage" x-cloak x-text="errorMessage" class="text-theme-xs text-error-500 mt-2"></p>

                <template x-if="tambahan.length > 0">
                    <div class="mt-4 overflow-hidden rounded-lg border border-gray-200 dark:border-gray-800">
                        <template x-for="(row, index) in tambahan" :key="row.item_id">
                            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 bg-white px-4 py-3 last:border-b-0 dark:border-gray-800 dark:bg-gray-900">
                                <p class="text-theme-sm font-medium text-gray-800 dark:text-white/90" x-text="row.item_name"></p>

                                <div class="flex items-center gap-3">
                                    <input type="number" min="1" x-model="row.amount"
                                        :name="`items[${usulanCount + index}][amount]`"
                                        class="h-9 w-24 rounded-lg border border-gray-300 bg-transparent px-3 text-sm text-gray-800 focus:border-brand-300 focus:outline-none focus:ring-2 focus:ring-brand-500/20 dark:border-gray-700 dark:text-white/90" />
                                    <input type="number" min="0" x-model="row.unit_price"
                                        :name="`items[${usulanCount + index}][unit_price]`"
                                        class="h-9 w-28 rounded-lg border border-gray-300 bg-transparent px-3 text-sm text-gray-800 focus:border-brand-300 focus:outline-none focus:ring-2 focus:ring-brand-500/20 dark:border-gray-700 dark:text-white/90" />
                                    <input type="hidden" :name="`items[${usulanCount + index}][item_id]`" :value="row.item_id">
                                    <button type="button" @click="tambahan.splice(index, 1)"
                                        class="text-error-500 hover:text-error-600 text-sm">
                                        Hapus
                                    </button>
                                </div>
                            </div>
                        </template>
                    </div>
                </template>

                {{-- Satu tempat untuk galat `items`, terlihat pada kedua rupa
                     formulir: aturan cakupan usulan maupun "minimal satu
                     barang" pada realisasi langsung. --}}
                @error('items')
                    <p class="text-theme-xs text-error-500 mt-3">{{ $message }}</p>
                @enderror
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <a href="{{ $procurementRequest ? route('procurement-requests.show', $procurementRequest) : route('procurements.index') }}"
                    class="rounded-lg px-4 py-2.5 text-sm font-medium text-gray-700 ring-1 ring-gray-300 ring-inset transition hover:bg-gray-50 dark:text-gray-400 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
                    Batal
                </a>
                <button type="submit"
                    class="bg-brand-500 shadow-theme-xs hover:bg-brand-600 inline-flex items-center justify-center rounded-lg px-6 py-2.5 text-sm font-medium text-white transition">
                    Buat Realisasi
                </button>
            </div>
        </form>
    </div>

    <script>
        function realisasiForm() {
            const items = @json($katalog);
            const usulanItemIds = @json($usulanItemIds);
            const tambahanAwal = @json($tambahanAwal);

            return {
                items,
                usulanCount: {{ $rincianDisetujui->count() }},
                selectedItem: null,
                inputAmount: '',
                inputPrice: '',
                errorMessage: '',
                tambahan: tambahanAwal,

                sudahDipakai(item) {
                    return usulanItemIds.includes(item.item_id) || this.tambahan.some(row => row.item_id === item.item_id);
                },

                selectItem(item) {
                    this.selectedItem = item;
                    this.errorMessage = '';
                },

                addItem() {
                    this.errorMessage = '';

                    if (!this.selectedItem) {
                        this.errorMessage = 'Pilih barang yang akan ditambahkan.';
                        return;
                    }

                    if (!this.inputAmount || this.inputAmount <= 0) {
                        this.errorMessage = 'Isi jumlah pesan dengan benar.';
                        return;
                    }

                    if (this.inputPrice === '' || this.inputPrice < 0) {
                        this.errorMessage = 'Isi harga satuan dengan benar.';
                        return;
                    }

                    this.tambahan.push({
                        item_id: this.selectedItem.item_id,
                        item_name: `${this.selectedItem.item_code} — ${this.selectedItem.item_name}`,
                        amount: parseInt(this.inputAmount),
                        unit_price: parseInt(this.inputPrice),
                    });

                    this.selectedItem = null;
                    this.inputAmount = '';
                    this.inputPrice = '';
                },
            };
        }
    </script>
@endsection
