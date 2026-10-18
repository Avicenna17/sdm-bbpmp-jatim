<?php

namespace Database\Seeders;

use App\Models\FunctionalCategory;
use App\Models\FunctionalLevel;
use Illuminate\Database\Seeder;

class FunctionalMasterSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['KEAHLIAN' => ['Ahli Pertama', 'Ahli Muda', 'Ahli Madya', 'Ahli Utama'], 'KETERAMPILAN' => ['Pemula', 'Terampil', 'Mahir', 'Penyelia']] as $code => $levels) {
            $category = FunctionalCategory::firstOrCreate(['code' => $code], ['name' => ucfirst(strtolower($code))]);
            foreach ($levels as $i => $name) {
                FunctionalLevel::firstOrCreate(['code' => strtoupper(str_replace(' ', '_', $name))], ['functional_category_id' => $category->id, 'name' => $name, 'sort_order' => $i + 1]);
            }
        }
    }
}
