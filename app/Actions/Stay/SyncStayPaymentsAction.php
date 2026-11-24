<?php

namespace App\Actions\Stay;

use App\Models\Stay;
use Illuminate\Support\Facades\DB;
use App\Exceptions\InvalidStayDataException;

/** Синхронизация оплат заселения со списком строк формы. */
class SyncStayPaymentsAction
{
    /** Обновляет строки с id, создаёт без id, удаляет не вошедшие; чужие id игнорирует.
     * @param Stay $stay Заселение.
     * @param array<int, array{id: int|null, amount: int, paid_at: string, note: string|null}> $rows Строки оплат.
     * @throws InvalidStayDataException
     * @return void
     */
    public function run(Stay $stay, array $rows): void
    {
        foreach ($rows as $row) {
            if ((int) $row['amount'] <= 0) {
                throw new InvalidStayDataException('Сумма оплаты должна быть больше нуля');
            }
        }

        DB::transaction(function () use ($stay, $rows): void {
            $own = $stay->payments()->get()->keyBy('id');
            $keptIds = [];

            foreach ($rows as $row) {
                $attributes = [
                    'amount' => (int) $row['amount'],
                    'paid_at' => $row['paid_at'],
                    'note' => $row['note'] ?? null,
                ];
                $payment = isset($row['id']) ? $own->get($row['id']) : null;

                if ($payment !== null) {
                    $payment->update($attributes);
                    $keptIds[] = $payment->id;
                } elseif (! isset($row['id'])) {
                    $keptIds[] = $stay->payments()->create($attributes)->id;
                }
            }

            $stay->payments()->whereNotIn('id', $keptIds)->delete();
        });
    }
}
