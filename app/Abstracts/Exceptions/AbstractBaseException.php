<?php

namespace App\Abstracts\Exceptions;

use Exception;
use Throwable;
use Illuminate\Contracts\Support\Arrayable;

/**
 * @implements Arrayable<string, string>
 */
abstract class AbstractBaseException extends Exception implements Arrayable
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        protected string     $langKey,
        protected array      $data = [],
        int|string           $code = 400,
        ?Throwable $previous = null
    )
    {
        parent::__construct($langKey, (int)$code, $previous);
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        if (__($this->langKey) === $this->langKey) {
            if (env('APP_DEBUG')) {
                $this->langKey .= ' in ' . $this->getPrevious()->getFile()
                    . ' on line ' . $this->getPrevious()->getLine();
            } else {
                $this->langKey = 'exceptions.something_went_wrong';
            }
        }

        return [
            'key'  => $this->langKey,
            'text' => __($this->langKey),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * @param array<string, mixed> $newData
     */
    public function mergeData(array $newData = []): static
    {
        $this->data = array_merge($this->data, $newData);

        return $this;
    }

    public function hasData(): bool
    {
        return !empty($this->data);
    }
}
