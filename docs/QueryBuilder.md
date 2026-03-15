# QueryBuilder

A single-file Laravel trait that gives any Eloquent model a full query API — search, filters, sorting, pagination, date ranges, eager loading, and soft-delete control — all driven by HTTP query parameters with zero extra controller code.

---

## Table of Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Model Setup](#model-setup)
- [Configuration Reference](#configuration-reference)
- [Default Sort Resolution](#default-sort-resolution)
- [Controller Usage](#controller-usage)
- [HTTP Query Parameters](#http-query-parameters)
- [Filter Operators](#filter-operators)
- [API Response Shape](#api-response-shape)
- [Overridable Constants](#overridable-constants)
- [Limitations](#limitations)
- [Performance Tips](#performance-tips)

---

## Requirements

| | |
|---|---|
| PHP | 8.1 or higher |
| Laravel | 9, 10, or 11 |
| Database | MySQL / MariaDB / PostgreSQL / SQLite |

---

## Installation

Copy `QueryBuilder.php` into `app/Traits/` in your Laravel project.

```
app/
└── Traits/
    └── QueryBuilder.php
```

No service provider or config registration needed.

---

## Model Setup

Add the trait to any Eloquent model and define four arrays that control what the query builder is allowed to touch.

```php
<?php

namespace App\Models;

use App\Traits\QueryBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class User extends Model
{
    use QueryBuilder;
    use SoftDeletes; // optional — only needed for ?trashed= support

    /**
     * Columns included in global ?search= queries.
     *
     * Use dot-notation to search across relation tables.
     * Keep this list small — search uses LIKE %term% which is not index-friendly.
     * For tables with 100k+ rows consider a dedicated search engine instead.
     */
    protected array $searchable = [
        'name',
        'email',
        'profile.bio',             // HasOne / BelongsTo relation
        'roles.name',              // HasMany / BelongsToMany relation
        'roles.permissions.name',  // nested relation — any depth supported
    ];

    /**
     * Columns the client is allowed to sort by via ?sort_by=
     *
     * Leave empty to allow sorting by any column on the model's own table.
     * Relation sorting is supported one level deep only: 'profile.city' works,
     * 'roles.permissions.name' does not and will be silently skipped.
     */
    protected array $sortable = [
        'id',
        'name',
        'email',
        'created_at',
        'profile.city', // 1-level relation sort
    ];

    /**
     * Columns the client is allowed to filter by via ?filters[col]=
     *
     * Leave empty to allow filtering by any own column plus any relation column
     * you explicitly list here using dot-notation.
     * Ensure frequently filtered columns are indexed in your database.
     */
    protected array $filterable = [
        'status',
        'role',
        'created_at',
        'roles.name',      // relation filter — any depth supported
        'profile.country',
    ];

    /**
     * Relations the client is allowed to eager load via ?with[]=
     *
     * Always define this explicitly.
     * Leaving it empty allows the client to load ANY relation on the model,
     * including expensive ones you may not intend to expose.
     */
    protected array $allowedRelations = [
        'posts',
        'roles',
        'roles.permissions',
        'profile',
    ];
}
```

### Optional: Override defaults

Override pagination and sorting defaults directly on your model.

```php
/**
 * Default number of rows returned per page when ?per_page is absent.
 * Must be lower than or equal to $maxPerPage.
 */
protected int $defaultPerPage = 20;  // default: 15

/**
 * Hard cap on rows per page regardless of what ?per_page requests.
 */
protected int $maxPerPage = 200;     // default: 100

/**
 * Default sort column when ?sort_by is absent.
 * Leave unset to use automatic resolution — see Default Sort Resolution.
 */
protected string $defaultSortBy = 'created_at'; // default: auto-resolved

/**
 * Default sort direction when ?sort_dir is absent.
 * Accepted values: 'asc' | 'desc'
 */
protected string $defaultSortDir = 'desc';       // default: 'asc'
```

---

## Configuration Reference

| Property | Type | Default | Purpose |
|---|---|---|---|
| `$searchable` | `array` | `[]` | Columns included in `?search=` |
| `$sortable` | `array` | `[]` | Columns allowed in `?sort_by=` — empty means all own columns |
| `$filterable` | `array` | `[]` | Columns allowed in `?filters[]=` — empty means all own columns |
| `$allowedRelations` | `array` | `[]` | Relations allowed in `?with[]=` — **always define this** |
| `$defaultPerPage` | `int` | `15` | Rows per page when `?per_page` is absent |
| `$maxPerPage` | `int` | `100` | Hard cap on rows per page |
| `$defaultSortBy` | `string` | auto | Default sort column — resolved automatically if unset |
| `$defaultSortDir` | `string` | `'asc'` | Sort direction when `?sort_dir` is absent |

---

## Default Sort Resolution

When `?sort_by` is not provided and `$defaultSortBy` is not set on the model, the trait resolves the sort column automatically in this order:

| Priority | Condition | Column used |
|---|---|---|
| 1 | `$defaultSortBy` is defined on the model | that value |
| 2 | Model has a single-column primary key | the primary key column (e.g. `code`, `uuid`, `id`) |
| 3 | Composite PK or no PK — schema available | first column returned by the schema |
| 4 | Schema query fails | `rowid` (SQLite fallback) |

This means the trait works correctly out of the box for all common primary key patterns:

```php
// Standard auto-increment
$table->id();                               // sorts by 'id'

// Custom string primary key
$table->string('code')->primary();          // sorts by 'code'

// UUID primary key
$table->uuid('uuid')->primary();            // sorts by 'uuid'

// Composite primary key — sorts by first column in the table
$table->primary(['order_id', 'product_id']); // sorts by 'order_id'
```

To override the automatic resolution, define `$defaultSortBy` on your model:

```php
/**
 * Always sort by name regardless of primary key resolution.
 */
protected string $defaultSortBy = 'name';
```

---

## Controller Usage

### Basic — get a collection

```php
public function index(Request $request)
{
    return User::QueryBuild($request)->get();
}
```

### Chain your own scopes after QueryBuild

`QueryBuild()` returns a standard Eloquent `Builder`. You can chain any scope, condition, or method on it freely.

```php
// Add your own scope
User::QueryBuild($request)->active()->get();

// Add a raw condition
User::QueryBuild($request)->where('verified', true)->get();

// Combine both
User::QueryBuild($request)->active()->where('verified', true)->get();
```

### Standard Laravel paginator

```php
User::QueryBuild($request)->paginate(15);

// Or let per_page come from the request
User::QueryBuild($request)->paginateQuery($request);
```

### Structured JSON response — best for REST APIs and DataTables

```php
public function index(Request $request)
{
    return User::QueryBuild($request)->paginateTable($request);
}

// With additional scopes chained before paginating
public function index(Request $request)
{
    return User::QueryBuild($request)
        ->active()
        ->where('verified', true)
        ->paginateTable($request);
}
```

---

## HTTP Query Parameters

All parameters are optional. Omitting any of them applies the model's configured default for that feature.

---

### Pagination

| Parameter | Example | Notes |
|---|---|---|
| `page` | `?page=3` | Page number, default `1` |
| `per_page` | `?per_page=25` | Rows per page, default `15`, max `100` |

---

### Global Search

Searches across all columns defined in `$searchable`, including relation columns.
Minimum term length is **3 characters** — shorter terms are ignored.

```
?search=john
```

---

### Sorting

Single column:

```
?sort_by=name
?sort_by=name&sort_dir=desc
```

Multi-column — comma-separated, directions matched by position:

```
?sort_by=name,created_at
?sort_by=name,created_at&sort_dir=asc,desc
```

Relation column (1-level only):

```
?sort_by=profile.city
?sort_by=profile.city&sort_dir=asc
```

> Paths deeper than one level (e.g. `roles.permissions.name`) are silently skipped for sorting. They are fully supported for search and filtering.

---

### Filters — Own Columns

**Shorthand** — operator defaults to `=`:

```
?filters[status]=active
```

**With explicit operator:**

```
?filters[name][operator]=like&filters[name][value]=john
?filters[age][operator]=between&filters[age][value]=18,30
?filters[role][operator]=in&filters[role][value]=admin,editor
?filters[score][operator]=>=&filters[score][value]=90
?filters[deleted_at][operator]=null
?filters[deleted_at][operator]=not_null
```

---

### Filters — Relation Columns

Use dot-notation as the filter key. Any depth is supported.

```
?filters[roles.name]=admin
?filters[roles.name][operator]=in&filters[roles.name][value]=admin,editor
?filters[profile.country][operator]=!=&filters[profile.country][value]=US
?filters[roles.permissions.slug][operator]=like&filters[roles.permissions.slug][value]=edit
```

---

## Filter Operators

| Operator | Behaviour |
|---|---|
| `=` | Exact match (default) |
| `!=` | Not equal |
| `<` `>` `<=` `>=` | Numeric / date comparison |
| `like` | Contains — wraps value in `%…%` |
| `not_like` | Does not contain |
| `in` | Value is in the list — comma-separated |
| `not_in` | Value is not in the list |
| `between` | Value is between two bounds — `min,max` |
| `null` | Column IS NULL |
| `not_null` | Column IS NOT NULL |

> Maximum **15 filters** per request. Excess entries are silently dropped.

---

### Date Range

Applies a date range to any date/datetime column.
Full-day precision is used — `date_from` starts at `00:00:00` and `date_to` ends at `23:59:59`, so DATETIME and TIMESTAMP columns include the entire last day of the range.

```
?date_from=2024-01-01
?date_to=2024-12-31
?date_column=updated_at
```

| Parameter | Default |
|---|---|
| `date_column` | `created_at` |

---

### Column Selection

Return only specific columns. The primary key is always included automatically to ensure sorting and pagination function correctly.

```
?columns=id,name,email,status
```

Only columns that exist on the model's own table are accepted. Relation columns cannot be selected this way.

---

### Soft Deletes

Only available when the model uses Laravel's `SoftDeletes` trait.

| Parameter | Behaviour |
|---|---|
| `?trashed=with` | Include soft-deleted rows alongside normal rows |
| `?trashed=only` | Return only soft-deleted rows |
| *(omit)* | Default Eloquent behaviour — soft-deleted rows excluded |

---

### Eager Loading

Load relations alongside the results. Only relations listed in `$allowedRelations` are accepted.

```
?with[]=posts
?with[]=roles
?with[]=roles.permissions
```

Multiple relations:

```
?with[]=posts&with[]=roles&with[]=profile
```

---

## API Response Shape

`paginateTable()` returns a structured array:

```json
{
  "data": [
    { "id": 1, "name": "Alice", "email": "alice@example.com" }
  ],
  "pagination": {
    "total": 200,
    "per_page": 15,
    "current_page": 1,
    "last_page": 14,
    "from": 1,
    "to": 15,
    "has_more": true,
    "links": {
      "first": "https://example.com/api/users?page=1",
      "last":  "https://example.com/api/users?page=14",
      "prev":  null,
      "next":  "https://example.com/api/users?page=2"
    }
  },
  "meta": {
    "search": "john",
    "applied_sorts": [
      "name:asc",
      "created_at:desc"
    ],
    "applied_filters": {
      "search": "john",
      "filters": {
        "status": "=:active",
        "roles.name": "in:admin,editor"
      },
      "date_range": {
        "column": "created_at",
        "from": "2024-01-01",
        "to": "2024-12-31"
      },
      "trashed": "with"
    }
  }
}
```

`applied_sorts` contains only the sort fields that were **actually applied** to the query. Invalid or whitelisted-out columns do not appear here.

---

## Overridable Constants

These constants control internal safety limits. Override them per-model in PHP 8.1+ by redeclaring the constant as `private const` in your model.

| Constant | Default | Purpose |
|---|---|---|
| `QB_MIN_SEARCH_LENGTH` | `3` | Minimum characters for `?search=` |
| `QB_MAX_RELATION_DEPTH` | `3` | Maximum dot-depth for `?with[]=` paths |
| `QB_MAX_FILTER_COUNT` | `15` | Maximum number of `?filters[]=` entries per request |

```php
class User extends Model
{
    use QueryBuilder;

    /**
     * Allow 2-character searches on this model.
     * Default: 3
     */
    private const QB_MIN_SEARCH_LENGTH = 2;

    /**
     * Restrict eager-load depth to 2 levels on this model.
     * Default: 3
     */
    private const QB_MAX_RELATION_DEPTH = 2;
}
```

---

## Limitations

| Area | Detail |
|---|---|
| Relation sorting depth | 1-level only — `profile.city` ✓, `roles.permissions.name` ✗ |
| Column selection | Own table columns only — relation columns cannot be selected via `?columns=` |
| Global search indexing | Uses `LIKE %term%` — B-tree indexes are not used |
| Deep nested relations | 3+ level chains in `$searchable` / `$filterable` generate nested subqueries — test on real data |
| `$allowedRelations` empty | Any relation can be loaded — always define the whitelist |
| Filter cap | Max 15 filters per request — excess are silently dropped |
| Composite primary keys | `$defaultSortBy` auto-resolution picks the first schema column — set `$defaultSortBy` explicitly if that is not the right choice |

---

## Performance Tips

**Index your filtered columns.**
Any column used in `$filterable` that receives frequent requests should have a database index. Unindexed filters cause full table scans.

```php
// In your migration
$table->index('status');
$table->index('created_at');
```

**Keep `$searchable` small.**
Global search fires a `LIKE %term%` against every listed column. Each relation column adds a nested `EXISTS` subquery. On large tables, limit this list to the most important 2–3 columns.

**Index relation foreign keys for relation sorting.**
Relation sorts use correlated subqueries. They depend on the foreign key being indexed.

```php
// profiles.user_id, posts.user_id, role_user.user_id, etc.
$table->index('user_id');
```

**Always define `$allowedRelations`.**
An empty array removes all restrictions on eager loading. A client could request a deeply nested relation graph and cause a cascade of queries.

**Consider cursor pagination for very large datasets.**
Standard offset pagination (`?page=10000`) forces the database to scan all preceding rows. For tables with millions of rows, switch to cursor pagination:

```php
User::QueryBuild($request)->cursorPaginate(15);
```

**Consider a search engine for large-scale text search.**
If your table has 100k+ rows and you need fast full-text search, offload search to Meilisearch, Algolia, or Typesense rather than relying on `$searchable`. Laravel Scout integrates with all three.