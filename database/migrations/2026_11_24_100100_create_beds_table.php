<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Создаёт таблицу. */
    public function up(): void
    {
        Schema::create('beds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });
    }

    /** Удаляет таблицу. */
    public function down(): void
    {
        Schema::dropIfExists('beds');
    }
};
