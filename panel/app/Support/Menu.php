<?php

namespace App\Support;

use Illuminate\Support\Facades\Route;

/**
 * Sidebar menu registry. The panel and packages add items to it:
 *
 *   Menu::admin()->add('backups', 'Backups', 'admin.backups.index', icon: 'backup', section: 'Server', order: 40);
 */
class Menu
{
    private static array $menus = [];

    private array $items = [];

    public static function admin(): self
    {
        return self::$menus['admin'] ??= new self;
    }

    public static function client(): self
    {
        return self::$menus['client'] ??= new self;
    }

    public static function reset(): void
    {
        self::$menus = [];
    }

    public function add(
        string $key,
        string $label,
        string $route,
        string $icon = 'dot',
        ?string $section = null,
        int $order = 50,
        array $activePatterns = [],
        ?string $badge = null,
        ?\Closure $visible = null,
    ): self {
        $this->items[$key] = compact('key', 'label', 'route', 'icon', 'section', 'order', 'activePatterns', 'badge', 'visible');

        return $this;
    }

    public function remove(string $key): self
    {
        unset($this->items[$key]);

        return $this;
    }

    /** Items grouped by section, in order, without unknown routes or hidden items. */
    public function sections(): array
    {
        $sections = [];
        $items = collect($this->items)
            ->filter(fn ($i) => Route::has($i['route']))
            ->filter(fn ($i) => $i['visible'] === null || ($i['visible'])())
            ->sortBy('order');

        foreach ($items as $item) {
            $patterns = $item['activePatterns'] ?: [$item['route'], preg_replace('/\.index$/', '', $item['route']).'.*'];
            $item['active'] = request()->routeIs(...$patterns);
            $sections[$item['section'] ?? ''][] = $item;
        }

        return $sections;
    }
}
