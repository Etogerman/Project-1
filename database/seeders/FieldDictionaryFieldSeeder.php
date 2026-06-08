<?php

namespace Database\Seeders;

use App\Models\FieldDictionaryField;
use Illuminate\Database\Seeder;

class FieldDictionaryFieldSeeder extends Seeder
{
    public function run(): void
    {
        FieldDictionaryField::syncSystemDefinitions();
    }
}
