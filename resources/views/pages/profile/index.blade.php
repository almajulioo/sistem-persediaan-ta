@extends('layouts.app')

@section('content')
    <h2 class="mb-6 text-xl font-semibold text-gray-800 dark:text-white/90">Profil Saya</h2>

    <x-ui.flash />

    <div class="space-y-6">
        @include('pages.profile._identitas')

        <div class="grid grid-cols-1 items-start gap-6 lg:grid-cols-2">
            @include('pages.profile._akun')

            <div class="space-y-6">
                @include('pages.profile._email')
                @include('pages.profile._kata-sandi')
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        /**
         * Pemotong foto profil di peramban (ADR 0038, 0039): foto digeser dan
         * diperbesar di dalam bingkai lingkaran, lalu bagian yang terlihat
         * digambar ke kanvas 512 px dan disimpan ulang sebagai JPEG.
         *
         * - Orientasi EXIF foto ponsel diterapkan peramban saat gambar
         *   digambar ke kanvas. Server tidak dapat melakukannya karena
         *   ekstensi `exif` PHP tidak aktif, dan GD sendiri mengabaikannya.
         * - Metadata ikut terbuang, termasuk lokasi GPS yang biasa ditanam
         *   kamera ponsel.
         * - Hasilnya ~100 KB, jauh di bawah batas unggah bawaan PHP (2 MB).
         *
         * Posisi disimpan sebagai geseran pusat foto dari pusat bingkai (x, y)
         * dalam piksel layar, dan perbesaran sebagai kelipatan dari skala
         * "menutup bingkai" — sehingga zoom 1 selalu berarti sisi pendek foto
         * pas dengan bingkai. Foto tidak pernah boleh menyisakan celah kosong
         * di dalam bingkai; batasi() menjaganya setelah setiap gerakan.
         *
         * Tanpa pustaka: satu-satunya kandidat, Cropper.js, ditolak di
         * ADR 0038 sebagai dependensi baru untuk sebuah avatar.
         */
        window.fotoProfil = function () {
            const KELUARAN = 512;
            // Sama dengan aturan `dimensions` UpdateProfileAvatarRequest.
            const SISI_MIN = 96;
            // Potongan terkecil dari foto asli: membatasi perbesaran supaya
            // hasil 512 px tidak berasal dari segelintir piksel yang buram.
            const POTONGAN_MIN = 128;
            const ZOOM_MAKS = 4;
            const FORMAT = ['image/jpeg', 'image/png', 'image/webp'];

            const jarak = (a, b) => Math.hypot(a.x - b.x, a.y - b.y);

            return {
                menuBuka: false,
                lihatBuka: false,
                potongBuka: false,
                menyimpan: false,
                galat: '',

                sumber: null,
                gambar: null,
                bingkai: 0,
                zoom: 1,
                zoomMaks: 1,
                x: 0,
                y: 0,
                penunjuk: new Map(),
                awal: null,

                // Lebar bingkai dibaca lewat ResizeObserver, bukan sesaat setelah
                // modal dibuka. x-show Alpine baru menampilkan modal pada
                // animation frame berikutnya, sedangkan $nextTick selesai lebih
                // dulu lewat setTimeout — bingkai masih display:none dan lebarnya
                // 0, sehingga foto tak tampil dan hasil potongan putih polos.
                // Pengamat ini terpicu tepat saat bingkai benar-benar tampil,
                // dan juga saat layar berubah ukuran (ponsel diputar).
                init() {
                    new ResizeObserver(() => this.ukurBingkai()).observe(this.$refs.bingkai);
                },

                pilihBerkas() {
                    this.menuBuka = false;
                    this.$refs.berkas.click();
                },

                async pilih(event) {
                    const berkas = event.target.files[0];
                    this.galat = '';

                    if (!berkas) {
                        return;
                    }

                    if (!FORMAT.includes(berkas.type)) {
                        return this.tolak('Foto harus berformat JPG, PNG, atau WEBP.');
                    }

                    const url = URL.createObjectURL(berkas);
                    const gambar = new Image();
                    gambar.src = url;

                    try {
                        await gambar.decode();
                    } catch (e) {
                        URL.revokeObjectURL(url);

                        return this.tolak('Foto tidak dapat dibaca.');
                    }

                    const sisi = Math.min(gambar.naturalWidth, gambar.naturalHeight);

                    if (sisi < SISI_MIN) {
                        URL.revokeObjectURL(url);

                        return this.tolak(`Foto terlalu kecil. Minimal ${SISI_MIN}×${SISI_MIN} piksel.`);
                    }

                    this.gambar = gambar;
                    this.sumber = url;
                    this.zoom = 1;
                    this.zoomMaks = Math.max(1, Math.min(ZOOM_MAKS, sisi / POTONGAN_MIN));
                    this.x = 0;
                    this.y = 0;
                    this.potongBuka = true;
                },

                /** Piksel layar per piksel foto asli. */
                get skala() {
                    if (!this.gambar || !this.bingkai) {
                        return 0;
                    }

                    const sisi = Math.min(this.gambar.naturalWidth, this.gambar.naturalHeight);

                    return (this.bingkai / sisi) * this.zoom;
                },

                get gayaGambar() {
                    const s = this.skala;

                    if (!s) {
                        return 'visibility: hidden';
                    }

                    return `width: ${this.gambar.naturalWidth * s}px; `
                        + `height: ${this.gambar.naturalHeight * s}px; `
                        + `transform: translate(calc(-50% + ${this.x}px), calc(-50% + ${this.y}px));`;
                },

                ukurBingkai() {
                    const baru = this.$refs.bingkai.clientWidth;
                    const baruTampil = !this.bingkai && baru > 0;

                    // Layar berubah ukuran (ponsel diputar): posisi ikut diskalakan.
                    if (this.bingkai && baru) {
                        this.x *= baru / this.bingkai;
                        this.y *= baru / this.bingkai;
                    }

                    this.bingkai = baru;
                    this.batasi();

                    if (baruTampil && this.potongBuka) {
                        this.$refs.bingkai.focus();
                    }
                },

                batasi() {
                    const s = this.skala;

                    if (!s) {
                        return;
                    }

                    const bx = Math.max(0, (this.gambar.naturalWidth * s - this.bingkai) / 2);
                    const by = Math.max(0, (this.gambar.naturalHeight * s - this.bingkai) / 2);

                    this.x = Math.min(bx, Math.max(-bx, this.x));
                    this.y = Math.min(by, Math.max(-by, this.y));
                },

                /** Perbesar mengelilingi pusat bingkai: yang di tengah tetap di tengah. */
                aturZoom(nilai) {
                    const lama = this.skala;
                    this.zoom = Math.min(this.zoomMaks, Math.max(1, Number(nilai)));

                    if (lama) {
                        this.x *= this.skala / lama;
                        this.y *= this.skala / lama;
                    }

                    this.batasi();
                },

                geser(dx, dy) {
                    this.x += dx;
                    this.y += dy;
                    this.batasi();
                },

                roda(event) {
                    this.aturZoom(this.zoom * Math.exp(-event.deltaY * 0.002));
                },

                // Satu penunjuk menggeser, dua penunjuk (cubitan) memperbesar.
                // Pointer events menyatukan tetikus, sentuhan, dan pena.
                tekan(event) {
                    event.currentTarget.setPointerCapture(event.pointerId);
                    this.penunjuk.set(event.pointerId, { x: event.clientX, y: event.clientY });
                    this.mulaiGerakan();
                },

                gerak(event) {
                    if (!this.penunjuk.has(event.pointerId)) {
                        return;
                    }

                    this.penunjuk.set(event.pointerId, { x: event.clientX, y: event.clientY });
                    const [a, b] = [...this.penunjuk.values()];

                    if (b) {
                        this.aturZoom(this.awal.zoom * (jarak(a, b) / this.awal.jarak));
                    } else {
                        this.x = this.awal.x + (a.x - this.awal.px);
                        this.y = this.awal.y + (a.y - this.awal.py);
                        this.batasi();
                    }
                },

                lepas(event) {
                    this.penunjuk.delete(event.pointerId);
                    this.mulaiGerakan();
                },

                // Titik awal diambil ulang setiap jumlah penunjuk berubah, supaya
                // foto tidak meloncat saat satu jari diangkat dari cubitan.
                mulaiGerakan() {
                    const [a, b] = [...this.penunjuk.values()];

                    if (!a) {
                        this.awal = null;
                    } else if (b) {
                        this.awal = { zoom: this.zoom, jarak: Math.max(1, jarak(a, b)) };
                    } else {
                        this.awal = { px: a.x, py: a.y, x: this.x, y: this.y };
                    }
                },

                /** Bagian foto asli yang tampak di dalam bingkai, dalam piksel foto. */
                potonganSumber() {
                    const s = this.skala;

                    return {
                        sx: (this.gambar.naturalWidth * s / 2 - this.x - this.bingkai / 2) / s,
                        sy: (this.gambar.naturalHeight * s / 2 - this.y - this.bingkai / 2) / s,
                        sisi: this.bingkai / s,
                    };
                },

                async simpan() {
                    // Jangan pernah mengirim kanvas kosong: tanpa skala, potongannya NaN.
                    if (!this.skala) {
                        return;
                    }

                    this.menyimpan = true;
                    const { sx, sy, sisi } = this.potonganSumber();

                    try {
                        const kanvas = document.createElement('canvas');
                        kanvas.width = kanvas.height = KELUARAN;

                        const ctx = kanvas.getContext('2d');
                        // PNG transparan menjadi hitam bila langsung dijadikan JPEG.
                        ctx.fillStyle = '#ffffff';
                        ctx.fillRect(0, 0, KELUARAN, KELUARAN);
                        ctx.imageSmoothingQuality = 'high';
                        ctx.drawImage(this.gambar, sx, sy, sisi, sisi, 0, 0, KELUARAN, KELUARAN);

                        const hasil = await new Promise((resolve, reject) => {
                            kanvas.toBlob((blob) => (blob ? resolve(blob) : reject()), 'image/jpeg', 0.9);
                        });

                        const wadah = new DataTransfer();
                        wadah.items.add(new File([hasil], 'foto-profil.jpg', { type: 'image/jpeg' }));
                        this.$refs.berkas.files = wadah.files;
                    } catch (e) {
                        // Peramban lama tanpa DataTransfer: berkas asli yang
                        // terkirim, tanpa potongan; server tetap memvalidasinya.
                    }

                    this.$refs.formulir.submit();
                },

                batalPotong() {
                    this.potongBuka = false;
                    this.penunjuk.clear();
                    this.awal = null;
                    URL.revokeObjectURL(this.sumber);
                    this.sumber = null;
                    this.gambar = null;
                    // Dikosongkan supaya memilih berkas yang sama lagi tetap memicu `change`.
                    this.$refs.berkas.value = '';
                },

                tolak(pesan) {
                    this.$refs.berkas.value = '';
                    this.galat = pesan;
                },
            };
        };

        /**
         * Daftar syarat kata sandi yang tercentang saat mengetik. Hanya
         * petunjuk — penentunya tetap Password::defaults() di server. Pola
         * regex-nya sama dengan yang dipakai aturan Password milik Laravel.
         */
        window.syaratSandi = function (min) {
            return {
                sandi: '',

                get cukupPanjang() {
                    return this.sandi.length >= min;
                },

                get hurufBesarKecil() {
                    return /\p{Ll}/u.test(this.sandi) && /\p{Lu}/u.test(this.sandi);
                },

                get adaHuruf() {
                    return /\p{L}/u.test(this.sandi);
                },

                get adaAngka() {
                    return /\p{N}/u.test(this.sandi);
                },

                get adaSimbol() {
                    return /[\p{Z}\p{S}\p{P}]/u.test(this.sandi);
                },
            };
        };
    </script>
@endpush
