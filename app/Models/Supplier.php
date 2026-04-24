<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    use HasFactory;

    protected $table = "Supplier";
    protected $guarded = [];

    public function postalCodeLink()
    {
        return $this->belongsTo(PostalCode::class, 'postalCode', 'postalCode');
    }
}
