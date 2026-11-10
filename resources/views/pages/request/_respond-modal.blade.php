{{--
    Modal keputusan Setujui/Tolak, dipakai halaman daftar dan detail
    permintaan. Dibuka lewat event `open-respond-modal` berisi
    { id, number, action: 'approve'|'reject', label, needsNd, preview, sequence, qr, qrUlang }.

    Catatan wajib saat menolak dan opsional saat menyetujui; catatan itulah
    yang dibaca pemohon di riwayat permintaan dan notifikasinya. Nomor urut
    Nota Dinas hanya diminta untuk permintaan yang belum bernomor (data lama
    atau hasil penggabungan permintaan umum). Konteks ikut dikirim sebagai
    `respond_context` supaya modal terbuka lagi dengan isi yang sama saat
    validasi gagal. `qr` berisi pemberitahuan dari App\Support\PemberitahuanQr,
    tampil hanya saat menyetujui tahap yang memang menghasilkan QR. `qrUlang`
    berisi peringatan bila nomor yang diisi menerbitkan ulang QR Nota Dinas.

    $kembali (opsional): 'dasbor' supaya kembali ke dasbor setelah diproses.
--}}
@php
    $oldRespond = ($errors->has('note') || $errors->has('nd_sequence')) && old('respond_context')
        ? json_decode((string) old('respond_context'), true)
        : null;
    $fieldClass = 'dark:bg-dark-900 shadow-theme-xs focus:border-brand-300 focus:ring-brand-500/10 dark:focus:border-brand-800 w-full rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 text-sm text-gray-800 placeholder:text-gray-400 focus:ring-3 focus:outline-hidden dark:border-gray-700 dark:text-white/90 dark:placeholder:text-white/30';
@endphp

<x-ui.modal @open-respond-modal.window="open = true" :isOpen="is_array($oldRespond)" class="max-w-[520px]">
    <form method="POST" :action="actionUrl.replace('__REQUEST__', ctx.id)" class="p-6 sm:p-8"
        x-data="{
            ctx: @js(is_array($oldRespond) ? $oldRespond : ['id' => '', 'number' => '', 'action' => 'approve', 'label' => 'Setujui', 'needsNd' => false, 'preview' => '', 'sequence' => '']),
            actionUrl: @js(route('request.respond', '__REQUEST__')),
            sequence: @js((string) old('nd_sequence', '')),
            get isReject() { return this.ctx.action === 'reject'; },
        }"
        @open-respond-modal.window="ctx = $event.detail; sequence = $event.detail.sequence ?? ''">
        @csrf
        <input type="hidden" name="action" :value="ctx.action">
        <input type="hidden" name="respond_context" :value="JSON.stringify(ctx)">
        @isset($kembali)
            <input type="hidden" name="kembali" value="{{ $kembali }}">
        @endisset

        <p class="text-sm font-medium" :class="isReject ? 'text-error-500' : 'text-brand-600'">
            <span x-text="isReject ? 'Tolak' : 'Proses'"></span> permintaan <span x-text="ctx.number"></span>
        </p>
        <h3 class="mt-2 text-xl font-semibold text-gray-800 dark:text-white/90"
            x-text="isReject ? 'Tolak permintaan ini?' : ctx.label + ' permintaan ini?'"></h3>

        <template x-if="ctx.needsNd && ! isReject">
            <div>
                <p class="mt-3 text-sm leading-6 text-gray-500 dark:text-gray-400">
                    Permintaan ini belum bernomor. Isi nomor urut Nota Dinas; nomor BAST memakai nomor urut yang sama.
                </p>
                <label for="nd_sequence_modal" class="mt-5 mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                    Nomor Urut Nota Dinas<span class="text-error-500">*</span>
                </label>
                <input id="nd_sequence_modal" type="text" name="nd_sequence" x-model="sequence" required inputmode="numeric"
                    pattern="\d{1,6}" maxlength="6" placeholder="mis. 51" autocomplete="off" class="h-11 {{ $fieldClass }}">
                <p class="text-theme-xs mt-1.5 break-all text-gray-500 dark:text-gray-400"
                    x-text="'Nomor lengkap: ' + ctx.preview.replace('……', sequence.trim() || '……')"></p>
                @error('nd_sequence')
                    <p class="text-theme-xs text-error-500 mt-1.5">{{ $message }}</p>
                @enderror
            </div>
        </template>

        <label for="respond_note" class="mt-5 mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
            <span x-text="isReject ? 'Alasan penolakan' : 'Catatan untuk pemohon (opsional)'"></span><span x-show="isReject" class="text-error-500">*</span>
        </label>
        <textarea id="respond_note" name="note" rows="3" maxlength="500" :required="isReject"
            :placeholder="isReject ? 'mis. stok kertas sedang kosong, ajukan kembali bulan depan' : 'mis. barang dapat diambil di gudang mulai besok'"
            class="{{ $fieldClass }}">{{ old('note') }}</textarea>
        @error('note')
            <p class="text-theme-xs text-error-500 mt-1.5">{{ $message }}</p>
        @else
            <p class="text-theme-xs mt-1.5 text-gray-500 dark:text-gray-400">Tampil di riwayat permintaan dan dikirim ke pemohon.</p>
        @enderror

        <template x-if="ctx.qr && ! isReject">
            <div>
                @include('pages.request._qr-notice', ['sumber' => 'ctx.qr'])
            </div>
        </template>

        <template x-if="ctx.qrUlang && ! isReject">
            <div>
                @include('pages.request._qr-warning', ['sumber' => 'ctx.qrUlang'])
            </div>
        </template>

        <div class="mt-6 flex justify-end gap-3">
            <button type="button" @click="open = false" class="rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/[0.03]">Batal</button>
            <button type="submit" class="rounded-lg px-4 py-2.5 text-sm font-medium text-white"
                :class="isReject ? 'bg-error-500 hover:bg-error-600' : 'bg-success-500 hover:bg-success-600'"
                x-text="ctx.label"></button>
        </div>
    </form>
</x-ui.modal>
