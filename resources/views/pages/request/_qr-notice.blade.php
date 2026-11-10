{{--
    Pemberitahuan di modal persetujuan yang menghasilkan QR, tepat di atas
    tombol konfirmasi. Isinya dari App\Support\PemberitahuanQr.

    $sumber : ekspresi Alpine berisi { dokumen, kolom, catatan }.
--}}
<div class="border-brand-200 bg-brand-50 dark:border-brand-500/30 dark:bg-brand-500/10 mt-5 rounded-xl border p-4">
    <p class="flex items-center gap-2.5 text-sm font-semibold text-gray-800 dark:text-white/90">
        <span class="bg-brand-500 flex size-7 shrink-0 items-center justify-center rounded-lg text-white">
            <x-ui.icon name="qr-code" class="size-4" />
        </span>
        Persetujuan ini membuat QR verifikasi
    </p>
    <dl class="text-theme-sm mt-3 space-y-1.5">
        <div class="flex gap-3">
            <dt class="w-20 shrink-0 text-gray-500 dark:text-gray-400">Dokumen</dt>
            <dd class="min-w-0 font-medium break-words text-gray-800 dark:text-white/90" x-text="{{ $sumber }}.dokumen"></dd>
        </div>
        <div class="flex gap-3">
            <dt class="w-20 shrink-0 text-gray-500 dark:text-gray-400">Kolom</dt>
            <dd class="min-w-0 font-medium text-gray-800 dark:text-white/90" x-text="{{ $sumber }}.kolom"></dd>
        </div>
    </dl>
    <p class="text-theme-xs mt-3 leading-5 text-gray-600 dark:text-gray-300">
        <span x-text="{{ $sumber }}.catatan"></span>
        Siapa pun yang memindai QR dapat melihat nama Anda dan waktu persetujuan ini.
    </p>
</div>
