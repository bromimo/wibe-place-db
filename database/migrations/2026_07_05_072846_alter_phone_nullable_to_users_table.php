<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone')->nullable()->change();
        });

        DB::table('users')
          ->where('phone', '000')
          ->update([
              'phone' => null,
          ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('users')
          ->whereNull('phone')
          ->update([
              'phone' => '000',
          ]);

        Schema::table('users', function (Blueprint $table) {
            $table->string('phone')->nullable(false)->change();
        });
    }
};