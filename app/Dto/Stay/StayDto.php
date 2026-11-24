<?php

namespace App\Dto\Stay;

use App\Enums\StayStatus;
use App\Enums\StayTariff;
use Carbon\CarbonImmutable;
use App\Abstracts\Dto\AbstractDto;
use Spatie\LaravelData\Attributes\WithCast;
use Spatie\LaravelData\Casts\DateTimeInterfaceCast;

/** Данные заселения из формы. */
class StayDto extends AbstractDto
{
    public function __construct(
        public int $bed_id,
        public int $user_id,
        #[WithCast(DateTimeInterfaceCast::class, format: ['!Y-m-d', DATE_ATOM])]
        public CarbonImmutable $date_from,
        #[WithCast(DateTimeInterfaceCast::class, format: ['!Y-m-d', DATE_ATOM])]
        public CarbonImmutable $date_to,
        public StayStatus $status,
        public StayTariff $tariff,
        public ?int $manual_total = null,
        public ?string $notes = null,
    ) {
    }

    /** Атрибуты модели: даты строками Y-m-d, enum объектами.
     * @return array<string, mixed>
     */
    public function toCreateArray(): array
    {
        return [
            'bed_id' => $this->bed_id,
            'user_id' => $this->user_id,
            'date_from' => $this->date_from->toDateString(),
            'date_to' => $this->date_to->toDateString(),
            'status' => $this->status,
            'tariff' => $this->tariff,
            'manual_total' => $this->manual_total,
            'notes' => $this->notes,
        ];
    }

    /** Атрибуты модели для правки.
     * @return array<string, mixed>
     */
    public function toUpdateArray(): array
    {
        return $this->toCreateArray();
    }
}
