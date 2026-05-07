<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Article extends Model
{
    use HasFactory;

    protected $table = 'Article';
    protected $guarded = [];

    public function unitMeasure()
    {
        return $this->belongsTo(UnitMeasure::class, 'unitMeasure_id');
    }

    public function family()
    {
        return $this->belongsTo(Family::class, 'family_id');
    }

    public function taxRate()
    {
        return $this->belongsTo(TaxRate::class, 'taxRate_id');
    }
}
