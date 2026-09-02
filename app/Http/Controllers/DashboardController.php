<?php

namespace App\Http\Controllers;

use App\Enums\StockStatus;
use App\Models\Item;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();

        // Dua metrik pertama sudah memakai data nyata dari master barang.
        // Sisanya masih contoh sampai modul permintaan dan pengadaan tersedia.
        $metrics = [
            [
                'label' => 'Total Item Persediaan',
                'value' => (string) Item::aktif()->count(),
                'caption' => 'jenis barang aktif',
                'tone' => 'brand',
                'dummy' => false,
            ],
            [
                'label' => 'Stok Menipis',
                'value' => (string) Item::aktif()->stockStatus(StockStatus::Menipis)->count(),
                'caption' => 'di bawah atau sama dengan stok minimum',
                'tone' => 'error',
                'dummy' => false,
            ],
            [
                'label' => 'Permintaan Menunggu',
                'value' => '12',
                'caption' => 'perlu ditindaklanjuti',
                'tone' => 'warning',
                'dummy' => true,
            ],
            [
                'label' => 'Pengadaan Berjalan',
                'value' => '3',
                'caption' => 'dalam proses realisasi',
                'tone' => 'success',
                'dummy' => true,
            ],
        ];

        return view('pages.dashboard.index', [
            'title' => 'Dasbor',
            'user' => $user,
            'metrics' => $metrics,
        ]);
    }
}
