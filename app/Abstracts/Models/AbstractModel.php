<?php

namespace App\Abstracts\Models;

use App\Abstracts\Dto\AbstractDto;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * @method static static create(array $attributes = [])
 */


abstract class AbstractModel extends Model
{
    use HasFactory;

    /** Создает модель в БД, используя данные DTO.
     * @param AbstractDto $dto
     * @return static
     */
    public static function createFromDto(AbstractDto $dto): static
    {
        /** @var static */
        return static::query()->create($dto->toCreateArray())->refresh();
    }

    /** Обновляет модель в БД, используя данные DTO.
     * @param AbstractDto $dto
     * @return $this
     */
    public function updateFromDto(AbstractDto $dto): static
    {
        $this->update($dto->toUpdateArray());

        return $this;
    }

    /** Обновляет или создает новую модель в БД, используя данные DTO.
     * @param AbstractDto $dto
     * @return static
     */
    public static function updateOrCreateFromDto(AbstractDto $dto): static
    {
        /** @var static */
        return static::query()->updateOrCreate(['id' => $dto->id], $dto->toUpdateArray());
    }
}
