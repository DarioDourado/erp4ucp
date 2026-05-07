<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PurchaseOrderC extends Model
{
    use HasFactory;
    protected $table = 'PurchaseOrderC';
    protected $guarded = [];

    public function supplierLink()
    {
        return $this->belongsTo(Supplier::class, 'supplierCode', 'code');
    }

    public function detailLines()
    {
        return $this->hasMany(PurchaseOrderD::class, 'pONumber', 'pONumber');
    }

    public function goodsReceipts()
    {
        return $this->hasMany(GoodsReceiptC::class, 'purchaseOrderId', 'id');
    }
}
