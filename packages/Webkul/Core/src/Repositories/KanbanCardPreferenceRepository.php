<?php

namespace Webkul\Core\Repositories;

use Webkul\Core\Contracts\KanbanCardPreference;
use Webkul\Core\Eloquent\Repository;

class KanbanCardPreferenceRepository extends Repository
{
    /**
     * Specify model class name.
     */
    public function model(): string
    {
        return KanbanCardPreference::class;
    }
}
