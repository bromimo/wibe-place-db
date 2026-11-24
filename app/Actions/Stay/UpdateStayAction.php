<?php

namespace App\Actions\Stay;

use App\Models\Bed;
use App\Models\Stay;
use App\Dto\Stay\StayDto;
use App\Enums\StayTariff;
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
        if ($dto->date_to->lte($dto->date_from)) {
            throw new InvalidStayDataException('День выезда должен быть позже дня заезда');
        }

        $isManual = $dto->tariff === StayTariff::Manual;

        if ($isManual && $dto->manual_total === null) {
            throw new InvalidStayDataException('Для ручного тарифа укажите сумму');
        }

        return DB::transaction(function () use ($stay, $dto, $isManual): Stay {
            $bed = Bed::query()->lockForUpdate()->findOrFail($dto->bed_id);

            $conflict = (new FindOverlappingStayQuery())($bed->id, $dto->date_from, $dto->date_to, $stay->id);

            if ($conflict !== null) {
                throw new StayOverlapException($conflict);
            }

            $roomChanged = $stay->bed->room_id !== $bed->room_id;
            $wasManual = $stay->tariff === StayTariff::Manual;

            $attributes = $dto->toUpdateArray();
            $attributes['manual_total'] = $isManual ? $dto->manual_total : null;

            if ($isManual) {
                $attributes['price_per_night'] = null;
            } elseif ($roomChanged || $wasManual) {
                $attributes['price_per_night'] = $bed->room->price;
            }

            $stay->update($attributes);

            return $stay->refresh();
        });
    }
}
