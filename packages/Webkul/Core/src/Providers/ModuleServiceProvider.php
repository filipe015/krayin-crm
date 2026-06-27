<?php

namespace Webkul\Core\Providers;

use Webkul\Core\Models\CoreConfig;
use Webkul\Core\Models\Country;
use Webkul\Core\Models\CountryState;
use Webkul\Core\Models\KanbanCardPreference;

class ModuleServiceProvider extends BaseModuleServiceProvider
{
    protected $models = [
        CoreConfig::class,
        Country::class,
        CountryState::class,
        KanbanCardPreference::class,
    ];
}
