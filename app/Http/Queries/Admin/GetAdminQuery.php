<?php

namespace App\Http\Queries\Admin;

use App\Models\Admin;
use Spatie\QueryBuilder\QueryBuilder;

class GetAdminQuery
{
    /**
     * @param string $email
     * @return Admin|null
     */
    public function __invoke(string $email): ?Admin
    {
        /** @var Admin */
        return QueryBuilder::for(Admin::class)
                           ->where('email', $email)
                           ->first();
    }
}
