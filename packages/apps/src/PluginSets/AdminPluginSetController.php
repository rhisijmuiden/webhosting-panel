<?php

declare(strict_types=1);

namespace Rhis\Apps\PluginSets;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\HostingPackage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Rhis\Apps\Models\AppInstallation;

/** Admin: the WordPress plugin sets that the app installer can put on new sites. */
final class AdminPluginSetController extends Controller
{
    public const SLUG = '/^[a-z0-9][a-z0-9_-]{0,99}$/';

    public const LINK = '#^https://[A-Za-z0-9.-]+(:[0-9]{1,5})?/[^\s"\'<>\\\\]{1,480}$#';

    public function __construct(private readonly WordPressDirectory $directory) {}

    public function index(): View
    {
        $sets = PluginSet::with('packages')->orderBy('name')->get();
        $used = AppInstallation::whereNotNull('plugin_set_id')->selectRaw('plugin_set_id, count(*) as n')
            ->groupBy('plugin_set_id')->pluck('n', 'plugin_set_id');

        return view('apps::plugin-sets.index', ['sets' => $sets, 'used' => $used]);
    }

    public function create(): View
    {
        return $this->form(new PluginSet([
            'plugins' => [], 'themes' => [], 'customer_visible' => true,
            'remove_default_content' => true, 'remove_inactive_themes' => true,
        ]));
    }

    public function store(Request $request): RedirectResponse
    {
        [$data, $packages] = $this->validated($request);
        [$data, $unknown] = $this->withNames($data);
        $set = DB::transaction(function () use ($data, $packages) {
            $set = PluginSet::create($data);
            $this->assignPackages($set, $packages);

            return $set;
        });
        ActivityLog::record('plugin_set.created', $set->name);

        return $this->saved($set, $unknown, __('Plugin set :name created.', ['name' => $set->name]));
    }

    public function edit(PluginSet $set): View
    {
        return $this->form($set->load('packages'));
    }

    public function update(Request $request, PluginSet $set): RedirectResponse
    {
        [$data, $packages] = $this->validated($request);
        [$data, $unknown] = $this->withNames($data, $set);
        DB::transaction(function () use ($set, $data, $packages) {
            $set->update($data);
            $this->assignPackages($set, $packages);
        });
        ActivityLog::record('plugin_set.updated', $set->name);

        return $this->saved($set, $unknown, __('Plugin set :name saved.', ['name' => $set->name]));
    }

    public function destroy(PluginSet $set): RedirectResponse
    {
        $set->delete();
        ActivityLog::record('plugin_set.deleted', $set->name);

        return redirect()->route('admin.apps.sets.index')->with('status', __('Plugin set :name deleted.', ['name' => $set->name]));
    }

    private function form(PluginSet $set): View
    {
        $others = PluginSet::with('packages')->whereKeyNot($set->id ?? 0)->get()
            ->flatMap(fn (PluginSet $s) => $s->packages->mapWithKeys(fn ($p) => [$p->id => $s->name]));

        return view('apps::plugin-sets.form', [
            'set' => $set,
            'packages' => HostingPackage::orderBy('name')->get(),
            'taken' => $others, // package id => name of the set it uses now
        ]);
    }

    /** @return array{0: array, 1: list<int>} */
    private function validated(Request $request): array
    {
        // Rows the admin left empty are not an error. Keys stay as posted, so theme_active
        // and the error messages point at the row the admin sees.
        $clean = fn (string $key) => array_filter((array) $request->input($key, []),
            fn ($row) => is_array($row) && trim((string) ($row['source'] ?? '')) !== '');
        $input = ['plugins' => $clean('plugins'), 'themes' => $clean('themes')] + $request->all();

        $source = ['required', 'string', 'max:500', function (string $attr, mixed $value, \Closure $fail) {
            $value = (string) $value;
            if (preg_match(self::SLUG, $value)) {
                return;
            }
            if (preg_match(self::LINK, $value) && str_ends_with(strtolower((string) parse_url($value, PHP_URL_PATH)), '.zip')) {
                return;
            }
            $fail(__('Use the slug from wordpress.org (for example wordfence) or an https link to a .zip file.'));
        }];

        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:500'],
            'plugins' => ['array', 'max:'.PluginSet::MAX_PLUGINS],
            'plugins.*.source' => $source,
            'plugins.*.activate' => ['nullable', 'boolean'],
            'themes' => ['array', 'max:'.PluginSet::MAX_THEMES],
            'themes.*.source' => $source,
            'theme_active' => ['nullable', 'integer', 'min:-1'],
            'remove_default_content' => ['nullable', 'boolean'],
            'remove_inactive_themes' => ['nullable', 'boolean'],
            'customer_visible' => ['nullable', 'boolean'],
            'packages' => ['array'],
            'packages.*' => ['integer', 'exists:hosting_packages,id'],
        ])->validate();

        $unique = function (array $rows): array {
            $out = [];
            foreach ($rows as $i => $row) {
                $out[strtolower(trim((string) $row['source']))] ??= ['source' => trim((string) $row['source']), 'activate' => (bool) ($row['activate'] ?? false), 'i' => $i];
            }

            return array_values($out);
        };
        $active = (string) ($data['theme_active'] ?? '-1');
        $themes = array_map(fn ($t) => ['source' => $t['source'], 'activate' => (string) $t['i'] === $active], $unique($data['themes'] ?? []));
        $plugins = array_map(fn ($p) => ['source' => $p['source'], 'activate' => $p['activate']], $unique($data['plugins'] ?? []));

        if ($plugins === [] && $themes === []) {
            throw ValidationException::withMessages(['plugins' => __('Add at least one plugin or theme.')]);
        }

        return [[
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'plugins' => $plugins,
            'themes' => $themes,
            'remove_default_content' => $request->boolean('remove_default_content'),
            'remove_inactive_themes' => $request->boolean('remove_inactive_themes'),
            'customer_visible' => $request->boolean('customer_visible'),
        ], array_map('intval', $data['packages'] ?? [])];
    }

    /**
     * Add the wordpress.org names to the items and list the slugs wordpress.org does not know.
     *
     * @return array{0: array, 1: list<string>}
     */
    private function withNames(array $data, ?PluginSet $old = null): array
    {
        $known = [];
        foreach (['plugins', 'themes'] as $key) {
            foreach ($old?->{$key} ?? [] as $item) {
                if (! empty($item['name'])) {
                    $known[$key][$item['source']] = $item['name'];
                }
            }
        }
        $unknown = [];
        foreach (['plugins' => 'plugin', 'themes' => 'theme'] as $key => $type) {
            foreach ($data[$key] as $n => $item) {
                if (PluginSet::isLink($item['source'])) {
                    continue;
                }
                $name = $known[$key][$item['source']] ?? $this->directory->lookup($type, $item['source']);
                if ($name === false) {
                    $unknown[] = $item['source'];
                } elseif (is_string($name) && $name !== '') {
                    $data[$key][$n]['name'] = mb_substr($name, 0, 120);
                }
            }
        }

        return [$data, $unknown];
    }

    /** Make this set the default of these packages (a package has one default set). */
    private function assignPackages(PluginSet $set, array $packages): void
    {
        DB::table('wp_plugin_set_packages')->where('wp_plugin_set_id', $set->id)->delete();
        if ($packages === []) {
            return;
        }
        DB::table('wp_plugin_set_packages')->whereIn('hosting_package_id', $packages)->delete();
        DB::table('wp_plugin_set_packages')->insert(array_map(
            fn (int $id) => ['hosting_package_id' => $id, 'wp_plugin_set_id' => $set->id], array_values(array_unique($packages))));
    }

    private function saved(PluginSet $set, array $unknown, string $message): RedirectResponse
    {
        $res = redirect()->route('admin.apps.sets.edit', $set)->with('status', $message);

        return $unknown === [] ? $res : $res->with('warning', __('Not found on wordpress.org: :slugs. Check the spelling, or use a link to a .zip file for plugins that are not on wordpress.org.', ['slugs' => implode(', ', $unknown)]));
    }
}
