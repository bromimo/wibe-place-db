<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;

/** Наполнение базы: в локальном окружении — демо-данные графика. */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /** Заполняет базу.
     * @return void
     */
    public function run(): void
    {
        if (app()->environment('local')) {
            $this->call(DemoSeeder::class);
        }
    }
}
