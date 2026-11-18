{{-- Ikon tab dan layar utama: lambang BPK, dibuat dari images/logo/logo-bpk.webp.
     ?v= memaksa peramban mengambil ulang. Server menyimpan favicon.ico 30 hari
     (immutable), jadi setiap kali berkas ikon diganti, naikkan nilainya. --}}
<link rel="icon" href="{{ asset('favicon.ico') }}?v=bpk2" sizes="16x16 32x32 48x48">
<link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}?v=bpk2">
