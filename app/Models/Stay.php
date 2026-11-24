<?php

namespace App\Models;

use App\Enums\StayStatus;
use App\Enums\StayTariff;
use Carbon\CarbonImmutable;
use App\Abstracts\Models\AbstractModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Заселение гостя на койку. */
class Stay extends AbstractModel
{
    protected $fillable = [
        'bed_id',
        'user_id',
        'date_from',
        'date_to',
        'status',
        'tariff',
        'price_per_night',
        'manual_total',
        'notes',
    ];

    /** Приведение типов атрибутов.
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date_from' => 'immutable_date',
            'date_to' => 'immutable_date',
            'status' => StayStatus::class,
            'tariff' => StayTariff::class,
        ];
    }

    /** Койка заселения.
     * @return BelongsTo<Bed, $this>
     */
    public function bed(): BelongsTo
    {
        return $this->belongsTo(Bed::class);
    }

    /** Гость.
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Оплаты заселения.
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** Число ночей между датой заезда и датой выезда по календарным датам.
     * @return int
     */
    public function nights(): int
    {
        $from = CarbonImmutable::parse($this->date_from->toDateString(), 'UTC');
        $to = CarbonImmutable::parse($this->date_to->toDateString(), 'UTC');

        return (int) $from->diffInDays($to);
    }

    /** Стоимость проживания в гривнах.
     * @return int
     */
    public function cost(): int
    {
        if ($this->tariff === StayTariff::Manual) {
            return (int) $this->manual_total;
        }

        return $this->nights() * (int) $this->price_per_night;
    }

    /** Сумма оплат; использует предзагруженный payments_sum_amount, если он есть.
     * @return int
     */
    public function paid(): int
    {
        if (array_key_exists('payments_sum_amount', $this->attributes)) {
            return (int) $this->attributes['payments_sum_amount'];
        }

        return (int) $this->payments()->sum('amount');
    }

    /** Долг по заселению, не меньше нуля.
     * @return int
     */
    public function debt(): int
    {
        return max(0, $this->cost() - $this->paid());
    }

    /** Проживающий гость, у которого дата выезда уже прошла.
     * @return bool
     */
    public function isOverdue(): bool
    {
        return $this->effectiveDateTo()->gt($this->date_to);
    }

    /** Фактическая дата выезда: для просроченного заселения завтрашний день.
     * @return CarbonImmutable
     */
    public function effectiveDateTo(): CarbonImmutable
    {
        return self::effectiveEnd($this->status, $this->date_to);
    }

    /** Фактический конец интервала заселения: у проживающего с прошедшей датой выезда — завтрашний день.
     * @param StayStatus $status Состояние заселения.
     * @param CarbonImmutable $dateTo Сохранённая или сохраняемая дата выезда.
     * @return CarbonImmutable
     */
    public static function effectiveEnd(StayStatus $status, CarbonImmutable $dateTo): CarbonImmutable
    {
        if ($status === StayStatus::Living && $dateTo->lt(today())) {
            return today()->addDay()->toImmutable();
        }

        return $dateTo;
    }
}
