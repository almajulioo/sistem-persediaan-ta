{{--
    Peringatan saat QR Nota Dinas diterbitkan ulang, sehingga QR pada salinan
    lama tidak bisa diverifikasi lagi. Isinya dari App\Support\PemberitahuanQr.

    $sumber : ekspresi Alpine berisi { dokumen, catatan }.
--}}
<div class="border-error-200 bg-error-50 dark:border-error-500/30 dark:bg-error-500/10 mt-5 rounded-xl border p-4">
    <p class="text-error-700 dark:text-error-400 flex items-center gap-2.5 text-sm font-semibold">
        <span class="bg-error-600 flex size-7 shrink-0 items-center justify-center rounded-lg text-white">
            <x-ui.icon name="alert-triangle" class="size-4" />
        </span>
        QR lama tidak berlaku lagi
    </p>
    <p class="text-theme-sm mt-3 font-medium break-words text-gray-800 dark:text-white/90" x-text="{{ $sumber }}.dokumen"></p>
    <p class="text-theme-xs mt-1 leading-5 text-gray-600 dark:text-gray-300" x-text="{{ $sumber }}.catatan"></p>
</div>
