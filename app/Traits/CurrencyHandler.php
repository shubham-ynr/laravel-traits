<?php

namespace App\Traits;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * ╔══════════════════════════════════════════════════════════════════╗
 * ║                     CurrencyHandler Trait                       ║
 * ║             Multi-Provider Exchange Rate Handler                ║
 * ╚══════════════════════════════════════════════════════════════════╝
 *
 * ┌──────────────────────────────────────────────────────────────────┐
 * │ COLUMN EXPECTATIONS                                              │
 * └──────────────────────────────────────────────────────────────────┘
 *
 *   code     string   ISO 4217 e.g. "USD"
 *   rate     decimal  Exchange rate relative to the default currency
 *   default  boolean  true = system base currency
 *   symbol   string   nullable
 *   name     string   nullable
 *
 * ┌──────────────────────────────────────────────────────────────────┐
 * │ .ENV                                                             │
 * └──────────────────────────────────────────────────────────────────┘
 *
 *   CURRENCY_API_KEY=your_key
 *   CURRENCY_API_URL=https://v6.exchangerate-api.com/v6
 *   CURRENCY_API_PROVIDER=exchangerate-api   # fixer | openexchange
 *   CURRENCY_CACHE_TTL=3600
 *   CURRENCY_API_RETRIES=3
 *
 * ┌──────────────────────────────────────────────────────────────────┐
 * │ SUPPORTED PROVIDERS                                              │
 * └──────────────────────────────────────────────────────────────────┘
 *
 *   exchangerate-api → https://v6.exchangerate-api.com/v6/{key}/latest/{base}
 *   fixer            → http://data.fixer.io/api/latest?access_key={key}&base={base}
 *   openexchange     → https://openexchangerates.org/api/latest.json?app_id={key}
 *
 * ┌──────────────────────────────────────────────────────────────────┐
 * │ RECOMMENDED MIGRATION EXTRA                                      │
 * └──────────────────────────────────────────────────────────────────┘
 *
 *   // Enforce a single default at DB level (MySQL 8+ / PostgreSQL)
 *   DB::statement("CREATE UNIQUE INDEX one_default ON currencies ((1)) WHERE `default` = 1");
 */
trait CurrencyHandler
{
    // ──────────────────────────────────────────────────
    // Boot — Eloquent event hooks (no separate Observer class needed)
    // ──────────────────────────────────────────────────

    protected static function bootCurrencyHandler(): void
    {
        // ── Creating ─────────────────────────────────────────────────────────
        static::creating(function ($currency) {
            $code = strtoupper($currency->code);

            // Becoming the default → rate is always 1, demote current default
            if ($currency->{'default'}) {
                static::atomicPromote($currency);
                $currency->rate = 1.0;
                Log::info("CurrencyHandler [creating]: {$code} set as default, rate = 1.0");
                return;
            }

            // Rate already supplied — nothing to do
            if (!empty($currency->rate)) {
                return;
            }

            // Rate missing — fetch from API now, fallback to 0 on failure
            $currency->rate = static::resolveRate($code) ?? 0.0;
        });

        // ── Updating ─────────────────────────────────────────────────────────
        static::updating(function ($currency) {
            $code       = strtoupper($currency->code);
            $isDefault  = (bool) $currency->{'default'};
            $wasDefault = (bool) $currency->getOriginal('default');

            // Guard: refuse to remove default when no other default exists
            if ($wasDefault && !$isDefault) {
                $hasOther = static::where('default', true)
                    ->where('id', '!=', $currency->id)
                    ->exists();

                if (!$hasOther) {
                    $currency->{'default'} = true;
                    Log::error("CurrencyHandler [updating]: refused to remove default from {$code} — no replacement exists.");
                    return;
                }
            }

            // Promoted to default → demote current default, set rate = 1
            if ($isDefault && !$wasDefault) {
                static::atomicPromote($currency);
                $currency->rate = 1.0;
                Log::info("CurrencyHandler [updating]: {$code} promoted to default, rate = 1.0");
                return;
            }

            // Currency code changed → re-fetch rate for the new code
            if ($currency->isDirty('code')) {
                $oldCode        = strtoupper($currency->getOriginal('code'));
                $currency->rate = static::resolveRate($code, $currency->rate) ?? $currency->rate;
                Log::info("CurrencyHandler [updating]: code changed {$oldCode} → {$code}, re-fetched rate.");
                return;
            }

            // Rate explicitly cleared on a non-default currency → re-fetch
            if (!$isDefault && $currency->isDirty('rate') && empty($currency->rate)) {
                $currency->rate = static::resolveRate($code, $currency->getOriginal('rate')) ?? $currency->getOriginal('rate');
            }
        });

        // ── Created / Updated ────────────────────────────────────────────────
        static::created(fn ($currency) => $currency->clearCache());
        static::updated(fn ($currency) => $currency->clearCache());

        // ── Deleting ─────────────────────────────────────────────────────────
        static::deleting(function ($currency) {
            if ($currency->{'default'}) {
                throw new \LogicException(
                    "Cannot delete the default currency [{$currency->code}]. Set another currency as default first."
                );
            }
        });

        static::deleted(fn ($currency) => $currency->clearCache());
    }

    // ──────────────────────────────────────────────────
    // Rate Resolution — shared by creating + updating hooks
    // ──────────────────────────────────────────────────

    /**
     * Attempt to fetch a live rate from the API with retry logic.
     * Falls back to $lastKnown if every attempt fails.
     *
     * @param  string     $code       ISO 4217 target code
     * @param  float|null $lastKnown  Previous rate — returned on total failure
     * @return float|null
     */
    protected static function resolveRate(string $code, ?float $lastKnown = null): ?float
    {
        $base    = strtoupper(static::getDefaultCodeStatic());
        $code    = strtoupper($code);
        $retries = (int) env('CURRENCY_API_RETRIES', 3);

        for ($attempt = 1; $attempt <= $retries; $attempt++) {
            $rates = (new static)->fetchAll($base);

            if (!empty($rates) && isset($rates[$code])) {
                Log::info("CurrencyHandler::resolveRate: {$code} = {$rates[$code]} (attempt {$attempt})");
                return (float) $rates[$code];
            }

            Log::warning("CurrencyHandler::resolveRate: attempt {$attempt}/{$retries} failed for {$code}.");

            if ($attempt < $retries) {
                sleep(2); // brief back-off between retries
            }
        }

        // Graceful fallback — never overwrite a valid rate with 0
        if ($lastKnown !== null && $lastKnown > 0) {
            Log::warning("CurrencyHandler::resolveRate: using last known rate {$lastKnown} for {$code}.");
            return $lastKnown;
        }

        Log::error("CurrencyHandler::resolveRate: could not resolve rate for {$code}.");
        return null;
    }

    // ──────────────────────────────────────────────────
    // Fetch — single rate
    // ──────────────────────────────────────────────────

    /**
     * Get the exchange rate for $code vs the base currency.
     * Reads from cache first; hits the API on a miss.
     *
     * @param  string      $code  ISO 4217 target code, e.g. "EUR"
     * @param  string|null $base  Override base; defaults to system default
     * @return float|null
     */
    public function fetch(string $code, ?string $base = null): ?float
    {
        $base     = strtoupper($base ?? $this->defaultCode());
        $code     = strtoupper($code);
        $cacheKey = "cx_rate_{$base}_{$code}";

        if (Cache::has($cacheKey)) {
            return (float) Cache::get($cacheKey);
        }

        $rates = $this->fetchAll($base);

        return isset($rates[$code]) ? (float) $rates[$code] : null;
    }

    /**
     * Fetch all exchange rates for a given base currency.
     * Returns ['USD' => 1.0, 'EUR' => 0.92, ...]
     *
     * @param  string|null $base
     * @return array<string, float>
     */
    public function fetchAll(?string $base = null): array
    {
        $base     = strtoupper($base ?? $this->defaultCode());
        $cacheKey = "cx_all_{$base}";
        $ttl      = (int) env('CURRENCY_CACHE_TTL', 3600);

        return Cache::remember($cacheKey, $ttl, function () use ($base) {
            $provider = strtolower(env('CURRENCY_API_PROVIDER', 'exchangerate-api'));
            $key      = env('CURRENCY_API_KEY');
            $url      = env('CURRENCY_API_URL');

            if (empty($key)) {
                Log::error('CurrencyHandler::fetchAll – CURRENCY_API_KEY is not set.');
                return [];
            }

            try {
                return match ($provider) {
                    'fixer'        => $this->callFixer($key, $url, $base),
                    'openexchange' => $this->callOpenExchange($key, $url, $base),
                    default        => $this->callExchangeRateApi($key, $url, $base),
                };
            } catch (\Throwable $e) {
                Log::error("CurrencyHandler::fetchAll – {$e->getMessage()}");
                return [];
            }
        });
    }

    // ──────────────────────────────────────────────────
    // Sync — refresh every stored currency's rate from the API
    // ──────────────────────────────────────────────────

    /**
     * Pull fresh rates from the API and update every stored currency.
     *
     * @param  string|null $base  Override base; defaults to system default
     * @return array{updated:int, skipped:int, failed:int}
     */
    public function sync(?string $base = null): array
    {
        $base  = strtoupper($base ?? $this->defaultCode());
        $stats = ['updated' => 0, 'skipped' => 0, 'failed' => 0];

        $this->clearCache($base);

        $rates = $this->fetchAll($base);

        if (empty($rates)) {
            Log::error("CurrencyHandler::sync – aborting, no rates fetched for base {$base}.");
            return $stats;
        }

        DB::beginTransaction();

        try {
            foreach ($this->allCurrencies() as $currency) {
                $c = strtoupper($currency->code);

                if ($c === $base) {
                    $currency->rate = 1.0;
                } elseif (array_key_exists($c, $rates)) {
                    $currency->rate = (float) $rates[$c];
                } else {
                    Log::warning("CurrencyHandler::sync – {$c} not in API response, skipping.");
                    $stats['skipped']++;
                    continue;
                }

                $currency->saveQuietly();
                $stats['updated']++;
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error("CurrencyHandler::sync – {$e->getMessage()}");
            $stats['failed']++;
        }

        Log::info('CurrencyHandler::sync complete.', $stats);

        return $stats;
    }

    // ──────────────────────────────────────────────────
    // Default To — change the default (base) currency
    // ──────────────────────────────────────────────────

    /**
     * Atomically swap the default currency, recalculate all stored rates,
     * and optionally cascade to price tables via recalcPrices().
     *
     * @param  string $code          ISO 4217 code for the new default
     * @param  bool   $recalcPrices  Whether to call recalcPrices() after the swap
     * @return bool
     */
    public function defaultTo(string $code, bool $recalcPrices = true): bool
    {
        $newCode = strtoupper($code);
        $oldCode = strtoupper($this->defaultCode());

        if ($newCode === $oldCode) {
            Log::info("CurrencyHandler::defaultTo – {$newCode} is already the default.");
            return true;
        }

        $this->clearCache($oldCode);
        $this->clearCache($newCode);

        $rates = $this->fetchAll($newCode);

        if (empty($rates)) {
            Log::error("CurrencyHandler::defaultTo – could not fetch rates for {$newCode}.");
            return false;
        }

        try {
            DB::transaction(function () use ($newCode, $rates) {
                $currencies = $this->currencyModel()::lockForUpdate()->get();

                foreach ($currencies as $currency) {
                    $c = strtoupper($currency->code);
                    $currency->{'default'} = ($c === $newCode);
                    $currency->rate        = ($c === $newCode)
                        ? 1.0
                        : (float) ($rates[$c] ?? $currency->rate);

                    $currency->saveQuietly();
                }
            });
        } catch (\Throwable $e) {
            Log::error("CurrencyHandler::defaultTo – transaction failed: {$e->getMessage()}");
            return false;
        }

        if ($recalcPrices) {
            $this->recalcPrices($oldCode, $newCode);
        }

        Log::info("CurrencyHandler::defaultTo – changed {$oldCode} → {$newCode}.");

        return true;
    }

    // ──────────────────────────────────────────────────
    // Convert & Format
    // ──────────────────────────────────────────────────

    /**
     * Convert an amount between two currencies using stored rates.
     */
    public function convert(float $amount, string $from, string $to, int $decimals = 2): ?float
    {
        $from = strtoupper($from);
        $to   = strtoupper($to);

        if ($from === $to) {
            return round($amount, $decimals);
        }

        $model   = $this->currencyModel();
        $fromRow = $model::where('code', $from)->first();
        $toRow   = $model::where('code', $to)->first();

        if (!$fromRow || !$toRow) {
            Log::warning("CurrencyHandler::convert – unknown currency(s): {$from} / {$to}");
            return null;
        }

        $inBase = $fromRow->rate > 0 ? ($amount / $fromRow->rate) : $amount;

        return round($inBase * $toRow->rate, $decimals);
    }

    /**
     * Format an amount with the currency symbol.
     * Uses PHP's NumberFormatter (ext-intl) when available.
     */
    public function format(float $amount, string $code, string $locale = 'en_US'): string
    {
        if (class_exists(\NumberFormatter::class)) {
            $fmt = new \NumberFormatter($locale, \NumberFormatter::CURRENCY);
            return $fmt->formatCurrency($amount, strtoupper($code));
        }

        $symbol = $this->currencyModel()::where('code', strtoupper($code))->value('symbol')
                  ?? strtoupper($code);

        return $symbol . number_format($amount, 2);
    }

    // ──────────────────────────────────────────────────
    // Cache
    // ──────────────────────────────────────────────────

    /**
     * Clear cached rates for a specific base, or all bases when null.
     */
    public function clearCache(?string $base = null): void
    {
        if ($base === null) {
            foreach ($this->allCurrencies() as $currency) {
                $this->clearCache($currency->code);
            }
            return;
        }

        $base = strtoupper($base);
        Cache::forget("cx_all_{$base}");

        foreach ($this->allCurrencies() as $currency) {
            Cache::forget("cx_rate_{$base}_{$currency->code}");
        }
    }

    // ──────────────────────────────────────────────────
    // Price Hook — override in your model
    // ──────────────────────────────────────────────────

    /**
     * Called after defaultTo() when $recalcPrices is true.
     * Override this to cascade price changes to product / order tables.
     *
     * Example:
     *   protected function recalcPrices(string $old, string $new): void
     *   {
     *       $rate = Currency::where('code', $new)->value('rate');
     *       Product::query()->chunkById(500, function ($products) use ($rate) {
     *           foreach ($products as $product) {
     *               $product->price = round($product->price * $rate, 2);
     *               $product->saveQuietly();
     *           }
     *       });
     *   }
     */
    protected function recalcPrices(string $old, string $new): void
    {
        // Stub — override to implement your price cascade logic.
    }

    // ──────────────────────────────────────────────────
    // Internals
    // ──────────────────────────────────────────────────

    protected static function atomicPromote(mixed $currency): void
    {
        DB::transaction(function () use ($currency) {
            static::where('default', true)
                ->lockForUpdate()
                ->when($currency->exists, fn ($q) => $q->where('id', '!=', $currency->id))
                ->update(['default' => false]);
        });
    }

    protected function allCurrencies(): \Illuminate\Support\Collection
    {
        return $this->currencyModel()::all();
    }

    protected function defaultCode(): string
    {
        return static::getDefaultCodeStatic();
    }

    protected static function getDefaultCodeStatic(): string
    {
        $table = (new static)->getTable();
        return strtoupper(
            DB::table($table)->where('default', true)->value('code') ?? 'USD'
        );
    }

    /**
     * Override to point to a different model class.
     * @return class-string
     */
    protected function currencyModel(): string
    {
        return static::class;
    }

    // ──────────────────────────────────────────────────
    // Provider Adapters
    // ──────────────────────────────────────────────────

    /** @throws \RuntimeException */
    private function callExchangeRateApi(string $key, string $url, string $base): array
    {
        $res = Http::timeout(10)->get(rtrim($url, '/') . "/{$key}/latest/{$base}");
        if ($res->failed()) throw new \RuntimeException("ExchangeRate-API HTTP {$res->status()}");
        $json = $res->json();
        if (($json['result'] ?? '') !== 'success') throw new \RuntimeException($json['error-type'] ?? 'unknown');
        return $json['conversion_rates'] ?? [];
    }

    /** @throws \RuntimeException */
    private function callFixer(string $key, string $url, string $base): array
    {
        $res = Http::timeout(10)->get(rtrim($url, '/') . "/latest?access_key={$key}&base={$base}");
        if ($res->failed()) throw new \RuntimeException("Fixer HTTP {$res->status()}");
        $json = $res->json();
        if (!($json['success'] ?? false)) throw new \RuntimeException($json['error']['info'] ?? 'unknown');
        return $json['rates'] ?? [];
    }

    /** @throws \RuntimeException */
    private function callOpenExchange(string $key, string $url, string $base): array
    {
        $res = Http::timeout(10)->get(rtrim($url, '/') . "/latest.json?app_id={$key}&base={$base}");
        if ($res->failed()) throw new \RuntimeException("OpenExchange HTTP {$res->status()}");
        $json = $res->json();
        if (!empty($json['error'])) throw new \RuntimeException($json['description'] ?? 'unknown');
        return $json['rates'] ?? [];
    }
}