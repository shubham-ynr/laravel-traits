<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ╔══════════════════════════════════════════════════════════════════╗
 * ║                     QueryBuilder Trait v4                       ║
 * ║         API-Ready Eloquent Query Building for Laravel           ║
 * ╚══════════════════════════════════════════════════════════════════╝
 *
 *   Add to any Eloquent model for instant API-ready query building:
 *
 *   ✦ Global search       — own columns + deep nested relation columns
 *   ✦ Per-column filters  — 12 operators (=, like, in, between, null…)
 *   ✦ Relation filters    — ?filters[roles.name]=admin  (any depth)
 *   ✦ Multi-column sort   — own columns + 1-level relation sort
 *   ✦ Pagination          — page / per_page, structured JSON response
 *   ✦ Date range          — ?date_from / ?date_to / ?date_column
 *   ✦ Column selection    — ?columns=id,name,email
 *   ✦ Eager loading       — ?with[]=posts&with[]=roles.permissions
 *   ✦ Soft delete control — ?trashed=with | only
 *
 * ┌──────────────────────────────────────────────────────────────────┐
 * │ STEP 1 — MODEL SETUP                                             │
 * └──────────────────────────────────────────────────────────────────┘
 *
 *   use App\Traits\QueryBuilder;
 *
 *   class User extends Model
 *   {
 *       use QueryBuilder;
 *
 *       // Columns included in ?search= (dot-notation searches relation tables)
 *       // Global search uses LIKE %term% — keep this list small on large tables.
 *       // For 100k+ rows consider FULLTEXT indexes or a dedicated search engine.
 *       protected array $searchable = [
 *           'name',
 *           'email',
 *           'profile.bio',              // 1-level relation
 *           'roles.name',              // HasMany / BelongsToMany
 *           'roles.permissions.name',  // deep nested (any depth)
 *       ];
 *
 *       // Columns allowed for ?sort_by= (empty = all own table columns)
 *       // Relation sort is 1-level only: 'profile.city' works, 'roles.permissions.name' does not.
 *       // Relation sorts use a SELECT subquery — safe pagination, no duplicate rows.
 *       protected array $sortable = [
 *           'id', 'name', 'email', 'created_at',
 *           'profile.city',
 *       ];
 *
 *       // Columns allowed for ?filters[col]= (empty = all own columns + listed relation cols)
 *       // Ensure frequently filtered columns are indexed in the database.
 *       protected array $filterable = [
 *           'status', 'role', 'created_at',
 *           'roles.name',
 *           'profile.country',
 *       ];
 *
 *       // Relations allowed for ?with[]= eager loading.
 *       // Always define this — an empty array allows ANY relation to be loaded,
 *       // which can trigger expensive unintended queries.
 *       protected array $allowedRelations = [
 *           'posts', 'roles', 'roles.permissions', 'profile',
 *       ];
 *   }
 *
 * ┌──────────────────────────────────────────────────────────────────┐
 * │ STEP 2 — CONTROLLER USAGE                                        │
 * └──────────────────────────────────────────────────────────────────┘
 *
 *   // Returns a fully configured Eloquent Builder — chain anything after it
 *   User::QueryBuild($request)->get();
 *   User::QueryBuild($request)->active()->get();
 *   User::QueryBuild($request)->where('verified', true)->get();
 *
 *   // Standard Laravel paginator
 *   User::QueryBuild($request)->paginate(15);
 *
 *   // Structured JSON response — best for DataTable / REST API
 *   return User::QueryBuild($request)->paginateTable($request);
 *   return User::QueryBuild($request)->active()->paginateTable($request);
 *
 * ┌──────────────────────────────────────────────────────────────────┐
 * │ STEP 3 — HTTP QUERY PARAMETERS                                   │
 * └──────────────────────────────────────────────────────────────────┘
 *
 *   PAGINATION
 *     ?page=2
 *     ?per_page=25                         (default: 15, max: 100)
 *
 *   GLOBAL SEARCH
 *     ?search=john                         searches all $searchable columns
 *                                          minimum 3 characters required
 *
 *   SORTING
 *     ?sort_by=name                        single column
 *     ?sort_by=name,created_at             multi-column
 *     ?sort_dir=desc                       applies to all columns
 *     ?sort_by=name,created_at&sort_dir=asc,desc   per-column directions
 *     ?sort_by=profile.city               1-level relation sort
 *
 *   FILTERS — own columns
 *     ?filters[status]=active
 *     ?filters[name][operator]=like&filters[name][value]=john
 *     ?filters[age][operator]=between&filters[age][value]=18,30
 *     ?filters[role][operator]=in&filters[role][value]=admin,editor
 *     ?filters[deleted_at][operator]=null
 *     ?filters[score][operator]=>=&filters[score][value]=90
 *
 *   FILTERS — relation columns (dot-notation, any depth)
 *     ?filters[roles.name]=admin
 *     ?filters[roles.name][operator]=in&filters[roles.name][value]=admin,editor
 *     ?filters[roles.permissions.slug][operator]=like&filters[roles.permissions.slug][value]=edit
 *
 *   FILTER OPERATORS
 *     =  !=  <  >  <=  >=  like  not_like  in  not_in  between  null  not_null
 *
 *   DATE RANGE
 *     ?date_from=2024-01-01
 *     ?date_to=2024-12-31
 *     ?date_column=updated_at             (default: created_at)
 *     Full-day precision: from 00:00:00 → to 23:59:59
 *
 *   COLUMN SELECTION
 *     ?columns=id,name,email              primary key always included automatically
 *
 *   SOFT DELETES  (model must use SoftDeletes)
 *     ?trashed=with                       include soft-deleted rows
 *     ?trashed=only                       only soft-deleted rows
 *
 *   EAGER LOADING
 *     ?with[]=posts
 *     ?with[]=roles.permissions
 *
 * ┌──────────────────────────────────────────────────────────────────┐
 * │ PAGINATETABLE RESPONSE SHAPE                                     │
 * └──────────────────────────────────────────────────────────────────┘
 *
 *   {
 *     "data": [...],
 *     "pagination": {
 *       "total": 200, "per_page": 15, "current_page": 1, "last_page": 14,
 *       "from": 1, "to": 15, "has_more": true,
 *       "links": { "first", "last", "prev", "next" }
 *     },
 *     "meta": {
 *       "search": "john",
 *       "applied_sorts": ["name:asc", "created_at:desc"],
 *       "applied_filters": { "filters": {...}, "date_range": {...}, "trashed": "with" }
 *     }
 *   }
 *
 * ┌──────────────────────────────────────────────────────────────────┐
 * │ KNOWN LIMITATIONS                                                │
 * └──────────────────────────────────────────────────────────────────┘
 *
 *   • Relation sorting — 1-level depth only (profile.city ✓, roles.permissions.name ✗)
 *   • Global search — LIKE %term% cannot use B-tree indexes
 *   • Deep nested relations (3+ levels) in $searchable / $filterable may generate
 *     heavy subqueries — benchmark on real data before enabling
 *   • $allowedRelations empty = no restriction on eager loading — always define it
 *   • Max 15 filters per request — excess filters are silently dropped
 */
trait QueryBuilder
{
    // ──────────────────────────────────────────────────
    // Model Configuration — getter methods
    //
    // The trait declares NO properties to avoid PHP 8.4 fatal errors when
    // the using model already defines a property with a different type hint
    // (e.g. Laravel Scout declares $searchable without a type hint).
    //
    // To customise behaviour, define the matching property on your model:
    //
    //     protected array  $searchable       = ['name', 'email'];
    //     protected array  $sortable          = ['id', 'name', 'created_at'];
    //     protected array  $filterable        = ['status', 'role'];
    //     protected array  $allowedRelations  = ['posts', 'roles'];
    //     protected int    $defaultPerPage    = 20;
    //     protected int    $maxPerPage        = 200;
    //     protected string $defaultSortBy     = 'created_at';
    //     protected string $defaultSortDir    = 'desc';
    // ──────────────────────────────────────────────────

    /** Columns to include in ?search= — read from model property or default to [] */
    private function qbSearchable(): array
    {
        return property_exists($this, 'searchable') ? (array) $this->searchable : [];
    }

    /** Columns allowed for ?sort_by= — read from model property or default to [] */
    private function qbSortable(): array
    {
        return property_exists($this, 'sortable') ? (array) $this->sortable : [];
    }

    /** Columns allowed for ?filters[]= — read from model property or default to [] */
    private function qbFilterable(): array
    {
        return property_exists($this, 'filterable') ? (array) $this->filterable : [];
    }

    /** Relations allowed for ?with[]= — read from model property or default to [] */
    private function qbAllowedRelations(): array
    {
        return property_exists($this, 'allowedRelations') ? (array) $this->allowedRelations : [];
    }

    /** Default rows per page — read from model property or default to 15 */
    private function qbDefaultPerPage(): int
    {
        return property_exists($this, 'defaultPerPage') ? (int) $this->defaultPerPage : 15;
    }

    /** Maximum rows per page — read from model property or default to 100 */
    private function qbMaxPerPage(): int
    {
        return property_exists($this, 'maxPerPage') ? (int) $this->maxPerPage : 100;
    }

    /**
     * Resolve the default sort column.
     *
     * Priority:
     *   1. Model's $defaultSortBy property  — explicit developer choice
     *   2. Model's $primaryKey              — only when it is a single string
     *   3. First column in the table schema — fallback for composite / no PK tables
     *   4. Literal 'rowid'                  — last resort when schema is unavailable
     */
    private function qbDefaultSortBy(): string
    {
        // 1. Developer explicitly set $defaultSortBy on the model
        if (property_exists($this, 'defaultSortBy') && is_string($this->defaultSortBy)) {
            return $this->defaultSortBy;
        }

        // 2. Single-column primary key — the safest automatic sort column
        $pk = $this->getKeyName();
        if (is_string($pk) && $pk !== '') {
            return $pk;
        }

        // 3. Composite or no PK — use the first real column from the table schema
        $columns = $this->qbTableColumns();
        if (! empty($columns)) {
            return $columns[0];
        }

        // 4. Absolute last resort (SQLite rowid, etc.)
        return 'rowid';
    }

    /** Default sort direction — read from model property or default to 'asc' */
    private function qbDefaultSortDir(): string
    {
        return property_exists($this, 'defaultSortDir') ? (string) $this->defaultSortDir : 'asc';
    }

    // ── Constants — override per-model with private const in PHP 8.1+ ─────────

    /** Minimum ?search= term length — shorter terms are silently ignored */
    private const QB_MIN_SEARCH_LENGTH = 3;

    /** Maximum dot-depth for ?with[]= relation paths — deeper paths are dropped */
    private const QB_MAX_RELATION_DEPTH = 3;

    /** Maximum number of ?filters[]= entries per request — excess are dropped */
    private const QB_MAX_FILTER_COUNT = 15;

    /** All valid filter operators */
    private const QB_OPERATORS = [
        '=', '!=', '<', '>', '<=', '>=',
        'like', 'not_like',
        'in', 'not_in',
        'between',
        'null', 'not_null',
    ];

    /**
     * Stores the list of sort fields that were actually applied per query instance.
     * Keyed by spl_object_id() of the underlying Query\Builder so it survives
     * Eloquent Builder clones without requiring dynamic property assignment.
     *
     * @var array<int, list<string>>
     */
    private static array $qbSortsRegistry = [];

    // ──────────────────────────────────────────────────
    // Entry Point
    // ──────────────────────────────────────────────────

    /**
     * Build a fully-configured Eloquent Builder from the incoming request.
     *
     * Applies column selection, soft-delete scope, search, filters, date range,
     * eager loading, and sorting — in that order. Returns the Builder so you
     * can freely chain additional scopes or conditions before executing.
     *
     * @param  Request|array  $request
     * @return Builder
     *
     * @example
     *   User::QueryBuild($request)->get();
     *   User::QueryBuild($request)->active()->paginate(15);
     *   User::QueryBuild($request)->where('verified', true)->paginateTable($request);
     */
    public static function QueryBuild(Request|array $request): Builder
    {
        $params   = $request instanceof Request ? $request->all() : $request;
        $instance = new static();
        $query    = static::query();

        $query = $instance->qbApplyColumnSelection($query, $params);
        $query = $instance->qbApplySoftDeletes($query, $params);
        $query = $instance->qbApplySearch($query, $params);
        $query = $instance->qbApplyFilters($query, $params);
        $query = $instance->qbApplyDateRange($query, $params);
        $query = $instance->qbApplyEagerLoad($query, $params);
        $query = $instance->qbApplySorting($query, $params);

        return $query;
    }

    // ──────────────────────────────────────────────────
    // Pagination
    // ──────────────────────────────────────────────────

    /**
     * Standard Laravel LengthAwarePaginator.
     * Call after QueryBuild() or after any additional chained scopes.
     *
     * @example
     *   User::QueryBuild($request)->paginateQuery($request);
     *   User::QueryBuild($request)->active()->paginateQuery($request);
     */
    public function scopePaginateQuery(Builder $query, Request|array $request): LengthAwarePaginator
    {
        $p    = $request instanceof Request ? $request->all() : $request;
        $page = max((int) ($p['page'] ?? 1), 1);

        return $query->paginate($this->qbResolvePerPage($p), ['*'], 'page', $page);
    }

    /**
     * Paginate and return a structured array response ready for JSON APIs and DataTables.
     *
     * Response includes: data, pagination (total, pages, links), and meta
     * (search term, actually-applied sorts, active filters, date range, trashed state).
     *
     * @example
     *   return User::QueryBuild($request)->paginateTable($request);
     *   return User::QueryBuild($request)->active()->paginateTable($request);
     */
    public function scopePaginateTable(Builder $query, Request|array $request): array
    {
        $p         = $request instanceof Request ? $request->all() : $request;
        $paginated = $this->scopePaginateQuery($query, $p);

        // Read applied sorts from the typed static registry.
        // Falls back to the raw request values if qbApplySorting was not called
        // (e.g. the Builder was constructed manually without QueryBuild()).
        $appliedSorts = self::$qbSortsRegistry[spl_object_id($query->getQuery())]
            ?? [($p['sort_by'] ?? $this->qbDefaultSortBy()) . ':' . ($p['sort_dir'] ?? $this->qbDefaultSortDir())];

        return [
            'data'       => $paginated->items(),
            'pagination' => [
                'total'        => $paginated->total(),
                'per_page'     => $paginated->perPage(),
                'current_page' => $paginated->currentPage(),
                'last_page'    => $paginated->lastPage(),
                'from'         => $paginated->firstItem(),
                'to'           => $paginated->lastItem(),
                'has_more'     => $paginated->hasMorePages(),
                'links'        => [
                    'first' => $paginated->url(1),
                    'last'  => $paginated->url($paginated->lastPage()),
                    'prev'  => $paginated->previousPageUrl(),
                    'next'  => $paginated->nextPageUrl(),
                ],
            ],
            'meta' => [
                'search'          => $p['search'] ?? null,
                'applied_sorts'   => $appliedSorts,
                'applied_filters' => $this->qbSummarizeFilters($p),
            ],
        ];
    }

    // ──────────────────────────────────────────────────
    // Column Selection
    // Restricts the SELECT list to the columns requested via ?columns=
    // Validates against real table columns to prevent SQL errors.
    // Always injects the primary key so sorting and pagination never break.
    // Prefixes each column with the table name to prevent JOIN ambiguity.
    // ──────────────────────────────────────────────────

    private function qbApplyColumnSelection(Builder $query, array $p): Builder
    {
        $raw = $p['columns'] ?? null;
        if (! $raw) {
            return $query;
        }

        $requested = array_map('trim', explode(',', $raw));
        $ownCols   = $this->qbTableColumns();

        $safe = empty($ownCols)
            ? $requested
            : array_values(array_intersect($requested, $ownCols));

        // Primary key must always be present for correct sorting, pagination, and eager-load matching
        $pk = $this->getKeyName();
        if (! in_array($pk, $safe, true)) {
            array_unshift($safe, $pk);
        }

        // Prefix with table name to avoid column ambiguity when JOINs are added by relation sorting
        $prefixed = array_map(fn ($col) => $this->getTable() . '.' . $col, $safe);
        $query->select($prefixed);

        return $query;
    }

    // ──────────────────────────────────────────────────
    // Soft Deletes
    // Exposes ?trashed= to control soft-deleted row visibility.
    // Only activates when the model uses the SoftDeletes trait.
    // Without the parameter, Eloquent's default behaviour is preserved.
    //
    // ?trashed=with  → withTrashed()   include soft-deleted rows
    // ?trashed=only  → onlyTrashed()   return only soft-deleted rows
    // ──────────────────────────────────────────────────

    private function qbApplySoftDeletes(Builder $query, array $p): Builder
    {
        if (! in_array(\Illuminate\Database\Eloquent\SoftDeletes::class, class_uses_recursive(static::class), true)) {
            return $query;
        }

        return match ($p['trashed'] ?? null) {
            'with'  => $query->withTrashed(),
            'only'  => $query->onlyTrashed(),
            default => $query,
        };
    }

    // ──────────────────────────────────────────────────
    // Global Search
    // Searches across all $searchable columns using LIKE %term%.
    // Own columns are table-qualified to prevent JOIN ambiguity.
    // Relation columns (dot-notation) are resolved via nested whereHas chains.
    // All conditions are wrapped in a single OR group so they do not interfere
    // with any WHERE clauses added before or after QueryBuild().
    // Terms shorter than QB_MIN_SEARCH_LENGTH are silently skipped.
    // ──────────────────────────────────────────────────

    private function qbApplySearch(Builder $query, array $p): Builder
    {
        $term = trim($p['search'] ?? '');

        if ($term === '' || empty($this->qbSearchable())) {
            return $query;
        }

        if (strlen($term) < self::QB_MIN_SEARCH_LENGTH) {
            return $query;
        }

        $query->where(function (Builder $q) use ($term) {
            foreach ($this->qbSearchable() as $field) {
                if (str_contains($field, '.')) {
                    $this->qbWhereHasNested(
                        query      : $q,
                        dotField   : $field,
                        callback   : fn (Builder $leaf) =>
                            $leaf->where($this->qbLeafColumn($field), 'LIKE', "%{$term}%"),
                        useOrWhere : true,
                    );
                } else {
                    $q->orWhere($this->getTable() . '.' . $field, 'LIKE', "%{$term}%");
                }
            }
        });

        return $query;
    }

    // ──────────────────────────────────────────────────
    // Filters
    // Applies per-column filter conditions from ?filters[col][operator]=&value=
    // Supports own columns and relation columns via dot-notation (any depth).
    // Relation filters are applied via nested whereHas chains.
    // Own column filters are table-qualified to prevent JOIN ambiguity.
    // Excess filter entries beyond QB_MAX_FILTER_COUNT are silently dropped.
    // Unexpected array values for scalar operators are flattened to a CSV string.
    // ──────────────────────────────────────────────────

    private function qbApplyFilters(Builder $query, array $p): Builder
    {
        $filters = $p['filters'] ?? [];

        if (empty($filters) || ! is_array($filters)) {
            return $query;
        }

        // Cap the number of filter entries to prevent complex subquery abuse
        if (count($filters) > self::QB_MAX_FILTER_COUNT) {
            $filters = array_slice($filters, 0, self::QB_MAX_FILTER_COUNT, true);
        }

        foreach ($filters as $field => $definition) {
            if (! empty($this->qbFilterable()) && ! in_array($field, $this->qbFilterable(), true)) {
                continue;
            }

            // Shorthand: ?filters[status]=active  →  operator = , value = active
            if (! is_array($definition)) {
                $definition = ['operator' => '=', 'value' => $definition];
            }

            $operator = strtolower($definition['operator'] ?? '=');
            $value    = $definition['value'] ?? null;

            if (! in_array($operator, self::QB_OPERATORS, true)) {
                continue;
            }

            // Flatten unexpected array values for scalar operators to avoid SQL errors
            if (is_array($value) && ! in_array($operator, ['in', 'not_in', 'between'], true)) {
                $value = implode(',', array_map('strval', array_values($value)));
            }

            // Validate that BETWEEN has exactly two values before applying
            if ($operator === 'between') {
                $range = $this->qbToArray($value);
                if (count($range) !== 2) {
                    continue;
                }
            }

            if (str_contains($field, '.')) {
                $leaf = $this->qbLeafColumn($field);
                $this->qbWhereHasNested(
                    query    : $query,
                    dotField : $field,
                    callback : fn (Builder $q) => $this->qbApplyOperator($q, $leaf, $operator, $value),
                );
            } else {
                $this->qbApplyOperator($query, $this->getTable() . '.' . $field, $operator, $value);
            }
        }

        return $query;
    }

    /**
     * Apply a single filter operator to a Builder.
     *
     * Used for both own-column filters (column is already table-qualified)
     * and the leaf query inside a relation whereHas chain (column is raw).
     */
    private function qbApplyOperator(Builder $q, string $col, string $op, mixed $val): void
    {
        match ($op) {
            '=', '!=', '<', '>', '<=', '>=' => $q->where($col, $op, $val),
            'like'                           => $q->where($col, 'LIKE', "%{$val}%"),
            'not_like'                       => $q->where($col, 'NOT LIKE', "%{$val}%"),
            'in'                             => $q->whereIn($col, $this->qbToArray($val)),
            'not_in'                         => $q->whereNotIn($col, $this->qbToArray($val)),
            'between'                        => (function () use ($q, $col, $val) {
                $r = $this->qbToArray($val);
                $q->whereBetween($col, [$r[0], $r[1]]);
            })(),
            'null'                           => $q->whereNull($col),
            'not_null'                       => $q->whereNotNull($col),
            default                          => null,
        };
    }

    // ──────────────────────────────────────────────────
    // Sorting
    // Supports comma-separated columns with matching per-column directions.
    // Own columns are table-qualified to prevent JOIN ambiguity.
    // Relation columns (1-level only) are sorted via a SELECT subquery —
    // this avoids duplicate rows and pagination count errors that JOINs cause.
    // Rows with no matching related record sort to the bottom (NULL-last).
    // Only successfully applied sorts are stored and returned in the meta.
    // ──────────────────────────────────────────────────

    private function qbApplySorting(Builder $query, array $p): Builder
    {
        $columns    = array_map('trim', explode(',', $p['sort_by']  ?? $this->qbDefaultSortBy()));
        $directions = array_map('trim', explode(',', $p['sort_dir'] ?? $this->qbDefaultSortDir()));
        $ownCols    = $this->qbTableColumns();
        $joinRegistry = [];
        $appliedSorts = [];

        foreach ($columns as $i => $field) {
            $dir = strtolower($directions[$i] ?? 'asc');
            $dir = in_array($dir, ['asc', 'desc'], true) ? $dir : 'asc';

            if (! empty($this->qbSortable()) && ! in_array($field, $this->qbSortable(), true)) {
                continue;
            }

            if (str_contains($field, '.')) {
                // Only 1-level relation paths are supported (e.g. profile.city)
                if (count(explode('.', $field)) !== 2) {
                    continue;
                }
                $this->qbSortByRelation($query, $field, $dir, $joinRegistry);
                $appliedSorts[] = "{$field}:{$dir}";
            } else {
                if (! empty($ownCols) && ! in_array($field, $ownCols, true)) {
                    continue;
                }
                $query->orderBy($this->getTable() . '.' . $field, $dir);
                $appliedSorts[] = "{$field}:{$dir}";
            }
        }

        // Store applied sorts in the typed static registry, keyed by the underlying
        // query object's ID. This avoids dynamic property assignment on a class
        // that does not declare the property, which Intelephense flags as P1014.
        // spl_object_id() returns the same ID for the same object instance, so the
        // value is accessible from any Builder wrapper that shares the same base query.
        self::$qbSortsRegistry[spl_object_id($query->getQuery())] = $appliedSorts;

        return $query;
    }

    /**
     * Apply a 1-level relation sort using the appropriate strategy per relation type.
     *
     * BelongsTo   — LEFT JOIN (no duplicates possible on a many-to-one join)
     * HasOne      — SELECT subquery (single row guaranteed, no join needed)
     * HasMany     — SELECT subquery with ORDER BY + LIMIT 1 (avoids row duplication)
     * BelongsToMany — SELECT subquery through the pivot table (avoids pivot duplication)
     *
     * For HasOne / HasMany / BelongsToMany the subquery is computed once as a named
     * SELECT alias and referenced by name in both ORDER BY clauses, so the database
     * evaluates it only once per row instead of twice.
     *
     * Rows with no matching related record always sort to the bottom (NULL-last)
     * regardless of sort direction.
     *
     * @param  array  &$joinRegistry  Tracks which relation aliases are already joined
     *                                to prevent duplicate LEFT JOINs on multi-sort.
     */
    private function qbSortByRelation(Builder $query, string $dotField, string $dir, array &$joinRegistry): void
    {
        [$relationName, $leafCol] = explode('.', $dotField, 2);

        // Skip if this relation was already joined / subqueried in this request
        if (isset($joinRegistry[$relationName])) {
            return;
        }

        try {
            $model    = $query->getModel();
            $relation = $this->qbResolveRelation($model, [$relationName]);

            if (! $relation) {
                return;
            }

            $relTable = $relation->getRelated()->getTable();
            $ownTable = $model->getTable();
            $ownerPk  = $model->getKeyName();

            // Validate the leaf column exists on the related table
            $relCols = Schema::getColumnListing($relTable);
            if (! empty($relCols) && ! in_array($leafCol, $relCols, true)) {
                return;
            }

            // When addSelect() is used for subquery aliases, ensure the main model
            // columns are not silently dropped if no explicit SELECT was set yet
            if (empty($query->getQuery()->columns)) {
                $query->select($ownTable . '.*');
            }

            if ($relation instanceof BelongsTo) {
                // Many-to-one: safe to LEFT JOIN — no duplicate rows possible
                $fk      = $relation->getForeignKeyName();
                $ownerPk = $relation->getOwnerKeyName();
                $alias   = 'qb_sort_' . $relationName;

                $joinRegistry[$relationName] = $alias;

                $query
                    ->leftJoin("{$relTable} as {$alias}", "{$ownTable}.{$fk}", '=', "{$alias}.{$ownerPk}")
                    ->orderByRaw("{$alias}.{$leafCol} IS NULL")
                    ->orderBy("{$alias}.{$leafCol}", $dir);

            } elseif ($relation instanceof HasOne) {
                // Single related row — correlated subquery computed once via SELECT alias
                $fk         = $relation->getForeignKeyName();
                $sortAlias  = 'qb_sv_' . $relationName . '_' . $leafCol;

                $joinRegistry[$relationName] = true;

                $sub = DB::table($relTable)
                    ->select($leafCol)
                    ->whereColumn($fk, "{$ownTable}.{$ownerPk}")
                    ->limit(1);

                $query
                    ->addSelect(DB::raw("({$sub->toSql()}) as `{$sortAlias}`"))
                    ->addBinding($sub->getBindings(), 'select')
                    ->orderByRaw("`{$sortAlias}` IS NULL")
                    ->orderByRaw("`{$sortAlias}` {$dir}");

            } elseif ($relation instanceof HasMany) {
                // Multiple related rows — take the first by the sort column via subquery
                $fk        = $relation->getForeignKeyName();
                $sortAlias = 'qb_sv_' . $relationName . '_' . $leafCol;

                $joinRegistry[$relationName] = true;

                $sub = DB::table($relTable)
                    ->select($leafCol)
                    ->whereColumn($fk, "{$ownTable}.{$ownerPk}")
                    ->orderBy($leafCol, $dir)
                    ->limit(1);

                $query
                    ->addSelect(DB::raw("({$sub->toSql()}) as `{$sortAlias}`"))
                    ->addBinding($sub->getBindings(), 'select')
                    ->orderByRaw("`{$sortAlias}` IS NULL")
                    ->orderByRaw("`{$sortAlias}` {$dir}");

            } elseif ($relation instanceof BelongsToMany) {
                // Many-to-many — JOIN through pivot inside the subquery to avoid row duplication
                $pivotTable = $relation->getTable();
                $relatedKey = $relation->getRelatedPivotKeyName();
                $foreignKey = $relation->getForeignPivotKeyName();
                $relatedPk  = $relation->getRelated()->getKeyName();
                $sortAlias  = 'qb_sv_' . $relationName . '_' . $leafCol;

                $joinRegistry[$relationName] = true;

                $sub = DB::table($relTable)
                    ->select("{$relTable}.{$leafCol}")
                    ->join($pivotTable, "{$pivotTable}.{$relatedKey}", '=', "{$relTable}.{$relatedPk}")
                    ->whereColumn("{$pivotTable}.{$foreignKey}", "{$ownTable}.{$ownerPk}")
                    ->orderBy("{$relTable}.{$leafCol}", $dir)
                    ->limit(1);

                $query
                    ->addSelect(DB::raw("({$sub->toSql()}) as `{$sortAlias}`"))
                    ->addBinding($sub->getBindings(), 'select')
                    ->orderByRaw("`{$sortAlias}` IS NULL")
                    ->orderByRaw("`{$sortAlias}` {$dir}");
            }

        } catch (\Throwable) {
            // Unresolvable relation or schema error — skip silently, never crash the request
        }
    }

    // ──────────────────────────────────────────────────
    // Date Range
    // Filters by a date/datetime column between an optional from and to value.
    // The column is validated against the real table schema before applying.
    // Full-day precision: from uses 00:00:00 and to uses 23:59:59 so that
    // DATETIME/TIMESTAMP columns include the entire last day of the range.
    // The column is table-qualified to prevent JOIN ambiguity.
    // ──────────────────────────────────────────────────

    private function qbApplyDateRange(Builder $query, array $p): Builder
    {
        $from   = $p['date_from']   ?? null;
        $to     = $p['date_to']     ?? null;
        $column = $p['date_column'] ?? 'created_at';

        // Reject unknown column names to prevent SQL errors
        $ownCols = $this->qbTableColumns();
        if (! empty($ownCols) && ! in_array($column, $ownCols, true)) {
            return $query;
        }

        $qualified = $this->getTable() . '.' . $column;

        if ($from) {
            $query->where($qualified, '>=', $from . ' 00:00:00');
        }

        if ($to) {
            $query->where($qualified, '<=', $to . ' 23:59:59');
        }

        return $query;
    }

    // ──────────────────────────────────────────────────
    // Eager Loading
    // Loads relations requested via ?with[]=
    // Only relations listed in $allowedRelations are permitted.
    // Relation paths deeper than QB_MAX_RELATION_DEPTH dots are stripped
    // to prevent expensive deep eager-load graphs.
    // ──────────────────────────────────────────────────

    private function qbApplyEagerLoad(Builder $query, array $p): Builder
    {
        $requested = $p['with'] ?? [];

        if (empty($requested) || ! is_array($requested)) {
            return $query;
        }

        $allowed = $this->qbAllowedRelations();

        $safe = empty($allowed)
            ? $requested
            : array_values(array_filter($requested, fn ($r) => in_array($r, $allowed, true)));

        // Drop relation paths that exceed the maximum allowed depth
        $safe = array_values(
            array_filter($safe, fn ($r) => substr_count($r, '.') < self::QB_MAX_RELATION_DEPTH)
        );

        if (! empty($safe)) {
            $query->with($safe);
        }

        return $query;
    }

    // ──────────────────────────────────────────────────
    // Nested WhereHas Builder
    // Converts a dot-notation field into a nested whereHas chain.
    // The $callback receives the deepest (leaf) Builder instance.
    //
    // Example — 'roles.permissions.name':
    //   whereHas('roles', fn =>
    //     whereHas('permissions', fn =>
    //       $callback($leafBuilder)
    //     )
    //   )
    //
    // Invalid or misspelled relation names are silently skipped via try/catch.
    // Pass $useOrWhere = true to wrap the top-level chain in orWhere (for search).
    // ──────────────────────────────────────────────────

    private function qbWhereHasNested(
        Builder  $query,
        string   $dotField,
        callable $callback,
        bool     $useOrWhere = false,
    ): void {
        $parts = explode('.', $dotField);
        array_pop($parts); // remove leaf column — keep only relation names

        if (empty($parts)) {
            return;
        }

        $buildChain = function (Builder $q, array $relations) use ($callback, &$buildChain): void {
            $current = array_shift($relations);
            $hasMore = ! empty($relations);

            try {
                $q->whereHas($current, function (Builder $inner) use ($relations, $callback, $hasMore, &$buildChain) {
                    $hasMore ? $buildChain($inner, $relations) : $callback($inner);
                });
            } catch (\Throwable) {
                // Invalid relation name — silently skip this condition
            }
        };

        $useOrWhere
            ? $query->orWhere(fn (Builder $q) => $buildChain($q, $parts))
            : $buildChain($query, $parts);
    }

    // ──────────────────────────────────────────────────
    // Utility Helpers
    // ──────────────────────────────────────────────────

    /**
     * Walk a chain of Eloquent relation names on a model instance and return
     * the final relation object. Returns null if any name in the chain does not
     * correspond to a method on the current model.
     *
     * @param  object  $model
     * @param  array   $chain  e.g. ['profile'] or ['roles', 'permissions']
     */
    private function qbResolveRelation(object $model, array $chain): ?object
    {
        $current = $model;
        $rel     = null;

        foreach ($chain as $name) {
            if (! method_exists($current, $name)) {
                return null;
            }
            $rel     = $current->{$name}();
            $current = $rel->getRelated();
        }

        return $rel;
    }

    /**
     * Extract the column name (last segment) from a dot-notation field string.
     * 'roles.permissions.name'  →  'name'
     */
    private function qbLeafColumn(string $dotField): string
    {
        $parts = explode('.', $dotField);
        return end($parts);
    }

    /**
     * Return the real column list for this model's table.
     * Cached statically per connection+table to avoid repeated schema queries.
     * The cache key includes the connection name to support multi-database apps
     * where different connections may share identical table names.
     */
    private function qbTableColumns(): array
    {
        static $cache = [];

        $conn  = $this->getConnectionName() ?? 'default';
        $key   = $conn . ':' . $this->getTable();

        if (! isset($cache[$key])) {
            try {
                $cache[$key] = Schema::connection($conn)->getColumnListing($this->getTable());
            } catch (\Throwable) {
                $cache[$key] = [];
            }
        }

        return $cache[$key];
    }

    /**
     * Clamp the requested per_page value to the configured [1 … $maxPerPage] range.
     */
    private function qbResolvePerPage(array $p): int
    {
        return min(max((int) ($p['per_page'] ?? $this->qbDefaultPerPage()), 1), $this->qbMaxPerPage());
    }

    /**
     * Convert a comma-separated string or an array into a flat array of trimmed values.
     * 'admin,editor'  →  ['admin', 'editor']
     */
    private function qbToArray(mixed $value): array
    {
        return is_array($value)
            ? $value
            : array_map('trim', explode(',', (string) $value));
    }

    /**
     * Build a concise summary of all active filters for the meta block
     * returned by paginateTable(). Includes search term, per-column filters,
     * date range, and trashed state.
     */
    private function qbSummarizeFilters(array $p): array
    {
        $summary = [];

        if (! empty($p['search'])) {
            $summary['search'] = $p['search'];
        }

        foreach ($p['filters'] ?? [] as $col => $def) {
            $summary['filters'][$col] = is_array($def)
                ? ($def['operator'] ?? '=') . ':' . ($def['value'] ?? '')
                : '=' . $def;
        }

        if (! empty($p['date_from']) || ! empty($p['date_to'])) {
            $summary['date_range'] = [
                'column' => $p['date_column'] ?? 'created_at',
                'from'   => $p['date_from']   ?? null,
                'to'     => $p['date_to']     ?? null,
            ];
        }

        if (! empty($p['trashed'])) {
            $summary['trashed'] = $p['trashed'];
        }

        return $summary;
    }
}