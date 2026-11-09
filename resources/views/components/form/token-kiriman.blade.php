{{--
    Token sekali pakai untuk formulir pembuat dokumen, dibaca
    App\Support\KirimanFormulir (ADR 0046). Halaman yang dipulihkan tombol
    Kembali setelah formulirnya terkirim dimuat ulang, supaya yang tampil
    formulir baru bertoken baru.
--}}
<input type="hidden" name="submission_token" value="{{ \Illuminate\Support\Str::uuid() }}"
    x-data
    x-init="$el.form.addEventListener('submit', (e) => { if (! e.defaultPrevented) $el.dataset.terkirim = '1' });
        window.addEventListener('pageshow', (e) => { if (e.persisted && $el.dataset.terkirim) location.reload() })">
