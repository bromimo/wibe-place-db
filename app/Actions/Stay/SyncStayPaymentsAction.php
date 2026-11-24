<?php

namespace App\Actions\Stay;

use App\Models\Stay;
use Illuminate\Support\Facades\DB;
use App\Exceptions\InvalidStayDataException;

/** Синхронизация оплат заселения со списком строк формы. */
class SyncStayPaymentsAction
{
    /** Обновляет строки с id, создаёт без id, удаляет оплаты, которые были в форме при открытии и убраны из неё.
     * Оплаты, которых форма не видела (внесены другим администратором), и чужие id не затрагиваются.
     * @param Stay $stay Заселение.
     * @param array<int, array{id: int|null, amount: int, paid_at: string, note: string|null}> $rows Строки оплат.
     * @param array<int, int> $openedIds Id оплат, бывших в форме при её открытии.
     * @throws InvalidStayDataException
     * @return void
     */
    public function run(Stay $stay, array $rows, array $openedIds): void
    {
        foreach ($rows as $row) {
            if ((int) $row['amount'] <= 0) {
                throw new InvalidStayDataException('Сумма оплаты должна быть больше нуля');
            }
        }

        DB::transaction(function () use ($stay, $rows, $openedIds): void {
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

            $removedIds = array_values(array_diff(array_map('intval', $openedIds), $keptIds));

            if ($removedIds !== []) {
                $stay->payments()->whereIn('id', $removedIds)->delete();
            }
        });
    }
}
