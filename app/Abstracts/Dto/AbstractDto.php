<?php

namespace App\Abstracts\Dto;

use Spatie\LaravelData\Data;

abstract class AbstractDto extends Data
{
    /** Преобразует DTO в массив.
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        /** @var array<string, mixed> */
        return parent::toArray();
    }

    /** Преобразует DTO в массив для создания модели.
     * @return array<string, mixed>
     */
    public function toCreateArray(): array
    {
        return $this->toArray();
    }

    /** Преобразует DTO в массив для обновления модели.
     * @return array<string, mixed>
     */
    public function toUpdateArray(): array
    {
        return $this->toArray();
    }
}
