# CurrencyHandler

A single-file Laravel trait that gives your `Currency` model automatic exchange-rate management — fetching, syncing, default swapping, and price recalculation — with no queue worker required.

---

## What's inside

| Thing | Role |
|---|---|
| `trait CurrencyHandler` | All public methods + Eloquent event hooks via `bootCurrencyHandler()` |

No job class. No observer class. No queue worker. Everything runs inline.

---

## Requirements

- PHP **8.1+**
- Laravel **10+**
- An account with one of the supported rate providers

---

## Installation

### 1 — Copy the file

```
app/
└── Traits/
    └── CurrencyHandler.php
```

### 2 — Configure `.env`

```env
# Required
CURRENCY_API_KEY=your_api_key_here
CURRENCY_API_URL=https://v6.exchangerate-api.com/v6

# Optional — defaults shown
CURRENCY_API_PROVIDER=exchangerate-api   # fixer | openexchange
CURRENCY_CACHE_TTL=3600                  # seconds to cache rates
CURRENCY_API_RETRIES=3                   # retry attempts on API failure
```

#### Supported providers

| Provider | `CURRENCY_API_PROVIDER` | Free tier |
|---|---|---|
| [ExchangeRate-API](https://www.exchangerate-api.com) | `exchangerate-api` | 1 500 req/month, any base |
| [Fixer.io](https://fixer.io) | `fixer` | 100 req/month, EUR base only |
| [Open Exchange Rates](https://openexchangerates.org) | `openexchange` | 1 000 req/month, USD base only |

### 3 — Create the migration

```php
Schema::create('currencies', function (Blueprint $table) {
    $table->id();
    $table->string('code', 3)->unique();
    $table->string('name')->nullable();
    $table->string('symbol', 10)->nullable();
    $table->decimal('rate', 15, 6)->default(0);
    $table->boolean('default')->default(false);
    $table->timestamps();
});

// Recommended: enforce a single default at DB level (MySQL 8+ / PostgreSQL)
DB::statement("CREATE UNIQUE INDEX one_default ON currencies ((1)) WHERE `default` = 1");
```

### 4 — Apply the trait to your model

```php
// app/Models/Currency.php
use App\Traits\CurrencyHandler;

class Currency extends Model
{
    use CurrencyHandler;
}
```

`bootCurrencyHandler()` is called automatically by Laravel when the model boots — all event hooks are registered with no extra wiring.

---

## How the event hooks work

### Creating

```php
// Default currency — rate forced to 1.0, current default demoted atomically
Currency::create(['code' => 'USD', 'default' => true]);

// Rate supplied — used as-is, no API call
Currency::create(['code' => 'GBP', 'rate' => 0.79]);

// Rate missing — fetched from the API inline (with retries + fallback)
Currency::create(['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€']);
```

**What happens when rate is missing:**
```
creating hook fires
  └── calls resolveRate('EUR')
        ├── attempt 1: hits API → returns rate   ✓  saved
        ├── attempt 1: API fails → retries (up to CURRENCY_API_RETRIES)
        └── all retries fail + no last known rate → saves 0.0, logs error
```

### Updating

```php
// Promoted to default → rate = 1.0, old default demoted in a transaction
$currency->update(['default' => true]);

// Trying to remove default with no replacement → silently reverted, logged
$currency->update(['default' => false]);

// Code changed → rate re-fetched for the new code
$currency->update(['code' => 'AUD']);

// Rate cleared on a non-default → re-fetched from API
$currency->update(['rate' => null]);
```

### Deleting

```php
Currency::where('code', 'EUR')->first()->delete();   // fine

// Throws \LogicException — rolls back any wrapping transaction
Currency::where('default', true)->first()->delete();
// LogicException: "Cannot delete the default currency [USD]. Set another currency as default first."
```

---

## Public API

```php
$c = new Currency(); // or any Currency instance
```

### `fetch(string $code, ?string $base = null): ?float`
Get the rate for a single currency. Reads from cache, hits API on miss.
```php
$c->fetch('EUR');           // EUR vs system default
$c->fetch('EUR', 'GBP');   // EUR vs GBP
```

### `fetchAll(?string $base = null): array`
Get all rates for a base currency as `['EUR' => 0.92, ...]`.
```php
$c->fetchAll();          // vs system default
$c->fetchAll('EUR');     // ['USD' => 1.08, 'GBP' => 0.86, ...]
```

### `sync(?string $base = null): array`
Pull fresh rates from the API and update every stored currency. Returns stats.
```php
$c->sync();
// ['updated' => 5, 'skipped' => 0, 'failed' => 0]

// Schedule daily in routes/console.php (Laravel 11+)
Schedule::call(fn () => (new Currency)->sync())->dailyAt('00:00');
```

### `defaultTo(string $code, bool $recalcPrices = true): bool`
Atomically swap the default currency, update all rates, optionally cascade prices.
```php
$c->defaultTo('EUR');           // swap + recalc prices
$c->defaultTo('EUR', false);    // swap only
```

### `convert(float $amount, string $from, string $to, int $decimals = 2): ?float`
Convert between any two stored currencies using their DB rates.
```php
$c->convert(100, 'USD', 'INR');       // e.g. 8312.00
$c->convert(500, 'INR', 'GBP', 4);   // 4 decimal places
```

### `format(float $amount, string $code, string $locale = 'en_US'): string`
Format with the currency symbol. Uses `NumberFormatter` (ext-intl) when available.
```php
$c->format(1299.99, 'USD');            // $1,299.99
$c->format(1299.99, 'EUR', 'de_DE'); // 1.299,99 €
```

### `clearCache(?string $base = null): void`
Clear rate cache. Called automatically on `created`, `updated`, `deleted`.
```php
$c->clearCache('USD');   // clear USD-based keys
$c->clearCache();        // clear all
```

---

## Customisation

### `recalcPrices(string $old, string $new)` — cascade price changes

Override this in your model to update product/order prices when the default currency changes.

```php
protected function recalcPrices(string $old, string $new): void
{
    $rate = Currency::where('code', $new)->value('rate');

    Product::query()->chunkById(500, function ($products) use ($rate) {
        foreach ($products as $product) {
            $product->price = round($product->price * $rate, 2);
            $product->saveQuietly();
        }
    });
}
```

### `currencyModel()` — use a different model class

```php
protected function currencyModel(): string
{
    return \App\Models\Finance\ExchangeRate::class;
}
```

---

## Error handling & resilience

| Scenario | Behaviour |
|---|---|
| API fails on `create` (no rate supplied) | Retried up to `CURRENCY_API_RETRIES` times; saves `0.0` only if all fail |
| API fails on `update` (code/rate changed) | Falls back to the last known DB rate; never overwrites with `0` |
| Two concurrent `defaultTo()` calls | `lockForUpdate` transaction serialises them — one wins, one waits |
| Two concurrent `create` with `default = true` | `atomicPromote()` transaction + optional DB index prevent dual defaults |
| Deleting the default currency | Throws `\LogicException`, rolls back any wrapping transaction |
| Removing last default via `update` | Change silently reverted, error logged |

---

## Logging

```
INFO  CurrencyHandler [creating]: USD set as default, rate = 1.0
INFO  CurrencyHandler::resolveRate: EUR = 0.921540 (attempt 1)
INFO  CurrencyHandler [updating]: GBP promoted to default, rate = 1.0
WARN  CurrencyHandler [updating]: refused to remove default from USD — no replacement exists.
WARN  CurrencyHandler::resolveRate: using last known rate 148.5 for JPY.
ERROR CurrencyHandler [deleting]: blocked deletion of default currency USD
INFO  CurrencyHandler::sync complete. {"updated":5,"skipped":0,"failed":0}
INFO  CurrencyHandler::defaultTo – changed USD → EUR.
```

---

## Examples

### Seeding — first currency must be the default

```php
Currency::create([
    'code'    => 'USD',
    'name'    => 'US Dollar',
    'symbol'  => '$',
    'default' => true,  // rate forced to 1.0 automatically
]);
```

---

### Creating currencies

```php
// Rate auto-fetched from the API (with retries + fallback)
Currency::create(['code' => 'EUR', 'name' => 'Euro',          'symbol' => '€']);
Currency::create(['code' => 'GBP', 'name' => 'British Pound', 'symbol' => '£']);
Currency::create(['code' => 'JPY', 'name' => 'Japanese Yen',  'symbol' => '¥']);

// Rate supplied — no API call made
Currency::create(['code' => 'INR', 'name' => 'Indian Rupee', 'symbol' => '₹', 'rate' => 83.12]);
```

---

### Syncing all rates

```php
$stats = (new Currency)->sync();
// ['updated' => 5, 'skipped' => 0, 'failed' => 0]

// Sync against a specific base
(new Currency)->sync('EUR');

// Schedule daily — routes/console.php (Laravel 11+)
Schedule::call(fn () => (new Currency)->sync())->dailyAt('00:00');
```

---

### Changing the default currency

```php
// Swap default + cascade recalcPrices()
(new Currency)->defaultTo('EUR');

// Swap default only, skip price recalculation
(new Currency)->defaultTo('EUR', false);
```

---

### Fetching rates

```php
$c = new Currency();

$c->fetch('EUR');           // EUR rate vs system default
$c->fetch('EUR', 'GBP');   // EUR rate vs GBP as base

$c->fetchAll();             // all rates vs system default → ['EUR' => 0.92, ...]
$c->fetchAll('EUR');        // all rates vs EUR as base
```

---

### Converting amounts

```php
$c = new Currency();

$c->convert(100, 'USD', 'EUR');      // e.g. 92.43
$c->convert(100, 'USD', 'INR');      // e.g. 8312.00
$c->convert(1,   'USD', 'INR', 4);  // 4 decimal places → 83.1200
```

---

### Formatting amounts

```php
$c = new Currency();

$c->format(1299.99, 'USD');            // $1,299.99
$c->format(1299.99, 'EUR', 'de_DE'); // 1.299,99 €
$c->format(1299.99, 'INR', 'en_IN'); // ₹1,299.99
```

---

### Deleting a currency

```php
// Fine
Currency::where('code', 'JPY')->first()->delete();

// Throws \LogicException — rolls back any wrapping transaction
try {
    Currency::where('default', true)->first()->delete();
} catch (\LogicException $e) {
    // "Cannot delete the default currency [USD]. Set another currency as default first."
}
```

---

### Overriding `recalcPrices()` in your model

```php
class Currency extends Model
{
    use CurrencyHandler;

    protected function recalcPrices(string $old, string $new): void
    {
        $rate = static::where('code', $new)->value('rate');

        if (!$rate || $rate <= 0) {
            Log::error("recalcPrices – invalid rate for {$new}, skipping.");
            return;
        }

        // Replace Product with whatever model holds your prices
        Product::query()->chunkById(500, function ($products) use ($rate) {
            foreach ($products as $product) {
                $product->price = round($product->price * $rate, 2);
                $product->saveQuietly();
            }
        });
    }
}
```