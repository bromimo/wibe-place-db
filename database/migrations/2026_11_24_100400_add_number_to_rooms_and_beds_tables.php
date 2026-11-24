<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /** Заменяет порядок sort номерами комнат и коек; существующие строки нумеруются по sort, id. */
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->unsignedInteger('number')->nullable()->after('id');
        });

        Schema::table('beds', function (Blueprint $table) {
            $table->unsignedInteger('number')->nullable()->after('room_id');
        });

        $this->backfill();

        Schema::table('rooms', function (Blueprint $table) {
            $table->unsignedInteger('number')->nullable(false)->change();
            $table->unique('number');
        });

        Schema::table('beds', function (Blueprint $table) {
            $table->unsignedInteger('number')->nullable(false)->change();
            $table->unique(['room_id', 'number']);
        });

        Schema::table('rooms', function (Blueprint $table) {
            $table->dropColumn('sort');
        });

        Schema::table('beds', function (Blueprint $table) {
            $table->dropColumn('sort');
        });
    }

    /** Возвращает sort, заполненный номером, и убирает номера. */
    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->unsignedInteger('sort')->default(0)->after('price');
        });

        Schema::table('beds', function (Blueprint $table) {
            $table->unsignedInteger('sort')->default(0)->after('name');
        });

        DB::table('rooms')->update(['sort' => DB::raw('number')]);
        DB::table('beds')->update(['sort' => DB::raw('number')]);

        Schema::table('beds', function (Blueprint $table) {
            // MySQL мог убрать свой индекс внешнего ключа room_id, опираясь на составной уникальный индекс.
            if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true) && ! Schema::hasIndex('beds', ['room_id'])) {
                $table->index('room_id', 'beds_room_id_foreign');
            }

            $table->dropUnique(['room_id', 'number']);
            $table->dropColumn('number');
        });

        Schema::table('rooms', function (Blueprint $table) {
            $table->dropUnique(['number']);
            $table->dropColumn('number');
        });
    }

    /** Нумерует комнаты по порядку sort, id, а койки — так же внутри комнаты; названия коек — «{комната}.{койка}.». */
    private function backfill(): void
    {
        $roomNumbers = [];

        foreach (DB::table('rooms')->orderBy('sort')->orderBy('id')->pluck('id') as $index => $roomId) {
            $roomNumbers[$roomId] = $index + 1;

            DB::table('rooms')->where('id', $roomId)->update(['number' => $index + 1]);
        }

        $bedNumbers = [];
        $beds = DB::table('beds')->orderBy('room_id')->orderBy('sort')->orderBy('id')->get(['id', 'room_id']);

        foreach ($beds as $bed) {
            $number = $bedNumbers[$bed->room_id] = ($bedNumbers[$bed->room_id] ?? 0) + 1;

            DB::table('beds')->where('id', $bed->id)->update([
                'number' => $number,
                'name' => "{$roomNumbers[$bed->room_id]}.{$number}.",
            ]);
        }
    }
};
