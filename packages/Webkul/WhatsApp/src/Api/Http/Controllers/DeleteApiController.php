<?php

namespace Webkul\WhatsApp\Api\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Webkul\Lead\Models\Lead;
use Webkul\Lead\Repositories\LeadRepository;
use Webkul\Lead\Repositories\PipelineRepository;

/**
 * Deletions. They need the key's own "delete" ability, and anything still in use is refused (409)
 * instead of being removed together with what depends on it.
 */
class DeleteApiController extends ApiController
{
    public function __construct(protected LeadRepository $leads) {}

    public function lead(int $id): JsonResponse
    {
        if (! Lead::query()->whereKey($id)->exists()) {
            return $this->fail('not_found', 'Negócio não encontrado.', 404);
        }

        $this->removeLead($id);

        return $this->ok(['id' => $id, 'deleted' => true]);
    }

    /**
     * What was imported from a source (e.g. "trello"), by its external ids.
     */
    public function importSummary(string $system): JsonResponse
    {
        $ids = $this->importedLeadIds($system);

        return $this->ok([
            'source'  => $system,
            'leads'   => count($ids),
            'sample'  => DB::table('leads')->whereIn('id', array_slice($ids, 0, 5))->pluck('title'),
        ]);
    }

    /**
     * Deletes every lead imported from the source (with its notes and activities) and, optionally,
     * the people and companies the import created. Without confirm=yes it only counts.
     */
    public function purgeImport(Request $request, string $system): JsonResponse
    {
        $data = $request->validate([
            'confirm' => ['nullable', 'in:yes'],
            'persons' => ['nullable', 'boolean'],
        ]);

        $ids = $this->importedLeadIds($system);
        $confirmed = ($data['confirm'] ?? null) === 'yes';

        $firstImport = DB::table('api_external_refs')->where('source', $system)->where('entity_type', 'lead')->min('created_at');

        // People and companies created while importing (not older ones that were only reused).
        $personIds = ($request->boolean('persons', true) && $firstImport)
            ? DB::table('leads')->whereIn('id', $ids)->pluck('person_id')->filter()->unique()->values()->all()
            : [];

        $threshold = $firstImport ? Carbon::parse($firstImport)->subMinutes(15) : null;

        if (! $confirmed) {
            return $this->ok(['dry_run' => true, 'leads' => count($ids), 'people_candidates' => count($personIds)]);
        }

        foreach ($ids as $id) {
            $this->removeLead($id);
        }

        DB::table('api_external_refs')->where('source', $system)->where('entity_type', 'lead')->delete();

        $peopleDeleted = 0;
        $companiesDeleted = 0;

        if ($personIds && $threshold) {
            $removable = DB::table('persons')
                ->whereIn('id', $personIds)
                ->where('created_at', '>=', $threshold)
                ->whereNotIn('id', DB::table('leads')->whereNotNull('person_id')->select('person_id'))
                ->get(['id', 'organization_id']);

            $orgIds = $removable->pluck('organization_id')->filter()->unique()->values()->all();
            $removableIds = $removable->pluck('id')->all();

            DB::table('attribute_values')->where('entity_type', 'persons')->whereIn('entity_id', $removableIds)->delete();
            $peopleDeleted = DB::table('persons')->whereIn('id', $removableIds)->delete();

            if ($orgIds) {
                $companiesDeleted = DB::table('organizations')
                    ->whereIn('id', $orgIds)
                    ->where('created_at', '>=', $threshold)
                    ->whereNotIn('id', DB::table('persons')->whereNotNull('organization_id')->select('organization_id'))
                    ->delete();
            }
        }

        return $this->ok(['dry_run' => false, 'leads_deleted' => count($ids), 'people_deleted' => $peopleDeleted, 'companies_deleted' => $companiesDeleted]);
    }

    /**
     * People with no deal (and no quote) and companies with no people, created since `since`.
     * Without confirm=yes it only lists what would go.
     */
    public function orphanContacts(Request $request): JsonResponse
    {
        $data = $request->validate([
            'since'   => ['required', 'date'],
            'confirm' => ['nullable', 'in:yes'],
        ]);

        $since = Carbon::parse($data['since']);

        $people = DB::table('persons')
            ->where('created_at', '>=', $since)
            ->whereNotIn('id', DB::table('leads')->whereNotNull('person_id')->select('person_id'))
            ->whereNotIn('id', DB::table('quotes')->whereNotNull('person_id')->select('person_id'))
            ->get(['id', 'name', 'organization_id']);

        $peopleIds = $people->pluck('id')->all();

        // Companies that are empty now, or will be once these people are gone.
        $companies = DB::table('organizations')
            ->where('created_at', '>=', $since)
            ->whereNotIn('id', DB::table('persons')->whereNotNull('organization_id')->whereNotIn('id', $peopleIds)->select('organization_id'))
            ->get(['id', 'name']);

        if (($data['confirm'] ?? null) !== 'yes') {
            return $this->ok([
                'dry_run'          => true,
                'people'           => $people->count(),
                'companies'        => $companies->count(),
                'people_sample'    => $people->take(10)->pluck('name'),
                'companies_sample' => $companies->take(10)->pluck('name'),
            ]);
        }

        DB::table('attribute_values')->where('entity_type', 'persons')->whereIn('entity_id', $peopleIds)->delete();
        $peopleDeleted = DB::table('persons')->whereIn('id', $peopleIds)->delete();
        $companiesDeleted = DB::table('organizations')->whereIn('id', $companies->pluck('id')->all())->delete();

        return $this->ok(['dry_run' => false, 'people_deleted' => $peopleDeleted, 'companies_deleted' => $companiesDeleted]);
    }

    public function pipeline(int $id, PipelineRepository $pipelines): JsonResponse
    {
        $pipeline = DB::table('lead_pipelines')->where('id', $id)->first(['id', 'name', 'is_default']);

        if (! $pipeline) {
            return $this->fail('not_found', 'Funil não encontrado.', 404);
        }

        if ($pipeline->is_default) {
            return $this->fail('default_pipeline', 'O funil padrão não pode ser excluído.', 422);
        }

        $leads = DB::table('leads')->where('lead_pipeline_id', $id)->count();

        if ($leads > 0) {
            // The admin screen would silently move these leads to the default funnel; here we refuse.
            return $this->fail('in_use', "O funil ainda tem {$leads} negócio(s). Exclua ou mova os negócios antes.", 409, ['leads' => $leads]);
        }

        Event::dispatch('settings.pipeline.delete.before', $id);
        $pipelines->delete($id);
        Event::dispatch('settings.pipeline.delete.after', $id);

        return $this->ok(['id' => $id, 'deleted' => true]);
    }

    public function attribute(Request $request, string $code): JsonResponse
    {
        $data = $request->validate([
            'entity' => ['required', 'in:leads,persons'],
            'force'  => ['nullable', 'boolean'],
        ]);

        $attribute = DB::table('attributes')->where('entity_type', $data['entity'])->where('code', $code)->first(['id', 'code', 'name', 'is_user_defined']);

        if (! $attribute) {
            return $this->fail('not_found', 'Campo não encontrado.', 404);
        }

        if (! $attribute->is_user_defined) {
            return $this->fail('system_field', 'Campos do sistema não podem ser excluídos.', 422);
        }

        $values = DB::table('attribute_values')->where('attribute_id', $attribute->id)->count();

        if ($values > 0 && ! $request->boolean('force')) {
            return $this->fail('in_use', "O campo \"{$attribute->name}\" tem valor em {$values} registro(s). Use force=1 para excluir mesmo assim.", 409, ['values' => $values]);
        }

        DB::table('attribute_values')->where('attribute_id', $attribute->id)->delete();
        DB::table('attribute_options')->where('attribute_id', $attribute->id)->delete();
        DB::table('attributes')->where('id', $attribute->id)->delete();

        return $this->ok(['code' => $code, 'deleted' => true, 'values_removed' => $values]);
    }

    public function tag(int $id): JsonResponse
    {
        if (! DB::table('tags')->where('id', $id)->exists()) {
            return $this->fail('not_found', 'Etiqueta não encontrada.', 404);
        }

        $used = DB::table('lead_tags')->where('tag_id', $id)->count();

        if ($used > 0) {
            return $this->fail('in_use', "A etiqueta está em {$used} negócio(s).", 409, ['leads' => $used]);
        }

        DB::table('tags')->where('id', $id)->delete();

        return $this->ok(['id' => $id, 'deleted' => true]);
    }

    public function source(int $id): JsonResponse
    {
        if (! DB::table('lead_sources')->where('id', $id)->exists()) {
            return $this->fail('not_found', 'Origem não encontrada.', 404);
        }

        $used = DB::table('leads')->where('lead_source_id', $id)->count();

        if ($used > 0) {
            return $this->fail('in_use', "A origem está em {$used} negócio(s).", 409, ['leads' => $used]);
        }

        DB::table('lead_sources')->where('id', $id)->delete();

        return $this->ok(['id' => $id, 'deleted' => true]);
    }

    /**
     * @return list<int>
     */
    private function importedLeadIds(string $source): array
    {
        return DB::table('api_external_refs')
            ->join('leads', 'leads.id', '=', 'api_external_refs.entity_id')
            ->where('api_external_refs.source', $source)
            ->where('api_external_refs.entity_type', 'lead')
            ->pluck('leads.id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function removeLead(int $id): void
    {
        Event::dispatch('lead.delete.before', $id);

        $this->leads->delete($id);

        // Krayin leaves the custom-field values and tags of a deleted lead behind; a field cannot be
        // seen as "unused" while they are there.
        DB::table('attribute_values')->where('entity_type', 'leads')->where('entity_id', $id)->delete();
        DB::table('lead_tags')->where('lead_id', $id)->delete();

        Event::dispatch('lead.delete.after', $id);
    }
}
