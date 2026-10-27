<?php

namespace App\Authorization;

use Illuminate\Support\Facades\Gate;

/**
 * Shared Livewire authorization for master-data write actions.
 */
trait AuthorizesMasterDataWrites
{
    protected function authorizeMasterDataManage(): void
    {
        Gate::authorize(ApplicationCapability::MasterdataManage->value);
    }
}
