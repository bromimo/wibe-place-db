<?php

namespace App\Actions\Stay;

use App\Models\Bed;
use App\Models\Stay;
use App\Dto\Stay\StayDto;
use App\Exceptions\StayOverlapException;
use App\Exceptions\InvalidStayDataException;

/** Создание заселения. */
class CreateStayAction
{
    /** Создаёт заселение, фиксируя цену за ночь; проверяет фактический интервал на койке.
     * @param StayDto $dto Данные заселения.
     * @throws InvalidStayDataException
     * @throws StayOverlapException
     * @return Stay
     */
    public function run(StayDto $dto): Stay
    {
        $dto->assertValid();

        $guard = new GuardStayChangeAction();

        return $guard->run(null, $dto->bed_id, function (Bed $bed) use ($guard, $dto): Stay {
            $guard->assertBedFree($bed, $dto->date_from, $dto->date_to, $dto->status);

            $price = (new ResolveStayPriceAction())->run($dto->tariff, $bed, null, $dto->manual_total);
            $attributes = array_merge($dto->toCreateArray(), $price);

            return Stay::query()->create($attributes)->refresh();
        });
    }
}
