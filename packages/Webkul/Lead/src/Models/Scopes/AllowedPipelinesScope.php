<?php

namespace Webkul\Lead\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * What a user restricted to some pipelines (Configurações → Usuários → Acesso por funil) gets from the
 * deals, the pipelines and the stages: only the allowed ones. Everybody else, and any code running
 * without a logged user (webhooks, API keys, queues, console), is not affected.
 *
 * $column is the column holding the pipeline id on the model's table.
 */
class AllowedPipelinesScope implements Scope
{
    public function __construct(private readonly string $column) {}

    public function apply(Builder $builder, Model $model): void
    {
        $ids = bouncer()->getAllowedPipelineIds();

        if ($ids === null) {
            return;
        }

        // A restricted user with no pipeline sees nothing (0 is never an id).
        $builder->whereIn($model->getTable().'.'.$this->column, $ids ?: [0]);
    }
}
