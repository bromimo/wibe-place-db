<?php

namespace App\Filament\Pages;

use BackedEnum;
use DateTimeImmutable;
use Filament\Pages\Page;
use Carbon\CarbonImmutable;
use Filament\Support\Enums\Width;
use Livewire\Attributes\Computed;
use Filament\Support\Icons\Heroicon;
use App\Http\Queries\Schedule\GetScheduleQuery;

/** График заселения: койки по дням с полосами заселений. */
class Schedule extends Page
{
    /** Число дней в окне графика. */
    private const WINDOW_DAYS = 31;

    protected static ?string $title = 'График';
    protected static ?string $navigationLabel = 'График';
    protected static ?int $navigationSort = -3;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;
    protected string $view = 'filament.pages.schedule';
    protected Width|string|null $maxContentWidth = Width::Full;

    /** Выбранная дата Y-m-d; окно начинается за день до неё. */
    public string $date = '';

    /** Ставит выбранной датой сегодняшний день. */
    public function mount(): void
    {
        $this->goToday();
    }

    /** Сбрасывает некорректную дату на сегодня. */
    public function updatedDate(): void
    {
        $this->date = $this->selectedDate()->toDateString();
    }

    /** Сдвигает выбранную дату.
     * @param int $days Число дней, может быть отрицательным.
     * @return void
     */
    public function shift(int $days): void
    {
        $this->date = $this->selectedDate()->addDays($days)->toDateString();
    }

    /** Возвращает выбранную дату на сегодня. */
    public function goToday(): void
    {
        $this->date = today()->toDateString();
    }

    /** Данные окна графика.
     * @return array<string, mixed>
     */
    #[Computed]
    public function schedule(): array
    {
        return app(GetScheduleQuery::class)($this->selectedDate()->subDay(), self::WINDOW_DAYS);
    }

    /** Выбранная дата; при некорректном значении — сегодня.
     * @return CarbonImmutable
     */
    private function selectedDate(): CarbonImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $this->date);

        if ($parsed === false || $parsed->format('Y-m-d') !== $this->date) {
            return today()->toImmutable();
        }

        return CarbonImmutable::parse($this->date);
    }
}
