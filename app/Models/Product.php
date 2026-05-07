<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Product extends Model
{
    use HasFactory;
    protected $table = 'Product';
    protected $guarded = [];

    public function codeRateLink(){
        return $this->belongsTO(TaxRate::class,'taxRateCode','taxRateCode');
    }

    public function stockMovements()
    {
        return $this->hasMany(StockMovement::class, 'productCode', 'code');
    }
}
