<?php

namespace App\Actions\Stay;

use App\Models\Stay;

/** Удаление заселения. */
class DeleteStayAction
{
    /** Удаляет заселение; оплаты удаляются каскадом БД.
     * @param Stay $stay Заселение.
     * @return void
     */
    public function run(Stay $stay): void
    {
        $stay->delete();
    }
}
