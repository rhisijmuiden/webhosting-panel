<?php

declare(strict_types=1);

namespace Rhis\CrmApi\Services;

use App\Services\Settings;

/** Products with webshop prices, pushed by the CRM (PUT /catalog). */
final class Catalog
{
    private const KEY = 'crm.catalog';

    public function __construct(private readonly Settings $settings) {}

    public function get(): array
    {
        $raw = $this->settings->get(self::KEY);
        $catalog = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($catalog) ? $catalog : ['shop_url' => null, 'updated_at' => null, 'products' => []];
    }

    public function put(array $catalog): array
    {
        $catalog = ['shop_url' => $catalog['shop_url'] ?? null, 'updated_at' => now()->toIso8601String(), 'products' => array_values($catalog['products'])];
        $this->settings->set(self::KEY, json_encode($catalog, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $catalog;
    }

    /** Active products, optionally of one kind (m365, plan_upgrade, ...). */
    public function products(?string $kind = null): array
    {
        return array_values(array_filter($this->get()['products'], fn ($p) => ($p['active'] ?? true) && ($kind === null || $p['kind'] === $kind)));
    }

    public function product(string $code): ?array
    {
        foreach ($this->products() as $p) {
            if ($p['code'] === $code) {
                return $p;
            }
        }

        return null;
    }

    public function shopUrl(): ?string
    {
        return $this->get()['shop_url'] ?? null;
    }
}
