<?php

declare(strict_types=1);

namespace Rhis\Apps\PluginSets;

use App\Models\HostingPackage;
use App\Models\Website;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;

/**
 * A WordPress plugin set: plugins and themes (wordpress.org slugs or https zip links)
 * that the app installer puts on a new WordPress or WooCommerce site.
 * A package can start its sites with one set by default.
 *
 * @property array<int, array{source: string, activate: bool, name?: string}> $plugins
 * @property array<int, array{source: string, activate: bool, name?: string}> $themes
 */
final class PluginSet extends Model
{
    /** The apps a set can be used with. */
    public const APPS = ['wordpress', 'woocommerce'];

    public const MAX_PLUGINS = 40;

    public const MAX_THEMES = 10;

    protected $table = 'wp_plugin_sets';

    protected $fillable = ['name', 'description', 'plugins', 'themes', 'remove_default_content', 'remove_inactive_themes', 'customer_visible'];

    protected function casts(): array
    {
        return [
            'plugins' => 'array', 'themes' => 'array',
            'remove_default_content' => 'boolean', 'remove_inactive_themes' => 'boolean', 'customer_visible' => 'boolean',
        ];
    }

    public function packages(): BelongsToMany
    {
        return $this->belongsToMany(HostingPackage::class, 'wp_plugin_set_packages', 'wp_plugin_set_id', 'hosting_package_id');
    }

    /** The default set of the website's package, if any. */
    public static function defaultFor(?Website $website): ?self
    {
        $package = $website?->package?->id;

        return $package ? self::whereHas('packages', fn (Builder $q) => $q->whereKey($package))->first() : null;
    }

    /** @return array<int, int> package id => set id */
    public static function defaultsByPackage(): array
    {
        return DB::table('wp_plugin_set_packages')->pluck('wp_plugin_set_id', 'hosting_package_id')
            ->map(fn ($id) => (int) $id)->all();
    }

    /**
     * The sets that can be picked for this website: every set for admins; for customers
     * the sets shown to customers plus the default of the website's package.
     */
    public static function choicesFor(Website $website, bool $admin): Collection
    {
        $query = self::query()->orderBy('name');
        if (! $admin) {
            $default = self::defaultFor($website)?->id;
            $query->where(fn (Builder $q) => $q->where('customer_visible', true)->when($default, fn ($q) => $q->orWhere('id', $default)));
        }

        return $query->get();
    }

    /** The plugin_set parameter of the agent's apps.install. */
    public function toAgent(): array
    {
        $strip = fn (array $items) => array_values(array_map(
            fn (array $i) => ['source' => (string) $i['source'], 'activate' => (bool) ($i['activate'] ?? false)], $items));

        return [
            'name' => $this->name,
            'plugins' => $strip($this->plugins ?? []),
            'themes' => $strip($this->themes ?? []),
            'remove_default_content' => $this->remove_default_content,
            'remove_inactive_themes' => $this->remove_inactive_themes,
        ];
    }

    /** What to show for one item: its wordpress.org name, the slug, or the file name of a link. */
    public static function label(array $item): string
    {
        if (! empty($item['name'])) {
            return (string) $item['name'];
        }
        $source = (string) ($item['source'] ?? '');
        if (str_starts_with($source, 'https://')) {
            return preg_replace('/\.zip$/i', '', basename((string) parse_url($source, PHP_URL_PATH))) ?: $source;
        }

        return $source;
    }

    public static function isLink(string $source): bool
    {
        return str_starts_with($source, 'https://');
    }
}
