<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /** Создаёт таблицу. */
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedInteger('price');
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });
    }

    /** Удаляет таблицу. */
    public function down(): void
    {
        Schema::dropIfExists('rooms');
    }
};
