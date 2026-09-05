<?php

namespace Database\Seeders;

use App\Models\Supplier;
use Illuminate\Database\Seeder;

class SupplierSeeder extends Seeder
{
    /**
     * Contoh penyedia barang untuk pengembangan.
     *
     * Nama dan alamat bersifat perkiraan; data penyedia sebenarnya diisi
     * Pejabat Pengadaan lewat aplikasi.
     */
    public function run(): void
    {
        $suppliers = [
            [
                'CV Sumber Rejeki Lampung',
                'Jl. Raden Intan No. 45, Tanjung Karang Pusat, Bandar Lampung',
                '0721-263450',
                'sales@sumberrejeki.co.id',
                'Budi Santoso',
                '01.234.567.8-321.000',
            ],
            [
                'PT Mitra Sarana Perkasa',
                'Jl. Zainal Abidin Pagar Alam No. 12, Rajabasa, Bandar Lampung',
                '0721-704812',
                'pengadaan@mitrasarana.co.id',
                'Siti Rahmawati',
                '02.876.543.2-321.000',
            ],
            [
                'UD Cahaya Kantor',
                'Jl. Teuku Umar No. 88, Kedaton, Bandar Lampung',
                '0721-782900',
                null,
                'Ahmad Fauzi',
                null,
            ],
            [
                'Toko Aneka Bersih',
                'Jl. Pangeran Antasari No. 210, Sukarame, Bandar Lampung',
                '0812-7900-4455',
                null,
                'Dewi Lestari',
                null,
            ],
        ];

        foreach ($suppliers as [$name, $address, $phone, $email, $contact, $npwp]) {
            Supplier::firstOrCreate(
                ['supplier_name' => $name],
                [
                    'address' => $address,
                    'phone' => $phone,
                    'email' => $email,
                    'contact_person' => $contact,
                    'npwp' => $npwp,
                    'is_active' => true,
                ],
            );
        }
    }
}
