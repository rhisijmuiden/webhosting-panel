<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ApplyPhpSettings;
use App\Models\ActivityLog;
use App\Models\Website;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\PhpSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** PHP fine-tuning (server defaults and maximums) and extra PHP extensions per version. */
class PhpController extends Controller
{
    public function __construct(private readonly PhpSettings $php, private readonly AgentClient $agent) {}

    public function index(Request $request): View
    {
        $tab = $request->query('tab') === 'extensions' ? 'extensions' : 'settings';
        $version = in_array($request->query('php'), Website::PHP_VERSIONS, true) ? $request->query('php') : '8.4';
        $extensions = null;
        $error = null;
        if ($tab === 'extensions') {
            try {
                $extensions = $this->agent->call('php.extensions.list', ['php' => $version], 60);
            } catch (AgentException $e) {
                $error = $e->getMessage();
            }
        }

        return view('admin.php.index', [
            'tab' => $tab,
            'version' => $version,
            'server' => $this->php->server(),
            'extensions' => $extensions,
            'error' => $error,
            'q' => trim((string) $request->query('q')),
            'sitesPerVersion' => Website::selectRaw('php_version, count(*) as n')->groupBy('php_version')->pluck('n', 'php_version'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $rules = ['workers' => ['required', 'integer', 'min:1', 'max:999']];
        foreach (PhpSettings::KEYS as $key => [, , , , $lowest, $highest]) {
            $rules["$key.default"] = ['required', 'integer', "min:$lowest", "max:$highest"];
            $rules["$key.max"] = ['required', 'integer', "min:$lowest", "max:$highest", "gte:$key.default"];
        }
        $data = $request->validate($rules, [], collect(PhpSettings::KEYS)->flatMap(fn ($k, $key) => [
            "$key.default" => __($k[0]).' ('.__('default').')', "$key.max" => __($k[0]).' ('.__('maximum').')',
        ])->all());
        if ($data['upload_max_filesize']['max'] > $data['post_max_size']['max']) {
            throw ValidationException::withMessages(['post_max_size.max' => __('The maximum POST size must be at least the maximum upload size: an upload is part of the POST.')]);
        }
        $server = [];
        foreach (PhpSettings::KEYS as $key => $_) {
            $server[$key] = ['default' => (int) $data[$key]['default'], 'max' => (int) $data[$key]['max']];
        }
        $server['workers'] = (int) $data['workers'];
        $server['display_errors'] = $request->boolean('display_errors');
        $server['customer_display_errors'] = $request->boolean('customer_display_errors');
        $this->php->saveServer($server);
        ApplyPhpSettings::dispatch();

        return back()->with('status', __('PHP settings saved. The websites get the new values within a minute.'));
    }

    public function search(Request $request): JsonResponse
    {
        $q = $request->validate(['q' => ['required', 'string', 'regex:/^[A-Za-z0-9_]{2,40}$/']])['q'];
        try {
            return response()->json($this->agent->call('php.extensions.search_pecl', ['q' => $q], 90));
        } catch (AgentException $e) {
            return response()->json(['error' => ['message' => $e->getMessage()]], 502);
        }
    }

    public function install(Request $request): RedirectResponse
    {
        $data = $this->extensionInput($request) + $request->validate(['source' => ['required', Rule::in(['apt', 'pecl'])]]);

        return $this->job('php.extensions.install', $data, __('Installing :name for PHP :php…', ['name' => $data['name'], 'php' => $data['php']]));
    }

    public function remove(Request $request): RedirectResponse
    {
        $data = $this->extensionInput($request);

        return $this->job('php.extensions.remove', $data, __('Removing :name from PHP :php…', ['name' => $data['name'], 'php' => $data['php']]));
    }

    public function toggle(Request $request): RedirectResponse
    {
        $data = $this->extensionInput($request) + ['enabled' => $request->boolean('enabled')];
        try {
            $this->agent->call('php.extensions.toggle', $data, 120);
        } catch (AgentException $e) {
            return back()->withErrors(['agent' => $e->getMessage()]);
        }
        ActivityLog::record('php.extension_toggled', "PHP {$data['php']} {$data['name']}", ['enabled' => $data['enabled']]);

        return back()->with('status', $data['enabled'] ? __(':name is on for PHP :php.', $data) : __(':name is off for PHP :php.', $data));
    }

    private function extensionInput(Request $request): array
    {
        return $request->validate([
            'php' => ['required', Rule::in(Website::PHP_VERSIONS)],
            'name' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{1,40}$/'],
        ]);
    }

    private function job(string $action, array $data, string $message): RedirectResponse
    {
        try {
            $job = $this->agent->call($action, $data, 60)['job'] ?? null;
        } catch (AgentException $e) {
            return back()->withErrors(['agent' => $e->getMessage()]);
        }
        ActivityLog::record(str_replace('php.extensions.', 'php.extension_', $action), "PHP {$data['php']} {$data['name']}", $data);

        return redirect()->route('admin.php.index', ['tab' => 'extensions', 'php' => $data['php']])
            ->with('status', $message)->with('php_job', $job);
    }
}
