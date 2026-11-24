<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /** Создаёт таблицу. */
    public function up(): void
    {
        Schema::create('stays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bed_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->date('date_from');
            $table->date('date_to');
            $table->string('status');
            $table->string('tariff');
            $table->unsignedInteger('price_per_night')->nullable();
            $table->unsignedInteger('manual_total')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['bed_id', 'date_from', 'date_to']);
        });
    }

    /** Удаляет таблицу. */
    public function down(): void
    {
        Schema::dropIfExists('stays');
    }
};
