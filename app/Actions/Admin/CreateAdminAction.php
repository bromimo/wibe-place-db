<?php

namespace App\Actions\Admin;

use App\Models\Admin;
use App\Dto\Admin\CreateAdminDto;
use App\Exceptions\AdminCreateException;
use App\Http\Queries\Admin\GetAdminQuery;

class CreateAdminAction
{
    public function run(CreateAdminDto $dto): Admin
    {
        $getAdminQuery = new GetAdminQuery();
        throw_if($getAdminQuery($dto->email), AdminCreateException::class);
        $admin = Admin::createFromDto($dto);

        return $admin;
    }
}
