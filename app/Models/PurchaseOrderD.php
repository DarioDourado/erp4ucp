<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PurchaseOrderD extends Model
{
    use HasFactory;
    protected $table = 'PurchaseOrderD';
    protected $guarded = [];

    public function goodsReceiptLines()
    {
        return $this->hasMany(GoodsReceiptD::class, 'purchaseOrderDId', 'id');
    }
}
