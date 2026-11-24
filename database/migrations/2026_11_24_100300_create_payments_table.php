<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /** Создаёт таблицу. */
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stay_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('amount');
            $table->date('paid_at');
            $table->string('note')->nullable();
            $table->timestamps();
        });
    }

    /** Удаляет таблицу. */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
