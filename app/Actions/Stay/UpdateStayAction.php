<?php

namespace App\Actions\Stay;

use App\Models\Bed;
use App\Models\Stay;
use App\Dto\Stay\StayDto;
use Illuminate\Support\Facades\DB;
use App\Exceptions\StayOverlapException;
use App\Exceptions\InvalidStayDataException;
use App\Http\Queries\Stay\FindOverlappingStayQuery;

/** Правка заселения. */
class UpdateStayAction
{
    /** Обновляет заселение; цену за ночь пересчитывает при смене комнаты или с ручного тарифа.
     * @param Stay $stay Правимое заселение.
     * @param StayDto $dto Новые данные.
     * @throws InvalidStayDataException
     * @throws StayOverlapException
     * @return Stay
     */
    public function run(Stay $stay, StayDto $dto): Stay
    {
        $dto->assertValid();

        return DB::transaction(function () use ($stay, $dto): Stay {
            $bed = Bed::query()->lockForUpdate()->findOrFail($dto->bed_id);

            $conflict = (new FindOverlappingStayQuery())($bed->id, $dto->date_from, $dto->date_to, $stay->id);

            if ($conflict !== null) {
                throw new StayOverlapException($conflict);
            }

            $price = (new ResolveStayPriceAction())->run($dto->tariff, $bed, $stay, $dto->manual_total);
            $attributes = array_merge($dto->toUpdateArray(), $price);

            $stay->update($attributes);

            return $stay->refresh();
        });
    }
}
