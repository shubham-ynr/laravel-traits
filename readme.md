# 🛠️ Laravel Traits

> Plug-and-play Eloquent traits for Laravel — drop them into any model and get a full query API or automatic exchange-rate management with zero boilerplate.

[![PHP](https://img.shields.io/badge/PHP-8.1%2B-blue?logo=php)](https://php.net)
[![Laravel](https://img.shields.io/badge/Laravel-9%20|%2010%20|%2011-red?logo=laravel)](https://laravel.com)
[![License](https://img.shields.io/badge/License-MIT-green)](LICENSE)
[![Stars](https://img.shields.io/github/stars/shubham-ynr/laravel-traits?style=social)](https://github.com/shubham-ynr/laravel-traits)
[![Issues](https://img.shields.io/github/issues/shubham-ynr/laravel-traits)](https://github.com/shubham-ynr/laravel-traits/issues)
[![Last Commit](https://img.shields.io/github/last-commit/shubham-ynr/laravel-traits)](https://github.com/shubham-ynr/laravel-traits/commits/main)

Stop writing the same controller logic over and over. These traits drop straight into your Eloquent models and give you production-ready features — search, filtering, sorting, pagination, currency management, exchange rates — all driven by HTTP query parameters or simple method calls.

**Share this repo:**
[![Share on Twitter](https://img.shields.io/badge/Share-Twitter-1DA1F2?logo=twitter&logoColor=white)](https://twitter.com/intent/tweet?text=Just%20found%20this%20awesome%20Laravel%20traits%20library%20-%20QueryBuilder%20%26%20CurrencyHandler%20for%20%40laravelphp%20%F0%9F%94%A5&url=https%3A%2F%2Fgithub.com%2Fshubham-ynr%2Flaravel-traits&hashtags=laravel,php,opensource)
[![Share on LinkedIn](https://img.shields.io/badge/Share-LinkedIn-0A66C2?logo=linkedin&logoColor=white)](https://www.linkedin.com/sharing/share-offsite/?url=https%3A%2F%2Fgithub.com%2Fshubham-ynr%2Flaravel-traits)

---

## 📦 Traits Included

| Trait | Description |
|---|---|
| [`QueryBuilder`](#-querybuilder-trait) | Full query API for any model — search, filters, sorting, pagination, eager loading, soft deletes |
| [`CurrencyHandler`](#-currencyhandler-trait) | Automatic exchange-rate management — fetch, sync, convert, format, default swapping |

---

## ⚡ Quick Start

Copy the trait file(s) you need into `app/Traits/`, add the trait to your model, and you're done.

```
app/
└── Traits/
    ├── QueryBuilder.php
    └── CurrencyHandler.php
```

No service provider. No config file. No queue worker. Just `use TraitName;` on your model.

---

## 🔍 QueryBuilder Trait

> Full-featured Eloquent query API driven entirely by HTTP query parameters.

### What it does

Add `use QueryBuilder;` to any Eloquent model and your controller goes from this:

```php
// ❌ Before — repeating this in every controller
public function index(Request $request)
{
    $query = User::query();

    if ($request->search) {
        $query->where('name', 'like', "%{$request->search}%")
              ->orWhere('email', 'like', "%{$request->search}%");
    }

    if ($request->has('filters')) {
        foreach ($request->filters as $col => $val) {
            $query->where($col, $val);
        }
    }

    return $query->paginate($request->per_page ?? 15);
}
```

To this:

```php
// ✅ After — one line
public function index(Request $request)
{
    return User::QueryBuild($request)->paginateTable($request);
}
```

### Features

- 🔎 **Global search** across own columns and nested relation columns
- 🎛️ **11 filter operators** — `=`, `!=`, `like`, `in`, `between`, `null`, `not_null`, and more
- ↕️ **Multi-column sorting** with per-column direction control
- 📄 **Automatic pagination** with configurable defaults and hard caps
- 📅 **Date range filtering** on any date/datetime column
- 🔗 **Eager loading** with a strict whitelist
- 🗑️ **Soft delete control** — include or return only trashed records
- 📐 **Column selection** — return only what the client needs
- 🔒 **Whitelist-based security** — clients can only touch what you explicitly allow

### Model Setup

```php
use App\Traits\QueryBuilder;

class User extends Model
{
    use QueryBuilder;

    protected array $searchable      = ['name', 'email', 'profile.bio'];
    protected array $sortable        = ['id', 'name', 'created_at'];
    protected array $filterable      = ['status', 'roles.name'];
    protected array $allowedRelations = ['posts', 'roles', 'profile'];
}
```

### HTTP API

```
GET /api/users?search=john&filters[status]=active&sort_by=name&sort_dir=desc&per_page=25
GET /api/users?filters[age][operator]=between&filters[age][value]=18,30
GET /api/users?with[]=roles&with[]=profile&date_from=2024-01-01&date_to=2024-12-31
GET /api/users?trashed=with&columns=id,name,email
```

### Response Shape (`paginateTable`)

```json
{
  "data": [...],
  "pagination": {
    "total": 200, "per_page": 15, "current_page": 1,
    "last_page": 14, "has_more": true, "links": { ... }
  },
  "meta": {
    "applied_sorts": ["name:asc"],
    "applied_filters": { "status": "=:active" }
  }
}
```

📄 [Full QueryBuilder Documentation →](docs/QueryBuilder.md)

---

## 💱 CurrencyHandler Trait

> Automatic exchange-rate management for your Currency model — no queue worker needed.

### What it does

Add `use CurrencyHandler;` to your `Currency` model and get automatic rate fetching, syncing, default swapping, and price recalculation — all wired up through Eloquent model events.

```php
// Rates auto-fetched from API on create
Currency::create(['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€']);
Currency::create(['code' => 'GBP', 'name' => 'British Pound', 'symbol' => '£']);

// Swap default currency + cascade price recalculation
(new Currency)->defaultTo('EUR');

// Convert between any two currencies
(new Currency)->convert(100, 'USD', 'INR');   // e.g. 8312.00

// Format with locale-aware symbols
(new Currency)->format(1299.99, 'EUR', 'de_DE');   // 1.299,99 €
```

### Features

- 📡 **Auto rate fetching** on model create/update — no manual API calls
- 🔁 **Sync all rates** in one call, schedulable as a daily cron
- 🔄 **Atomic default swapping** — no dual-default race conditions
- 💸 **Price recalculation** hook — override to cascade to your products/orders
- 🌍 **3 provider support** — ExchangeRate-API, Fixer.io, Open Exchange Rates
- 🛡️ **Resilient** — retries on failure, falls back to last known rate, never overwrites with 0
- 🗃️ **Cache-backed** — configurable TTL, auto-cleared on model changes
- 🚫 **Delete protection** — throws `LogicException` when deleting the default currency

### Supported Rate Providers

| Provider | Free Tier |
|---|---|
| [ExchangeRate-API](https://www.exchangerate-api.com) | 1,500 req/month |
| [Fixer.io](https://fixer.io) | 100 req/month |
| [Open Exchange Rates](https://openexchangerates.org) | 1,000 req/month |

### Setup

```env
CURRENCY_API_KEY=your_api_key_here
CURRENCY_API_URL=https://v6.exchangerate-api.com/v6
CURRENCY_API_PROVIDER=exchangerate-api
CURRENCY_CACHE_TTL=3600
```

```php
use App\Traits\CurrencyHandler;

class Currency extends Model
{
    use CurrencyHandler;
}
```

### Scheduling Sync

```php
// routes/console.php (Laravel 11+)
Schedule::call(fn () => (new Currency)->sync())->dailyAt('00:00');
```

📄 [Full CurrencyHandler Documentation →](docs/CurrencyHandler.md)

---

## ✅ Requirements

| | |
|---|---|
| PHP | 8.1 or higher |
| Laravel | 9, 10, or 11 |
| Database | MySQL / MariaDB / PostgreSQL / SQLite |

---

## 📁 File Structure

```
laravel-traits/
├── app/
│   └── Traits/
│       ├── QueryBuilder.php
│       └── CurrencyHandler.php
├── docs/
│   ├── QueryBuilder.md
│   └── CurrencyHandler.md
└── README.md
```

---

## ⭐ Support This Project

If these traits saved you time or made your code cleaner, consider giving the repo a star — it helps others discover it and keeps me motivated to build more!

[![GitHub stars](https://img.shields.io/github/stars/shubham-ynr/laravel-traits?style=for-the-badge&logo=github&label=Star%20this%20repo)](https://github.com/shubham-ynr/laravel-traits)

> 🙌 **Liked the work? Star it, share it, or tell a fellow Laravel dev about it. It means a lot!**

---

## 🤝 Contributing

Pull requests are welcome. For major changes, please open an issue first to discuss what you'd like to change.

1. Fork the repository
2. Create your feature branch (`git checkout -b feature/improvement`)
3. Commit your changes (`git commit -m 'Add some improvement'`)
4. Push to the branch (`git push origin feature/improvement`)
5. Open a Pull Request

---

## 📄 License

[MIT](LICENSE) — free to use in personal and commercial projects.

---

## 🔖 Keywords

`laravel` `laravel-traits` `eloquent` `query-builder` `laravel-filter` `laravel-search` `laravel-pagination` `laravel-sort` `currency` `exchange-rate` `laravel-currency` `php` `php8` `laravel9` `laravel10` `laravel11` `eloquent-traits` `laravel-package` `rest-api` `api-filter`