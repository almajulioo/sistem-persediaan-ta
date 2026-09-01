<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();

        // TODO: ganti dengan data nyata setelah modul persediaan tersedia.
        $metrics = [
            ['label' => 'Total Item Persediaan', 'value' => '248', 'caption' => 'jenis barang terdaftar', 'tone' => 'brand'],
            ['label' => 'Permintaan Menunggu', 'value' => '12', 'caption' => 'perlu ditindaklanjuti', 'tone' => 'warning'],
            ['label' => 'Stok Menipis', 'value' => '7', 'caption' => 'di bawah stok minimum', 'tone' => 'error'],
            ['label' => 'Pengadaan Berjalan', 'value' => '3', 'caption' => 'dalam proses realisasi', 'tone' => 'success'],
        ];

        return view('pages.dashboard.index', [
            'title' => 'Dasbor',
            'user' => $user,
            'metrics' => $metrics,
        ]);
    }
}
