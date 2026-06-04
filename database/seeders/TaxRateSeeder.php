<?php

namespace Database\Seeders;

use App\Models\TaxRate;
use Illuminate\Database\Seeder;

class TaxRateSeeder extends Seeder
{
    public function run(): void
    {
        $rates = [
            ['taxRateCode' => 6, 'descriptionTaxRate' => 'IVA 6%', 'taxRate' => 6.00],
            ['taxRateCode' => 13, 'descriptionTaxRate' => 'IVA 13%', 'taxRate' => 13.00],
            ['taxRateCode' => 23, 'descriptionTaxRate' => 'IVA 23%', 'taxRate' => 23.00],
        ];

        foreach ($rates as $rate) {
            TaxRate::firstOrCreate(
                ['taxRateCode' => $rate['taxRateCode']],
                $rate,
            );
        }
    }
}
