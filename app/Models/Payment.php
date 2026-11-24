<?php

namespace App\Models;

use App\Abstracts\Models\AbstractModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Оплата заселения. */
class Payment extends AbstractModel
{
    protected $fillable = [
        'stay_id',
        'amount',
        'paid_at',
        'note',
    ];

    /** Приведение типов атрибутов.
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'paid_at' => 'immutable_date',
        ];
    }

    /** Заселение, за которое внесена оплата.
     * @return BelongsTo<Stay, $this>
     */
    public function stay(): BelongsTo
    {
        return $this->belongsTo(Stay::class);
    }
}
