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

    /** @var list<\Closure(array): bool> Extra checks an item must pass, such as team member access. */
    private array $filters = [];

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
        string|\Closure|null $badge = null,
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

    /** Hide items for which the check returns false (it gets the item array). */
    public function filter(\Closure $check): self
    {
        $this->filters[] = $check;

        return $this;
    }

    public function remove(string $key): self
    {
        unset($this->items[$key]);

        return $this;
    }

    /**
     * All visible items, placed and in order. An item placed with a 'parent'
     * (for example everything that lives under "Tools & settings") is left out
     * of the sidebar but still makes its parent active.
     */
    private function resolved(): array
    {
        $items = collect($this->items)
            ->filter(fn ($i) => Route::has($i['route']))
            ->filter(fn ($i) => $i['visible'] === null || ($i['visible'])())
            ->filter(fn ($i) => collect($this->filters)->every(fn ($check) => $check($i)))
            ->map(function ($i) {
                $place = $this->placements[$i['key']] ?? null;
                if ($place === null) {
                    $i['order'] += $this->placements ? 1000 : 0;

                    return $i + ['parent' => null];
                }

                return array_merge($i, ['parent' => null], array_filter($place, fn ($v) => $v !== null));
            })
            ->sortBy('order')
            ->map(function ($i) {
                $patterns = $i['activePatterns'] ?: [$i['route'], preg_replace('/\.index$/', '', $i['route']).'.*'];
                $i['active'] = request()->routeIs(...$patterns);

                return $i;
            })
            ->all();

        foreach ($items as $key => $item) {
            if ($item['parent'] !== null && $item['active'] && isset($items[$item['parent']])) {
                $items[$item['parent']]['active'] = true;
            }
        }

        return $items;
    }

    /** Items grouped by section, in order, without unknown routes, hidden items or child pages. */
    public function sections(): array
    {
        $sections = [];
        foreach ($this->resolved() as $item) {
            if ($item['parent'] !== null) {
                continue;
            }
            if ($item['badge'] instanceof \Closure) {
                $item['badge'] = rescue(fn () => ($item['badge'])(), null, false);
            }
            $sections[$item['section'] ?? ''][] = $item;
        }

        if ($this->sectionOrder) {
            $rank = array_flip($this->sectionOrder);
            uksort($sections, fn ($a, $b) => ($rank[$a] ?? 999) <=> ($rank[$b] ?? 999));
        }

        return $sections;
    }

    /** Every reachable page with the label of its section or parent, for the Ctrl/Cmd+K palette. */
    public function pages(): array
    {
        $items = $this->resolved();

        return collect($items)->map(fn ($i) => $i + [
            'context' => $i['parent'] !== null ? ($items[$i['parent']]['label'] ?? '') : ($i['section'] ?? ''),
        ])->values()->all();
    }

    /**
     * The active item for the breadcrumbs in the top bar. A child page returns
     * itself, with its parent as the crumb before it.
     */
    public function active(): ?array
    {
        $items = $this->resolved();
        $active = collect($items)->filter(fn ($i) => $i['active']);
        $item = $active->first(fn ($i) => $i['parent'] !== null) ?? $active->first();
        if ($item === null) {
            return null;
        }
        if ($item['parent'] !== null && isset($items[$item['parent']])) {
            $parent = $items[$item['parent']];

            return $item + ['section_label' => $parent['label'], 'section_route' => $parent['route']];
        }

        return $item + ['section_label' => $item['section'] ?? '', 'section_route' => null];
    }
}
