<?php

namespace App\Actions\Stay;

use App\Models\Bed;
use App\Models\Stay;
use App\Exceptions\StayChangedException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/** Удаление заселения. */
class DeleteStayAction
{
    /** Удаляет заселение под блокировкой его койки; оплаты удаляются каскадом БД.
     * @param Stay $stay Заселение (может быть устаревшей копией).
     * @throws StayChangedException
     * @throws ModelNotFoundException
     * @return void
     */
    public function run(Stay $stay): void
    {
        (new GuardStayChangeAction())->run($stay, null, function (Bed $bed, Stay $fresh): void {
            $fresh->delete();
        });
    }
}
