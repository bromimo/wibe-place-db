<?php

namespace App\Dto\Admin;

use Illuminate\Support\Arr;
use App\Abstracts\Dto\AbstractDto;

class CreateAdminDto extends AbstractDto
{
    public function __construct(
        public string $last_name,
        public string $first_name,
        public string $email,
        public string $password,
    )
    {
    }

    public function toCreateArray(): array
    {
        $arr = $this->toArray();

        Arr::set($arr, 'password', make_hash($this->password));

        return $arr;
    }
}
