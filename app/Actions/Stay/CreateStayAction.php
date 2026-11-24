<?php

namespace App\Actions\Stay;

use App\Models\Bed;
use App\Models\Stay;
use App\Dto\Stay\StayDto;
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
        $dto->assertValid();

        return DB::transaction(function () use ($dto): Stay {
            $bed = Bed::query()->lockForUpdate()->findOrFail($dto->bed_id);

            $conflict = (new FindOverlappingStayQuery())($bed->id, $dto->date_from, $dto->date_to);

            if ($conflict !== null) {
                throw new StayOverlapException($conflict);
            }

            $price = (new ResolveStayPriceAction())->run($dto->tariff, $bed, null, $dto->manual_total);
            $attributes = array_merge($dto->toCreateArray(), $price);

            return Stay::query()->create($attributes)->refresh();
        });
    }
}
