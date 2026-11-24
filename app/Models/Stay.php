<?php

namespace App\Models;

use App\Abstracts\Models\AbstractModel;
use App\Enums\StayStatus;
use App\Enums\StayTariff;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    /**
     * Приведение типов атрибутов.
     *
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

    /**
     * Койка заселения.
     *
     * @return BelongsTo<Bed, $this>
     */
    public function bed(): BelongsTo
    {
        return $this->belongsTo(Bed::class);
    }

    /**
     * Гость.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Оплаты заселения.
     *
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
