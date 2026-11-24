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

/** Создание заселения. */
class CreateStayAction
{
    /** Создаёт заселение, фиксируя цену за ночь.
     * @param StayDto $dto Данные заселения.
     * @throws InvalidStayDataException
     * @throws StayOverlapException
     * @return Stay
     */
    public function run(StayDto $dto): Stay
    {
        if ($dto->date_to->lte($dto->date_from)) {
            throw new InvalidStayDataException('День выезда должен быть позже дня заезда');
        }

        $isManual = $dto->tariff === StayTariff::Manual;

        if ($isManual && $dto->manual_total === null) {
            throw new InvalidStayDataException('Для ручного тарифа укажите сумму');
        }

        return DB::transaction(function () use ($dto, $isManual): Stay {
            $bed = Bed::query()->lockForUpdate()->findOrFail($dto->bed_id);

            $conflict = (new FindOverlappingStayQuery())($bed->id, $dto->date_from, $dto->date_to);

            if ($conflict !== null) {
                throw new StayOverlapException($conflict);
            }

            $attributes = $dto->toCreateArray();
            $attributes['manual_total'] = $isManual ? $dto->manual_total : null;
            $attributes['price_per_night'] = $isManual ? null : $bed->room->price;

            return Stay::query()->create($attributes)->refresh();
        });
    }
}
