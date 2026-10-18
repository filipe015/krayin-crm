<?php

namespace Webkul\Admin\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Filtering deals by their custom fields, for the kanban and the list alike.
 *
 * Each custom field becomes a filter column "cf_<attribute id>" whose value is read straight from
 * attribute_values by a scalar sub-query, so the existing filter machinery can use it like any column.
 */
class LeadCustomFieldFilters
{
    /**
     * Custom field types that can be filtered, and the attribute_values column each one is stored in.
     */
    protected const STORED_IN = [
        'text'     => 'text_value',
        'textarea' => 'text_value',
        'email'    => 'json_value',
        'phone'    => 'json_value',
        'select'   => 'integer_value',
        'boolean'  => 'boolean_value',
        'price'    => 'float_value',
        'date'     => 'date_value',
        'datetime' => 'datetime_value',
    ];

    /**
     * A text field with at most this many distinct values is filtered by picking one of them
     * (no typos); one with more keeps the free text box.
     */
    public const OPTIONS_LIMIT = 100;

    /** @var array<int, int>|null attribute id => distinct values, per request */
    private static ?array $distinct = null;

    public static function index(object $attribute): string
    {
        return 'cf_'.$attribute->id;
    }

    /**
     * The deal custom fields people can filter by.
     */
    public static function attributes(): Collection
    {
        return DB::table('attributes')
            ->where('entity_type', 'leads')
            ->where('is_user_defined', 1)
            ->whereIn('type', array_keys(self::STORED_IN))
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'type']);
    }

    /**
     * Whether the field is filtered by choosing among its existing values.
     */
    public static function usesOptions(object $attribute): bool
    {
        if ($attribute->type !== 'text') {
            return false;
        }

        self::$distinct ??= DB::table('attribute_values')
            ->where('entity_type', 'leads')
            ->whereNotNull('text_value')
            ->where('text_value', '!=', '')
            ->groupBy('attribute_id')
            ->selectRaw('attribute_id, count(distinct text_value) as total')
            ->pluck('total', 'attribute_id')
            ->all();

        $total = (int) (self::$distinct[$attribute->id] ?? 0);

        return $total > 0 && $total <= self::OPTIONS_LIMIT;
    }

    /**
     * The existing values of a text field, most used first, for the filter drawer.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function values(string $index): array
    {
        $attribute = self::attributes()->first(fn ($attribute) => self::index($attribute) === $index);

        if (! $attribute || ! self::usesOptions($attribute)) {
            return [];
        }

        return DB::table('attribute_values')
            ->where('entity_type', 'leads')
            ->where('attribute_id', $attribute->id)
            ->whereNotNull('text_value')
            ->where('text_value', '!=', '')
            ->groupBy('text_value')
            ->orderByRaw('count(*) desc')
            ->orderBy('text_value')
            ->selectRaw('text_value, count(*) as total')
            ->get()
            ->map(fn ($row) => ['value' => $row->text_value, 'label' => $row->text_value.' ('.$row->total.')'])
            ->all();
    }

    /**
     * SQL that reads the field's value for the deal in the outer query (a "leads" table row).
     */
    public static function expression(object $attribute): string
    {
        $prefix = DB::getTablePrefix();
        $column = self::STORED_IN[$attribute->type];
        $id = (int) $attribute->id;

        $value = "(select av.{$column} from {$prefix}attribute_values as av where av.entity_type = 'leads' and av.entity_id = {$prefix}leads.id and av.attribute_id = {$id} limit 1)";

        // A date compares as midnight; the filter's "from" is 00:00:01, so nudge it by a second.
        return $attribute->type === 'date' ? "DATE_ADD({$value}, INTERVAL 1 SECOND)" : $value;
    }

    /**
     * The column definition the filter drawers (and the data grid) understand.
     */
    public static function column(object $attribute): array
    {
        $base = [
            'index'                => self::index($attribute),
            'label'                => $attribute->name,
            'searchable'           => false,
            'filterable'           => true,
            'filterable_options'   => [],
            'allow_multiple_values' => true,
            'sortable'             => false,
            'visibility'           => false,
            'custom_field'         => true,
            'attribute_id'         => (int) $attribute->id,
            'search_field'         => 'like',
        ];

        return match ($attribute->type) {
            'select' => array_merge($base, [
                'type'               => 'string',
                'filterable_type'    => 'dropdown',
                'search_field'       => 'in',
                'filterable_options' => DB::table('attribute_options')
                    ->where('attribute_id', $attribute->id)
                    ->orderBy('sort_order')
                    ->get(['name as label', 'id as value'])
                    ->map(fn ($option) => (array) $option)
                    ->all(),
            ]),

            'boolean' => array_merge($base, [
                'type'                  => 'boolean',
                'filterable_type'       => 'dropdown',
                'allow_multiple_values' => false,
                'search_field'          => 'in',
            ]),

            'price' => array_merge($base, ['type' => 'decimal', 'filterable_type' => null, 'search_field' => 'in']),

            'date' => array_merge($base, [
                'type'                  => 'date',
                'filterable_type'       => 'date_range',
                'allow_multiple_values' => false,
                'search_field'          => 'between',
            ]),

            'datetime' => array_merge($base, [
                'type'                  => 'datetime',
                'filterable_type'       => 'datetime_range',
                'allow_multiple_values' => false,
                'search_field'          => 'between',
            ]),

            // Options are fetched when the field is picked (see values()); until then the list is empty.
            default => self::usesOptions($attribute)
                ? array_merge($base, ['type' => 'string', 'filterable_type' => 'dropdown', 'search_field' => 'in'])
                : array_merge($base, ['type' => 'string', 'filterable_type' => null]),
        };
    }

    /**
     * Columns for every filterable custom field.
     */
    public static function columns(): array
    {
        return self::attributes()->map(fn ($attribute) => self::column($attribute))->all();
    }

    /**
     * Narrows a deals query by the "cf" request value: a JSON list of { index, value } as the
     * kanban filter drawer holds them. Unknown fields are ignored.
     */
    public static function apply($query, mixed $raw): void
    {
        $filters = is_string($raw) ? json_decode($raw, true) : $raw;

        if (! is_array($filters)) {
            return;
        }

        $attributes = self::attributes()->keyBy(fn ($attribute) => self::index($attribute));

        foreach ($filters as $filter) {
            $attribute = $attributes[$filter['index'] ?? ''] ?? null;

            if (! $attribute || ! array_key_exists('value', $filter)) {
                continue;
            }

            [$sql, $bindings] = self::condition($attribute, $filter['value']);

            if ($sql) {
                $query->whereRaw($sql, $bindings);
            }
        }
    }

    /**
     * @return array{0: string|null, 1: array}
     */
    protected static function condition(object $attribute, mixed $value): array
    {
        $expression = self::expression($attribute);
        $values = array_values(array_filter((array) $value, fn ($item) => $item !== '' && $item !== null));

        if (! $values) {
            return [null, []];
        }

        switch ($attribute->type) {
            case 'select':
            case 'boolean':
            case 'price':
                return ["{$expression} in (".implode(',', array_fill(0, count($values), '?')).')', array_map(fn ($item) => is_array($item) ? 0 : $item, $values)];

            case 'date':
            case 'datetime':
                $parts = [];
                $bindings = [];

                foreach ($values as $range) {
                    $from = is_array($range) ? ($range[0] ?? '') : $range;
                    $to = is_array($range) ? ($range[1] ?? '') : $range;

                    if ($from !== '') {
                        $parts[] = "{$expression} >= ?";
                        $bindings[] = $from.' 00:00:01';
                    }

                    if ($to !== '') {
                        $parts[] = "{$expression} <= ?";
                        $bindings[] = $to.' 23:59:59';
                    }
                }

                return $parts ? ['('.implode(' and ', $parts).')', $bindings] : [null, []];

            default:
                // Picked from the existing values: exact match. Typed text: contains.
                if (self::usesOptions($attribute)) {
                    return ["{$expression} in (".implode(',', array_fill(0, count($values), '?')).')', array_map(fn ($item) => is_array($item) ? '' : $item, $values)];
                }

                return [
                    '('.implode(' or ', array_fill(0, count($values), "{$expression} like ?")).')',
                    array_map(fn ($item) => '%'.(is_array($item) ? '' : $item).'%', $values),
                ];
        }
    }
}
