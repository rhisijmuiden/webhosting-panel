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

    /** Section order and per-item placement (section, order, label) that override what a package registered. */
    private array $sectionOrder = [];

    private array $placements = [];

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

    /**
     * Arrange the menu centrally without touching the packages that add items:
     * the order of the sections, and for known items their section, order and label.
     * Items that are not placed keep their own section and come after the placed ones.
     */
    public function arrange(array $sectionOrder, array $placements): self
    {
        $this->sectionOrder = $sectionOrder;
        $this->placements = $placements;

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
            ->map(function ($i) {
                $place = $this->placements[$i['key']] ?? null;
                if ($place === null) {
                    $i['order'] += $this->placements ? 1000 : 0;

                    return $i;
                }

                return array_merge($i, array_filter($place, fn ($v) => $v !== null));
            })
            ->sortBy('order');

        foreach ($items as $item) {
            $patterns = $item['activePatterns'] ?: [$item['route'], preg_replace('/\.index$/', '', $item['route']).'.*'];
            $item['active'] = request()->routeIs(...$patterns);
            $sections[$item['section'] ?? ''][] = $item;
        }

        if ($this->sectionOrder) {
            $rank = array_flip($this->sectionOrder);
            uksort($sections, fn ($a, $b) => ($rank[$a] ?? 999) <=> ($rank[$b] ?? 999));
        }

        return $sections;
    }

    /** The active item with its section, for the breadcrumbs in the top bar. */
    public function active(): ?array
    {
        foreach ($this->sections() as $section => $items) {
            foreach ($items as $item) {
                if ($item['active']) {
                    return $item + ['section_label' => $section];
                }
            }
        }

        return null;
    }
}
