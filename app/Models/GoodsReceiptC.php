<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GoodsReceiptC extends Model
{
    use HasFactory;

    protected $table = 'GoodsReceiptC';
    protected $guarded = [];

    public function supplierLink()
    {
        return $this->belongsTo(Supplier::class, 'supplierCode', 'code');
    }

    public function purchaseOrderLink()
    {
        return $this->belongsTo(PurchaseOrderC::class, 'purchaseOrderId', 'id');
    }

    public function detailLines()
    {
        return $this->hasMany(GoodsReceiptD::class, 'goodsReceiptId', 'id');
    }
}
