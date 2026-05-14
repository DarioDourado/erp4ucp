<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GoodsReceiptD extends Model
{
    use HasFactory;

    protected $table = 'GoodsReceiptD';
    protected $guarded = [];

    public function goodsReceiptLink()
    {
        return $this->belongsTo(GoodsReceiptC::class, 'goodsReceiptId', 'id');
    }

    public function purchaseOrderLineLink()
    {
        return $this->belongsTo(PurchaseOrderD::class, 'purchaseOrderDId', 'id');
    }
}
