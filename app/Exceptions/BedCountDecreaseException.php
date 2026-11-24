<?php

namespace App\Exceptions;

use DomainException;

/** Число коек нельзя уменьшить: на удаляемой койке есть заселения. */
final class BedCountDecreaseException extends DomainException
{
    /** Создаёт исключение с текстом для уведомления.
     * @param string $bedName Название первой занятой удаляемой койки.
     */
    public function __construct(string $bedName)
    {
        parent::__construct("Нельзя уменьшить число коек: на койке {$bedName} есть заселения");
    }
}
