<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /** Переводит таблицу users на InnoDB: MyISAM не может быть целью внешнего ключа stays.user_id. */
    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        $table = DB::selectOne(
            'select engine as engine from information_schema.tables where table_schema = database() and table_name = ?',
            ['users'],
        );

        if ($table !== null && strcasecmp((string) $table->engine, 'InnoDB') !== 0) {
            DB::statement('alter table `users` engine = InnoDB');
        }
    }

    /** Обратно не переводится: на InnoDB уже могут ссылаться внешние ключи. */
    public function down(): void
    {
        //
    }
};
