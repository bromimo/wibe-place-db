<?php

namespace Tests\Feature\Stay;

use App\Models\Bed;
use Tests\TestCase;
use App\Models\Room;
use App\Models\Stay;
use App\Models\User;
use App\Dto\Stay\StayDto;
use App\Enums\StayStatus;
use App\Enums\StayTariff;
use Carbon\CarbonImmutable;
use App\Actions\Stay\CreateStayAction;
use App\Actions\Stay\UpdateStayAction;
use App\Exceptions\StayOverlapException;
use App\Exceptions\InvalidStayDataException;
use Illuminate\Foundation\Testing\RefreshDatabase;

/** Тесты создания и правки заселения. */
class CreateUpdateStayActionTest extends TestCase
{
    use RefreshDatabase;

    private Room $room;

    private Bed $bed;

    /** Создаёт комнату с ценой 460 и койку. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-24 12:00');
        $this->room = Room::factory()->create(['price' => 460]);
        $this->bed = Bed::factory()->create(['room_id' => $this->room->id]);
    }

    /** Собирает DTO заселения.
     * @param array<string, mixed> $overrides Переопределения полей.
     * @return StayDto
     */
    private function dto(array $overrides = []): StayDto
    {
        return StayDto::from(array_merge([
            'bed_id' => $this->bed->id,
            'user_id' => User::factory()->create()->id,
            'date_from' => '2026-11-24',
            'date_to' => '2026-11-27',
            'status' => 'living',
            'tariff' => 'standard',
            'manual_total' => null,
            'notes' => null,
        ], $overrides));
    }

    /** Создаёт заселение через действие.
     * @param array<string, mixed> $overrides Переопределения полей DTO.
     * @return Stay
     */
    private function create(array $overrides = []): Stay
    {
        return (new CreateStayAction())->run($this->dto($overrides));
    }

    /** DTO принимает строки дат и значения enum. */
    public function test_dto_casts_strings(): void
    {
        $dto = $this->dto();

        $this->assertInstanceOf(CarbonImmutable::class, $dto->date_from);
        $this->assertInstanceOf(CarbonImmutable::class, $dto->date_to);
        $this->assertSame('2026-11-24', $dto->date_from->toDateString());
        $this->assertSame(StayStatus::Living, $dto->status);
        $this->assertSame(StayTariff::Standard, $dto->tariff);
    }

    /** Стандартное заселение фиксирует цену комнаты. */
    public function test_create_standard_snapshots_room_price(): void
    {
        $stay = $this->create();

        $this->assertSame(460, $stay->price_per_night);
        $this->assertNull($stay->manual_total);
    }

    /** Смена цены комнаты не меняет существующее заселение. */
    public function test_room_price_change_does_not_touch_existing_stay(): void
    {
        $stay = $this->create();
        $this->room->update(['price' => 600]);

        (new UpdateStayAction())->run($stay, $this->dto([
            'user_id' => $stay->user_id,
            'notes' => 'заметка',
        ]));

        $this->assertSame(460, $stay->fresh()->price_per_night);
    }

    /** Ручной тариф хранит сумму без цены за ночь. */
    public function test_create_manual(): void
    {
        $stay = $this->create(['tariff' => 'manual', 'manual_total' => 2000]);

        $this->assertSame(2000, $stay->manual_total);
        $this->assertNull($stay->price_per_night);
    }

    /** Нулевая сумма допустима. */
    public function test_create_manual_zero_total_allowed(): void
    {
        $stay = $this->create(['tariff' => 'manual', 'manual_total' => 0]);

        $this->assertSame(0, $stay->fresh()->manual_total);
    }

    /** Ручной тариф без суммы отклоняется. */
    public function test_create_manual_without_total_rejected(): void
    {
        try {
            $this->create(['tariff' => 'manual']);
            $this->fail('Ожидалось InvalidStayDataException');
        } catch (InvalidStayDataException $e) {
            $this->assertSame('Для ручного тарифа укажите сумму', $e->getMessage());
            $this->assertSame(0, Stay::query()->count());
        }
    }

    /** Выезд раньше или в день заезда отклоняется. */
    public function test_create_rejects_inverted_and_equal_dates(): void
    {
        foreach (['2026-11-23', '2026-11-24'] as $to) {
            try {
                $this->create(['date_to' => $to]);
                $this->fail("Ожидалось исключение для date_to {$to}");
            } catch (InvalidStayDataException $e) {
                $this->assertSame('День выезда должен быть позже дня заезда', $e->getMessage());
            }
        }

        $this->assertSame(0, Stay::query()->count());
    }

    /** Пересечение на той же койке отклоняется. */
    public function test_create_overlap_rejected(): void
    {
        $this->create();

        try {
            $this->create(['date_from' => '2026-11-25', 'date_to' => '2026-11-28']);
            $this->fail('Ожидалось StayOverlapException');
        } catch (StayOverlapException) {
            $this->assertSame(1, Stay::query()->count());
        }
    }

    /** Заезд в день выезда соседа разрешён. */
    public function test_same_day_turnover_allowed(): void
    {
        $this->create();
        $this->create(['date_from' => '2026-11-27', 'date_to' => '2026-11-29']);

        $this->assertSame(2, Stay::query()->count());
    }

    /** Перенос в другую комнату берёт её цену. */
    public function test_update_to_other_room_takes_new_price(): void
    {
        $stay = $this->create();
        $otherRoom = Room::factory()->create(['price' => 600]);
        $otherBed = Bed::factory()->create(['room_id' => $otherRoom->id]);

        (new UpdateStayAction())->run($stay, $this->dto([
            'bed_id' => $otherBed->id,
            'user_id' => $stay->user_id,
        ]));

        $this->assertSame(600, $stay->fresh()->price_per_night);
    }

    /** Перенос на другую койку той же комнаты оставляет цену. */
    public function test_update_same_room_other_bed_keeps_price(): void
    {
        $stay = $this->create();
        $this->room->update(['price' => 600]);
        $otherBed = Bed::factory()->create(['room_id' => $this->room->id]);

        (new UpdateStayAction())->run($stay, $this->dto([
            'bed_id' => $otherBed->id,
            'user_id' => $stay->user_id,
        ]));

        $this->assertSame(460, $stay->fresh()->price_per_night);
    }

    /** Смена ручного тарифа на стандартный берёт цену комнаты. */
    public function test_update_manual_to_standard_takes_room_price(): void
    {
        $stay = $this->create(['tariff' => 'manual', 'manual_total' => 2000]);

        (new UpdateStayAction())->run($stay, $this->dto(['user_id' => $stay->user_id, 'manual_total' => 777]));

        $fresh = $stay->fresh();
        $this->assertSame(460, $fresh->price_per_night);
        $this->assertNull($fresh->manual_total);
    }

    /** Заселение не конфликтует с собственным прежним периодом. */
    public function test_update_does_not_conflict_with_itself(): void
    {
        $stay = $this->create();

        (new UpdateStayAction())->run($stay, $this->dto([
            'user_id' => $stay->user_id,
            'date_from' => '2026-11-25',
            'date_to' => '2026-11-28',
        ]));

        $this->assertSame('2026-11-25', $stay->fresh()->date_from->toDateString());
    }

    /** Конфликтная правка отклоняется, запись не меняется. */
    public function test_update_overlap_rejected_and_unchanged(): void
    {
        $this->create();
        $stay = $this->create(['date_from' => '2026-11-27', 'date_to' => '2026-11-29']);

        try {
            (new UpdateStayAction())->run($stay, $this->dto([
                'user_id' => $stay->user_id,
                'date_from' => '2026-11-26',
                'date_to' => '2026-11-29',
            ]));
            $this->fail('Ожидалось StayOverlapException');
        } catch (StayOverlapException) {
            $this->assertSame('2026-11-27', $stay->fresh()->date_from->toDateString());
        }
    }

    /** Два одинаковых создания подряд: второе отклоняется. */
    public function test_second_identical_create_rejected(): void
    {
        $dto = $this->dto();
        $action = new CreateStayAction();
        $action->run($dto);

        $this->expectException(StayOverlapException::class);

        $action->run($dto);
    }

    /** Правка с некорректными датами или ручным тарифом без суммы отклоняется, запись не меняется. */
    public function test_update_rejects_invalid_data_and_keeps_record(): void
    {
        $stay = $this->create();
        $cases = [
            ['date_to' => '2026-11-24'],
            ['date_to' => '2026-11-23'],
            ['tariff' => 'manual', 'manual_total' => null],
        ];

        foreach ($cases as $overrides) {
            try {
                (new UpdateStayAction())->run($stay, $this->dto(['user_id' => $stay->user_id] + $overrides));
                $this->fail('Ожидалось InvalidStayDataException');
            } catch (InvalidStayDataException) {
                $fresh = $stay->fresh();
                $this->assertSame('2026-11-27', $fresh->date_to->toDateString());
                $this->assertSame(StayTariff::Standard, $fresh->tariff);
                $this->assertSame(460, $fresh->price_per_night);
            }
        }
    }

    /** Стандартный тариф с переданной ручной суммой хранит null. */
    public function test_create_standard_ignores_supplied_manual_total(): void
    {
        $stay = $this->create(['manual_total' => 999]);

        $this->assertNull($stay->fresh()->manual_total);
    }

    /** Смена стандартного тарифа на ручной обнуляет цену за ночь. */
    public function test_update_standard_to_manual_clears_price(): void
    {
        $stay = $this->create();

        (new UpdateStayAction())->run($stay, $this->dto([
            'user_id' => $stay->user_id,
            'tariff' => 'manual',
            'manual_total' => 1500,
        ]));

        $fresh = $stay->fresh();
        $this->assertNull($fresh->price_per_night);
        $this->assertSame(1500, $fresh->manual_total);
    }
}
