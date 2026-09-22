@extends('layouts.app')

@section('content')
    @php
        $isEdit = $isEdit ?? false;
        $requestData = $requestData ?? null;
        $initialItems = old('items', $isEdit
            ? $requestData->item->map(fn ($detail) => [
                'item_id' => $detail->item_id,
                'item_name' => $detail->item?->item_name ?? 'Barang',
                'qty' => (int) $detail->quantity_requested,
            ])->values()->all()
            : []);
    @endphp

    <div class="mb-6">
        <h2 class="text-xl font-semibold text-gray-800 dark:text-white/90">{{ $isEdit ? 'Ubah Permintaan Umum' : 'Buat Permintaan Umum' }}</h2>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $isEdit ? 'Perbarui barang dan jumlah sebelum permintaan diproses.' : 'Catat barang yang diminta oleh unit kerja untuk kebutuhan umum.' }}</p>
    </div>

    @if ($errors->any())
        <div class="mb-6 rounded-lg bg-error-50 px-4 py-3 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-500" role="alert">
            Periksa kembali isian formulir.
            <ul class="mt-1 list-inside list-disc">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] md:p-6">
        <form action="{{ $isEdit ? route('request.umum.update', $requestData) : route('request.umum.store') }}" method="POST" x-data="umumRequestForm()">
            @csrf
            @if ($isEdit)
                @method('PUT')
            @endif

            <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
                <div>
                    <label for="item-picker" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                        Nama Barang <span class="text-error-500">*</span>
                    </label>
                    <select id="item-picker" x-model="selectedItemId"
                        class="h-11 w-full rounded-lg border border-gray-300 bg-transparent px-4 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                        <option value="">Pilih barang</option>
                        <template x-for="item in availableItems" :key="item.item_id">
                            <option :value="item.item_id" x-text="item.item_name"></option>
                        </template>
                    </select>
                </div>

                <div>
                    <label for="qty" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                        Jumlah Barang <span class="text-error-500">*</span>
                    </label>
                    <input id="qty" type="number" min="1" max="999999" x-model.number="quantity"
                        class="h-11 w-full rounded-lg border border-gray-300 bg-transparent px-4 text-sm text-gray-800 dark:border-gray-700 dark:text-white/90">
                </div>
            </div>

            <div class="mt-5 flex justify-end">
                <button type="button" @click="addItem" :disabled="!selectedItemId || quantity < 1"
                    class="inline-flex items-center rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-brand-600 disabled:opacity-50">
                    Tambah Barang
                </button>
            </div>

            <div class="mt-6 overflow-hidden rounded-lg border border-gray-200 dark:border-gray-800">
                <div class="border-b border-gray-200 px-4 py-3 dark:border-gray-800">
                    <h3 class="text-sm font-medium text-gray-700 dark:text-gray-300">Daftar Barang yang Diminta</h3>
                </div>
                <template x-if="requestedItems.length === 0">
                    <p class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">Belum ada barang yang ditambahkan.</p>
                </template>
                <template x-if="requestedItems.length > 0">
                    <div class="divide-y divide-gray-200 dark:divide-gray-800">
                        <template x-for="(item, index) in requestedItems" :key="item.item_id">
                            <div class="flex items-center justify-between gap-4 px-4 py-3">
                                <div>
                                    <span class="text-sm font-medium text-gray-800 dark:text-white/90" x-text="item.item_name"></span>
                                    <input type="hidden" :name="`items[${index}][item_id]`" :value="item.item_id">
                                </div>
                                <div class="flex items-center gap-3">
                                    <input type="number" min="1" max="999999" :name="`items[${index}][qty]`" x-model.number="item.qty"
                                        class="h-9 w-24 rounded-md border border-gray-300 px-3 text-right text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                                    <button type="button" @click="removeItem(index)" class="text-sm text-error-500 hover:text-error-600">Hapus</button>
                                </div>
                            </div>
                        </template>
                    </div>
                </template>
            </div>

            <div class="mt-6 flex justify-end">
                <button type="submit" :disabled="requestedItems.length === 0"
                    class="rounded-lg bg-brand-500 px-6 py-3 text-sm font-medium text-white hover:bg-brand-600 disabled:opacity-50">
                    {{ $isEdit ? 'Simpan Perubahan' : 'Simpan Permintaan Umum' }}
                </button>
            </div>
        </form>
    </div>

    <script>
        function umumRequestForm() {
            return {
                items: @json($items),
                selectedItemId: '',
                quantity: 1,
                requestedItems: @json($initialItems),
                get availableItems() {
                    const selected = new Set(this.requestedItems.map(item => item.item_id));
                    return this.items.filter(item => !selected.has(item.item_id));
                },
                addItem() {
                    const item = this.items.find(item => item.item_id === this.selectedItemId);
                    if (!item || this.quantity < 1) return;
                    this.requestedItems.push({ item_id: item.item_id, item_name: item.item_name, qty: Number(this.quantity) });
                    this.selectedItemId = '';
                    this.quantity = 1;
                },
                removeItem(index) {
                    this.requestedItems.splice(index, 1);
                },
            };
        }
    </script>
@endsection
