<?php

namespace App\Http\Requests\Request;

use App\Enums\UserRole;
use App\Models\Request as ModelsRequest;

class UpdatePermintaanBarangRequest extends StorePermintaanBarangRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $request = $this->route('request');

        return $user !== null
            && $request instanceof ModelsRequest
            && $request->status === 'approved_by_head'
            && $user->hasRole(UserRole::PengelolaGudang, UserRole::Admin, UserRole::KasubbagUmumTi);
    }

}
