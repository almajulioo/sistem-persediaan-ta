<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class RequestDetail extends Model
{
    use HasFactory, HasUuids;

     protected $primaryKey = 'request_detail_id';
     protected $keyType = 'string';
      public $incrementing = false;

    protected $fillable = [
        'request_id',
        'item_id',
        'quantity_requested',
        'quantity_approved',
    ];

    public function request()
    {
        return $this->belongsTo(Request::class, 'request_id', 'request_id');
    }

    public function item()
    {
        return $this->belongsTo(Item::class, 'item_id', 'item_id');
    }
}
