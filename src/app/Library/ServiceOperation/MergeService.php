<?php

namespace Backpack\CRUD\app\Library\ServiceOperation;

use Backpack\CRUD\app\Library\CrudPanel\CrudPanel;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class MergeService
{
    public const STRATEGY_TRANSLATIONS = 'translations';
    public const STRATEGY_APPEND = 'append';
    public const STRATEGY_REPLACE = 'replace';

    protected CrudPanel $crud;

    protected ?Model $sourceEntry;

    /**
     * @var array<string, mixed>
     */
    protected array $definition = [];

    /**
     * @var array<string, array<string, mixed>>
     */
    protected array $fieldDefinitions = [];

    /**
     * @var array<string, array<string, mixed>>
     */
    protected array $relationDefinitions = [];

    public function __construct(CrudPanel $crud, ?Model $sourceEntry = null)
    {
        $this->crud = $crud;
        $this->sourceEntry = $sourceEntry;
        $this->definition = $this->resolveDefinition();
        $this->fieldDefinitions = $this->definition['fields'];
        $this->relationDefinitions = $this->definition['relations'];
    }

    public function getDefinition(): array
    {
        return $this->definition;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getFields(): array
    {
        return array_values($this->fieldDefinitions);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getRelations(): array
    {
        return array_values($this->relationDefinitions);
    }

    /**
     * @return array<string>
     */
    public function getFieldKeys(): array
    {
        return array_keys($this->fieldDefinitions);
    }

    /**
     * @return array<string>
     */
    public function getRelationDefaults(): array
    {
        return $this->definition['relation_defaults'] ?? [];
    }

    public function shouldDeleteSourceByDefault(): bool
    {
        return (bool) ($this->definition['delete_source_default'] ?? true);
    }

    public function getCandidateLabelFormat(): string
    {
        return $this->definition['candidate_label'] ?? '#%id% — %label%';
    }

    /**
     * Search candidates for select2 widget.
     */
    public function searchCandidates(?string $term, ?Model $source = null): array
    {
        $model = $this->crud->model;
        $builder = $model->newQuery();

        if ($source) {
            $builder->where($model->getKeyName(), '!=', $source->getKey());
        }

        $builder = $this->applyCandidateQuery($builder, $source);
        $term = trim((string) $term);

        if ($term !== '') {
            $builder->where(function (Builder $query) use ($term, $model) {
                $first = true;
                foreach ($this->getCandidateSearchableColumns($model) as $column) {
                    $method = $first ? 'where' : 'orWhere';
                    $first = false;

                    if ($column === $model->getKeyName() && is_numeric($term)) {
                        $query->{$method}($column, (int) $term);
                        continue;
                    }

                    $query->{$method}($column, 'LIKE', '%'.$term.'%');
                }
            });
        }

        $builder->orderByDesc($model->getKeyName());
        $builder->limit($this->definition['candidate_limit']);

        return $builder->get()->map(function (Model $entry) {
            return $this->makeCandidateOption($entry);
        })->all();
    }

    public function resolveCandidatesByIds(array $ids, ?Model $source = null): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

        if ($ids === []) {
            return [];
        }

        $model = $this->crud->model;
        $builder = $model->newQuery()->whereIn($model->getKeyName(), $ids);

        if ($source) {
            $builder->where($model->getKeyName(), '!=', $source->getKey());
        }

        $entries = $builder->get()->sortBy(function (Model $entry) use ($ids) {
            return array_search($entry->getKey(), $ids, true);
        });

        return $entries->values()->map(function (Model $entry) {
            return $this->makeCandidateOption($entry);
        })->all();
    }

    /**
     * Merge $this->sourceEntry into $targetEntry using provided configuration.
     */
    public function mergeInto(Model $targetEntry, array $selectedFields, array $forcedFields, bool $deleteSource, array $relationKeys = []): array
    {
        if (! $this->sourceEntry) {
            throw new InvalidArgumentException('Источник слияния не определён.');
        }

        $selected = array_values(array_intersect($selectedFields, $this->getFieldKeys()));

        if ($selected === []) {
            throw new InvalidArgumentException('Не выбраны поля для слияния.');
        }

        if ($targetEntry->getKey() === null) {
            throw new InvalidArgumentException('Целевая запись должна существовать.');
        }

        $forceMap = array_flip($forcedFields);

        DB::transaction(function () use ($targetEntry, $selected, $forceMap, $deleteSource, $relationKeys) {
            foreach ($selected as $fieldName) {
                $definition = $this->fieldDefinitions[$fieldName] ?? null;

                if (! $definition) {
                    continue;
                }

                $force = array_key_exists($fieldName, $forceMap);
                $this->applyFieldMerge($targetEntry, $this->sourceEntry, $definition, $force);
            }

            $targetEntry->save();

            if ($relationKeys !== []) {
                $this->mergeSelectedRelations($targetEntry, $this->sourceEntry, $relationKeys);
            }

            if ($deleteSource) {
                $this->sourceEntry->delete();
            }
        });

        return [
            'target' => $targetEntry->fresh(),
            'source_deleted' => $deleteSource,
        ];
    }

    protected function applyFieldMerge(Model $target, Model $source, array $definition, bool $force): void
    {
        $field = $definition['key'];
        $handler = $definition['handler'];

        if ($handler) {
            $this->callHandler($target, $source, $handler, $definition, $force);

            return;
        }

        $strategy = $definition['strategy'];

        if ($strategy === static::STRATEGY_TRANSLATIONS) {
            $this->mergeTranslations($target, $source, $field, $force);

            return;
        }

        if ($strategy === static::STRATEGY_APPEND) {
            $this->mergeAppendable($target, $source, $field, $force);

            return;
        }

        $this->mergeReplace($target, $source, $field, $force);
    }

    protected function mergeSelectedRelations(Model $target, Model $source, array $relationKeys): void
    {
        $unique = array_values(array_intersect(array_unique($relationKeys), array_keys($this->relationDefinitions)));

        foreach ($unique as $relationKey) {
            $definition = $this->relationDefinitions[$relationKey] ?? null;

            if (! $definition) {
                continue;
            }

            $this->applyRelationMerge($target, $source, $definition);
        }
    }

    protected function applyRelationMerge(Model $target, Model $source, array $definition): void
    {
        $handler = $definition['handler'];

        if ($handler) {
            $this->callRelationHandler($target, $source, $handler, $definition);

            return;
        }

        $type = $definition['type'] ?? 'table';

        if ($type === 'table') {
            $this->reassignTableRelation($target, $source, $definition);
        }
    }

    protected function reassignTableRelation(Model $target, Model $source, array $definition): void
    {
        $table = $definition['table'] ?? null;

        if (! $table) {
            return;
        }

        $column = $definition['column'] ?? $source->getForeignKey();
        $sourceId = $source->getKey();
        $targetId = $target->getKey();

        if ($sourceId === null || $targetId === null) {
            return;
        }

        $query = DB::table($table)->where($column, $sourceId);
        $this->applyRelationConstraints($query, $definition);

        $affected = $query->update([$column => $targetId]);

        if (! $affected) {
            return;
        }

        $uniqueColumns = $definition['unique'] ?? [];

        if ($uniqueColumns === []) {
            return;
        }

        $this->deduplicateTableRelations($table, $column, $targetId, $uniqueColumns, $definition);
    }

    protected function deduplicateTableRelations(string $table, string $column, $targetId, array $uniqueColumns, array $definition): void
    {
        $primaryKey = $definition['primary_key'] ?? 'id';
        $groupColumns = array_merge([$column], $uniqueColumns);

        $duplicatesQuery = DB::table($table)
            ->select(array_merge($groupColumns, [DB::raw('COUNT(*) as aggregate')]))
            ->where($column, $targetId);

        $this->applyRelationConstraints($duplicatesQuery, $definition);

        $duplicates = $duplicatesQuery
            ->groupBy($groupColumns)
            ->having('aggregate', '>', 1)
            ->get();

        foreach ($duplicates as $duplicate) {
            $idsQuery = DB::table($table)
                ->select($primaryKey)
                ->where($column, $targetId);

            $this->applyRelationConstraints($idsQuery, $definition);

            foreach ($uniqueColumns as $uniqueColumn) {
                if (isset($duplicate->{$uniqueColumn})) {
                    $idsQuery->where($uniqueColumn, $duplicate->{$uniqueColumn});
                }
            }

            $ids = $idsQuery->orderBy($primaryKey)->pluck($primaryKey);

            if ($ids->count() <= 1) {
                continue;
            }

            $idsToDelete = $ids->slice(1)->all();

            if ($idsToDelete !== []) {
                DB::table($table)->whereIn($primaryKey, $idsToDelete)->delete();
            }
        }
    }

    protected function applyRelationConstraints($query, array $definition): void
    {
        $constraints = $definition['constraints'] ?? [];

        foreach ($constraints as $constraint) {
            if (is_callable($constraint)) {
                $constraint($query);
                continue;
            }

            if (is_array($constraint) && isset($constraint['column'])) {
                $operator = $constraint['operator'] ?? '=';
                $value = $constraint['value'] ?? null;

                if ($operator === 'in' && is_array($value)) {
                    $query->whereIn($constraint['column'], $value);
                } else {
                    $query->where($constraint['column'], $operator, $value);
                }
            }
        }
    }

    protected function callRelationHandler(Model $target, Model $source, $handler, array $definition): void
    {
        $payload = [
            'relation' => $definition['key'],
            'definition' => $definition,
        ];

        if (is_string($handler) && method_exists($target, $handler)) {
            $target->{$handler}($source, $payload);

            return;
        }

        if (is_callable($handler)) {
            $handler($target, $source, $payload);
        }
    }

    protected function mergeTranslations(Model $target, Model $source, string $attribute, bool $force): void
    {
        if (! method_exists($target, 'getTranslations') || ! method_exists($target, 'setTranslation')) {
            $this->mergeReplace($target, $source, $attribute, $force);

            return;
        }

        $targetTranslations = (array) $target->getTranslations($attribute);
        $sourceTranslations = (array) $source->getTranslations($attribute);

        foreach ($sourceTranslations as $locale => $value) {
            if ($this->valueIsEmpty($value)) {
                continue;
            }

            $hasValue = array_key_exists($locale, $targetTranslations) && ! $this->valueIsEmpty($targetTranslations[$locale]);

            if (! $hasValue || $force) {
                $target->setTranslation($attribute, $locale, $value);
            }
        }
    }

    protected function mergeAppendable(Model $target, Model $source, string $attribute, bool $force): void
    {
        $targetValue = $target->getAttribute($attribute);
        $sourceValue = $source->getAttribute($attribute);

        if (is_numeric($targetValue) && is_numeric($sourceValue)) {
            $target->setAttribute($attribute, $targetValue + $sourceValue);

            return;
        }

        if (is_string($targetValue) || is_string($sourceValue)) {
            $left = trim((string) $targetValue);
            $right = trim((string) $sourceValue);

            if ($right === '') {
                return;
            }

            if ($force || $left === '') {
                $target->setAttribute($attribute, $right);

                return;
            }

            $target->setAttribute($attribute, trim($left.' '.$right));

            return;
        }

        $targetArray = $this->castToArray($targetValue);
        $sourceArray = $this->castToArray($sourceValue);

        if ($targetArray === null && $sourceArray === null) {
            return;
        }

        if ($this->isAssocArray($targetArray) || $this->isAssocArray($sourceArray)) {
            $merged = $targetArray ?? [];

            foreach ($sourceArray ?? [] as $key => $value) {
                if (! array_key_exists($key, $merged) || $force) {
                    $merged[$key] = $value;
                }
            }

            $target->setAttribute($attribute, $merged);

            return;
        }

        $merged = array_values(array_unique(array_merge($targetArray ?? [], $sourceArray ?? [])));
        $target->setAttribute($attribute, $merged);
    }

    protected function mergeReplace(Model $target, Model $source, string $attribute, bool $force): void
    {
        $sourceValue = $source->getAttribute($attribute);

        if ($this->valueIsEmpty($sourceValue)) {
            return;
        }

        $targetValue = $target->getAttribute($attribute);
        $targetHasValue = ! $this->valueIsEmpty($targetValue);

        if ($targetHasValue && ! $force) {
            return;
        }

        $target->setAttribute($attribute, $sourceValue);
    }

    protected function callHandler(Model $target, Model $source, $handler, array $definition, bool $force): void
    {
        $payload = [
            'field' => $definition['key'],
            'force' => $force,
            'definition' => $definition,
        ];

        if (is_string($handler) && method_exists($target, $handler)) {
            $target->{$handler}($source, $payload);

            return;
        }

        if (is_callable($handler)) {
            $handler($target, $source, $payload);
        }
    }

    protected function resolveDefinition(): array
    {
        $model = $this->crud->model;
        $definition = [];

        if (method_exists($model, 'getServiceMergeConfiguration')) {
            $definition = $model->getServiceMergeConfiguration();
        } elseif (method_exists($model, 'getServiceMergeDefinition')) {
            $definition = $model->getServiceMergeDefinition();
        } elseif (property_exists($model, 'serviceMergeDefinition')) {
            $definition = $model->serviceMergeDefinition;
        }

        $definition = is_array($definition) ? $definition : [];

        return $this->normalizeDefinition($definition);
    }

    protected function normalizeDefinition(array $definition): array
    {
        $label = $definition['label'] ?? 'Слияние записей';
        $description = $definition['description'] ?? 'Объедините данные текущей записи с другой записью.';
        $deleteSource = array_key_exists('delete_source_default', $definition)
            ? (bool) $definition['delete_source_default']
            : true;
        $candidateLimit = (int) ($definition['candidate_limit'] ?? 25);
        $candidateLimit = $candidateLimit > 0 ? $candidateLimit : 25;
        $searchable = Arr::wrap($definition['candidate_search'] ?? []);
        $candidateLabel = $definition['candidate_label'] ?? null;
        $fields = [];
        $defaultFields = [];

        foreach ((array) ($definition['fields'] ?? []) as $key => $config) {
            $normalized = $this->normalizeFieldDefinition($key, $config);
            $fields[$normalized['key']] = $normalized;

            if ($normalized['default']) {
                $defaultFields[] = $normalized['key'];
            }
        }

        $relations = [];
        $relationDefaults = [];

        foreach ((array) ($definition['relations'] ?? []) as $relationKey => $config) {
            $normalized = $this->normalizeRelationDefinition($relationKey, $config);
            $relations[$normalized['key']] = $normalized;

            if ($normalized['default']) {
                $relationDefaults[] = $normalized['key'];
            }
        }

        $candidateQuery = $definition['candidate_query'] ?? null;

        return [
            'label' => $label,
            'description' => $description,
            'delete_source_default' => $deleteSource,
            'candidate_label' => $candidateLabel,
            'candidate_limit' => $candidateLimit,
            'candidate_search' => $searchable,
            'candidate_query' => $candidateQuery,
            'default_fields' => $defaultFields,
            'fields' => $fields,
            'relations' => $relations,
            'relation_defaults' => $relationDefaults,
        ];
    }

    protected function normalizeRelationDefinition(string|int $name, $config): array
    {
        $key = is_string($name) ? trim($name) : '';

        if ($key === '') {
            $key = (string) ($config['name'] ?? '');
        }

        if ($key === '') {
            throw new InvalidArgumentException('Связь слияния должна иметь имя.');
        }

        $label = $config['label'] ?? Str::title(str_replace('_', ' ', $key));
        $type = $config['type'] ?? 'table';
        $handler = $config['handler'] ?? null;
        $default = (bool) ($config['default'] ?? false);
        $help = $config['help'] ?? null;
        $table = $config['table'] ?? null;
        $column = $config['column'] ?? null;
        $constraints = $this->normalizeConstraints($config['constraints'] ?? []);
        $unique = $this->normalizeStringArray($config['unique'] ?? []);
        $primaryKey = $config['primary_key'] ?? 'id';

        return [
            'key' => $key,
            'label' => $label,
            'type' => $type,
            'handler' => $handler,
            'default' => $default,
            'help' => $help,
            'table' => $table,
            'column' => $column,
            'constraints' => $constraints,
            'unique' => $unique,
            'primary_key' => $primaryKey,
        ];
    }

    protected function normalizeFieldDefinition(string|int $name, $config): array
    {
        $key = is_string($name) ? trim($name) : '';

        if ($key === '') {
            $key = (string) ($config['name'] ?? '');
        }

        if ($key === '') {
            throw new InvalidArgumentException('Поле слияния должно иметь имя.');
        }

        $label = $config['label'] ?? Str::title(str_replace('_', ' ', $key));
        $strategy = $config['strategy'] ?? static::STRATEGY_REPLACE;
        $handler = $config['handler'] ?? null;
        $default = (bool) ($config['default'] ?? false);
        $forceDefault = (bool) ($config['force'] ?? false);
        $forceable = array_key_exists('forceable', $config) ? (bool) $config['forceable'] : true;
        $help = $config['help'] ?? null;

        return [
            'key' => $key,
            'label' => $label,
            'strategy' => $strategy,
            'handler' => $handler,
            'default' => $default,
            'force' => $forceDefault,
            'forceable' => $forceable,
            'help' => $help,
        ];
    }

    /**
     * @param  array<int, string>|mixed  $values
     * @return array<int, string>
     */
    protected function normalizeStringArray($values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_filter(array_map(function ($value) {
            if (! is_string($value)) {
                return null;
            }

            $trimmed = trim($value);

            return $trimmed === '' ? null : $trimmed;
        }, $values)));
    }

    /**
     * @param  array<int, mixed>|mixed  $constraints
     * @return array<int, mixed>
     */
    protected function normalizeConstraints($constraints): array
    {
        if (! is_array($constraints)) {
            return [];
        }

        return array_values(array_filter(array_map(function ($constraint) {
            if (is_callable($constraint)) {
                return $constraint;
            }

            if (is_array($constraint) && isset($constraint['column'])) {
                $column = trim((string) $constraint['column']);

                if ($column === '') {
                    return null;
                }

                $operator = $constraint['operator'] ?? '=';
                $value = $constraint['value'] ?? null;

                if (is_string($operator)) {
                    $operator = trim($operator);
                }

                return [
                    'column' => $column,
                    'operator' => $operator,
                    'value' => $value,
                ];
            }

            return null;
        }, $constraints)));
    }

    protected function applyCandidateQuery(Builder $builder, ?Model $source): Builder
    {
        $candidateQuery = $this->definition['candidate_query'] ?? null;

        if ($candidateQuery && is_callable($candidateQuery)) {
            $result = $candidateQuery($builder, $source);

            if ($result instanceof Builder) {
                return $result;
            }
        }

        return $builder;
    }

    protected function getCandidateSearchableColumns(Model $model): array
    {
        $columns = $this->definition['candidate_search'];

        if ($columns === []) {
            $columns = [$model->identifiableAttribute(), $model->getKeyName()];
        }

        return array_values(array_filter(array_unique(array_map(function ($column) {
            return is_string($column) ? trim($column) : null;
        }, $columns))));
    }

    protected function formatCandidateLabel(Model $entry): string
    {
        $labelTemplate = $this->definition['candidate_label'];
        $attribute = $entry->identifiableAttribute();
        $value = $entry->getAttribute($attribute);
        $label = $this->stringifyValue($value) ?: '#'.$entry->getKey();

        if (! $labelTemplate) {
            return sprintf('#%s — %s', $entry->getKey(), $label);
        }

        return strtr($labelTemplate, [
            '%id%' => $entry->getKey(),
            '%label%' => $label,
        ]);
    }

    protected function stringifyValue($value): string
    {
        if ($value instanceof Arrayable) {
            $value = $value->toArray();
        }

        if (is_array($value)) {
            $value = Arr::first(array_filter($value, fn ($item) => ! $this->valueIsEmpty($item)));
        }

        if ($value instanceof \Stringable) {
            return (string) $value;
        }

        return is_scalar($value) ? (string) $value : '';
    }

    protected function castToArray($value): ?array
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            return $value;
        }

        if ($value instanceof Arrayable) {
            return $value->toArray();
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    protected function isAssocArray(?array $value): bool
    {
        if ($value === null) {
            return false;
        }

        return array_keys($value) !== range(0, count($value) - 1);
    }

    protected function valueIsEmpty($value): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_string($value)) {
            return trim($value) === '';
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                if (! $this->valueIsEmpty($item)) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    protected function makeCandidateOption(Model $entry): array
    {
        return [
            'id' => $entry->getKey(),
            'text' => $this->formatCandidateLabel($entry),
            'slug' => method_exists($entry, 'getSlugOrTitleAttribute') ? ($entry->slug_or_title ?? null) : null,
        ];
    }
}
