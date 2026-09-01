{{-- Banner pesan sesi: sukses (status) dan gagal (error). --}}
@if (session('status'))
    <div class="bg-success-50 text-success-600 dark:bg-success-500/15 dark:text-success-500 mb-6 flex items-start gap-3 rounded-lg px-4 py-3 text-sm"
        role="status">
        <svg class="mt-0.5 size-4 shrink-0 fill-current" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
            <path fill-rule="evenodd" clip-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.7-9.3a1 1 0 00-1.4-1.4L9 10.6 7.7 9.3a1 1 0 00-1.4 1.4l2 2a1 1 0 001.4 0l4-4z" />
        </svg>
        <span>{{ session('status') }}</span>
    </div>
@endif

@if (session('error'))
    <div class="bg-error-50 text-error-600 dark:bg-error-500/15 dark:text-error-500 mb-6 flex items-start gap-3 rounded-lg px-4 py-3 text-sm"
        role="alert" aria-live="assertive">
        <svg class="mt-0.5 size-4 shrink-0 fill-current" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
            <path fill-rule="evenodd" clip-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-11a1 1 0 10-2 0v4a1 1 0 102 0V7zm-1 7a1 1 0 100 2 1 1 0 000-2z" />
        </svg>
        <span>{{ session('error') }}</span>
    </div>
@endif
