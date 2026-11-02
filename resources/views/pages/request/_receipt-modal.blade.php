{{--
    Modal penerimaan barang di halaman detail permintaan: konfirmasi diterima
    dan laporan masalah (pemohon), tanggapan dan penutupan atas nama pemohon
    (Pengelola Gudang/Admin). Dibuka lewat event `open-receipt-modal` berisi
    { action, label, title, description, required, placeholder, danger }.
    Konteks ikut dikirim sebagai `receipt_context` supaya modal terbuka lagi
    dengan isi yang sama saat validasi gagal.

    $requestData : permintaan yang sedang ditampilkan.
--}}
@php
    $oldReceipt = $errors->has('note') && old('receipt_context')
        ? json_decode((string) old('receipt_context'), true)
        : null;
@endphp

<x-ui.modal @open-receipt-modal.window="open = true" :isOpen="is_array($oldReceipt)" class="max-w-[520px]">
    <form method="POST" action="{{ route('request.receipt', $requestData) }}" class="p-6 sm:p-8"
        x-data="{ ctx: @js(is_array($oldReceipt) ? $oldReceipt : ['action' => 'confirm', 'label' => '', 'title' => '', 'description' => '', 'required' => false, 'placeholder' => '', 'danger' => false]) }"
        @open-receipt-modal.window="ctx = $event.detail">
        @csrf
        <input type="hidden" name="action" :value="ctx.action">
        <input type="hidden" name="receipt_context" :value="JSON.stringify(ctx)">

        <p class="text-sm font-medium text-brand-600">Penerimaan barang {{ $requestData->request_number }}</p>
        <h3 class="mt-2 text-xl font-semibold text-gray-800 dark:text-white/90" x-text="ctx.title"></h3>
        <p class="mt-3 text-sm leading-6 text-gray-500 dark:text-gray-400" x-text="ctx.description"></p>

        <label for="receipt_note" class="mt-5 mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
            <span x-text="ctx.required ? 'Keterangan' : 'Catatan (opsional)'"></span><span x-show="ctx.required" class="text-error-500">*</span>
        </label>
        <textarea id="receipt_note" name="note" rows="3" maxlength="500" :required="ctx.required" :placeholder="ctx.placeholder"
            class="dark:bg-dark-900 shadow-theme-xs focus:border-brand-300 focus:ring-brand-500/10 dark:focus:border-brand-800 w-full rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 text-sm text-gray-800 placeholder:text-gray-400 focus:ring-3 focus:outline-hidden dark:border-gray-700 dark:text-white/90 dark:placeholder:text-white/30">{{ old('note') }}</textarea>
        @error('note')
            <p class="text-theme-xs text-error-500 mt-1.5">{{ $message }}</p>
        @else
            <p class="text-theme-xs mt-1.5 text-gray-500 dark:text-gray-400">Tercatat di riwayat permintaan.</p>
        @enderror

        <div class="mt-6 flex justify-end gap-3">
            <button type="button" @click="open = false" class="rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/[0.03]">Batal</button>
            <button type="submit" class="rounded-lg px-4 py-2.5 text-sm font-medium text-white"
                :class="ctx.danger ? 'bg-error-500 hover:bg-error-600' : 'bg-success-500 hover:bg-success-600'"
                x-text="ctx.label"></button>
        </div>
    </form>
</x-ui.modal>
