<?php

declare(strict_types=1);

namespace Rhis\Cron\Http;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Website;
use App\Services\Agent\AgentException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Rhis\Cron\CronManager;
use Rhis\Cron\Models\CronJob;
use Rhis\Cron\Models\CronRun;
use Rhis\Cron\Schedule;

/** The screens admins and customers share; the subclasses decide who may see which job. */
abstract class CronController extends Controller
{
    /** 'admin' or 'client': route names, layout and views. */
    protected string $area;

    private const SETTINGS = ['name', 'type', 'command', 'args', 'schedule', 'timeout', 'enabled', 'notify_failure', 'notify_email'];

    public function __construct(protected readonly CronManager $cron) {}

    /** The customer whose websites this user may use (null = all, for admins). */
    abstract protected function customerId(Request $request): ?int;

    protected function route(string $name, mixed $params = []): string
    {
        return route("{$this->area}.cron.{$name}", $params);
    }

    protected function authorizeJob(Request $request, CronJob $job): void
    {
        $id = $this->customerId($request);
        abort_unless($job->website && ($id === null || $job->website->customer_id === $id), 404);
    }

    /** Websites that may get a cron job (customers: only packages that allow them). */
    protected function websites(Request $request): Collection
    {
        return Website::query()->with('customer')->whereNotIn('status', ['removing'])
            ->when($this->customerId($request), fn ($q, $id) => $q->where('customer_id', $id))
            ->orderBy('domain')->get()
            ->filter(fn (Website $w) => $this->area === 'admin' || CronManager::allowed($w))->values();
    }

    public function index(Request $request): View
    {
        $websites = $this->websites($request);
        $filter = $request->integer('website') ?: null;
        $list = CronJob::with('website.customer')
            ->when($this->customerId($request), fn ($q, $id) => $q->forCustomer($id))
            ->when($filter, fn ($q, $id) => $q->where('website_id', $id))
            ->get()->sortBy(fn (CronJob $j) => $j->website->domain.' '.$j->name)->values();

        return view("cron::{$this->area}.index", [
            'list' => $list, 'statuses' => $this->cron->statuses($list), 'area' => $this->area,
            'websites' => $websites, 'filter' => $filter, 'canCreate' => $websites->isNotEmpty(),
        ]);
    }

    public function create(Request $request): View
    {
        $websites = $this->websites($request);
        abort_if($websites->isEmpty() && $this->area === 'client', 404);
        $website = $websites->firstWhere('id', $request->integer('website')) ?? $websites->first();

        return view("cron::{$this->area}.create", [
            'websites' => $websites, 'website' => $website, 'area' => $this->area,
            'job' => new CronJob(['schedule' => '*/5 * * * *', 'type' => 'command', 'command' => '', 'name' => '']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $websites = $this->websites($request);
        $data = $request->validate(['website_id' => ['required', 'integer', Rule::in($websites->pluck('id'))]] + $this->rules());
        $website = $websites->firstWhere('id', (int) $data['website_id']);
        $max = CronManager::limit($website);
        if (CronJob::where('website_id', $website->id)->count() >= $max) {
            return back()->withInput()->withErrors(['name' => $max === 0
                ? __('The package of this website does not include cron jobs.')
                : __('This website already has the maximum number of cron jobs (:max).', ['max' => $max])]);
        }
        if ($error = $this->checkSettings($data)) {
            return back()->withInput()->withErrors($error);
        }
        $job = CronJob::create(['website_id' => $website->id, 'uid' => CronJob::newUid()] + $this->settings($request, $data));
        try {
            $this->cron->apply($job->setRelation('website', $website));
        } catch (AgentException $e) {
            $job->delete();

            return back()->withInput()->withErrors(['agent' => __($e->getMessage())]);
        }
        ActivityLog::record('cron.created', $website->domain.': '.$job->name, ['schedule' => $job->schedule], $website->customer_id);

        return redirect($this->route('show', $job))->with('status', __('Cron job saved. It runs on schedule; press Run now to try it.'));
    }

    public function show(Request $request, CronJob $cronjob): View
    {
        $this->authorizeJob($request, $cronjob);
        $agentErrors = [];
        try {
            $this->cron->syncRuns(collect([$cronjob]));
        } catch (AgentException $e) {
            $agentErrors[] = $e->getMessage();
        }
        $status = $this->cron->status($cronjob);

        return view("cron::{$this->area}.show", [
            'job' => $cronjob->refresh()->load('website.customer'), 'status' => $status, 'area' => $this->area,
            'runs' => $cronjob->runs()->limit((int) config('cron.keep_runs', 25))->get(),
            'agentErrors' => $agentErrors, 'next' => Schedule::nextRuns($cronjob->schedule, 3),
        ]);
    }

    public function update(Request $request, CronJob $cronjob): RedirectResponse
    {
        $this->authorizeJob($request, $cronjob);
        $data = $request->validate($this->rules());
        if ($error = $this->checkSettings($data)) {
            return back()->withInput()->withErrors($error);
        }
        $before = array_intersect_key($cronjob->getAttributes(), array_flip(self::SETTINGS));
        $cronjob->fill($this->settings($request, $data, $cronjob))->save();
        try {
            $this->cron->apply($cronjob);
        } catch (AgentException $e) {
            DB::table('cron_jobs')->where('id', $cronjob->id)->update($before + ['last_error' => $e->getMessage()]);

            return back()->withInput()->withErrors(['agent' => __($e->getMessage())]);
        }
        ActivityLog::record('cron.updated', $cronjob->website->domain.': '.$cronjob->name, ['schedule' => $cronjob->schedule], $cronjob->website->customer_id);

        return back()->with('status', __('Cron job saved.'));
    }

    public function toggle(Request $request, CronJob $cronjob): RedirectResponse
    {
        $this->authorizeJob($request, $cronjob);
        $cronjob->update(['enabled' => ! $cronjob->enabled]);
        try {
            $this->cron->apply($cronjob);
        } catch (AgentException $e) {
            $cronjob->update(['enabled' => ! $cronjob->enabled]);

            return back()->withErrors(['agent' => __($e->getMessage())]);
        }
        ActivityLog::record($cronjob->enabled ? 'cron.enabled' : 'cron.disabled', $cronjob->website->domain.': '.$cronjob->name, [], $cronjob->website->customer_id);

        return back()->with('status', $cronjob->enabled ? __('Cron job switched on.') : __('Cron job paused. It does not run until you switch it on.'));
    }

    public function run(Request $request, CronJob $cronjob): RedirectResponse|JsonResponse
    {
        $this->authorizeJob($request, $cronjob);
        abort_if($cronjob->website->status === 'suspended', 409, __('The website is suspended.'));
        try {
            $out = $this->cron->runNow($cronjob);
        } catch (AgentException $e) {
            return $request->expectsJson() ? response()->json(['error' => __($e->getMessage())], 502) : back()->withErrors(['agent' => __($e->getMessage())]);
        }
        ActivityLog::record('cron.run', $cronjob->website->domain.': '.$cronjob->name, [], $cronjob->website->customer_id);
        $message = ($out['started'] ?? true) ? __('The cron job runs now. The result appears below when it is done.') : __('The cron job is already running.');

        return $request->expectsJson() ? response()->json(['message' => $message, 'status' => $out])
            : back()->with('status', $message)->with('cron_started', true);
    }

    public function stop(Request $request, CronJob $cronjob): RedirectResponse
    {
        $this->authorizeJob($request, $cronjob);
        try {
            $this->cron->stop($cronjob);
        } catch (AgentException $e) {
            return back()->withErrors(['agent' => __($e->getMessage())]);
        }
        ActivityLog::record('cron.stopped', $cronjob->website->domain.': '.$cronjob->name, [], $cronjob->website->customer_id);

        return back()->with('status', __('The run was stopped.'));
    }

    /** JSON for the job page while a run is going on: running or not, and the latest runs. */
    public function poll(Request $request, CronJob $cronjob): JsonResponse
    {
        $this->authorizeJob($request, $cronjob);
        try {
            $this->cron->syncRuns(collect([$cronjob]));
        } catch (AgentException) {
        }
        $status = $this->cron->status($cronjob);
        $last = $cronjob->runs()->first();

        return response()->json([
            'running' => (bool) ($status['running'] ?? false), 'running_since' => $status['running_since'] ?? null,
            'next_run' => $status['next_run'] ?? null, 'last_run' => $last?->id,
        ]);
    }

    /** The whole output of one run, as plain text. */
    public function output(Request $request, CronJob $cronjob, int $run): Response
    {
        $this->authorizeJob($request, $cronjob);
        $row = CronRun::where('cron_job_id', $cronjob->id)->findOrFail($run);

        return response((string) $row->output, 200, [
            'Content-Type' => 'text/plain; charset=utf-8', 'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline; filename="cron-'.$cronjob->uid.'-'.$row->id.'.log"',
        ]);
    }

    /** Next runs and the description of a schedule while typing (create/edit form). */
    public function preview(Request $request): JsonResponse
    {
        $expr = (string) $request->input('schedule', '');
        $error = Schedule::error($expr);
        if ($error === null && ($gap = $this->gapError($expr))) {
            $error = $gap;
        }

        return response()->json([
            'error' => $error ? __($error, ['field' => __(Schedule::errorField($expr) ?? '')]) : null,
            'label' => $error ? null : Schedule::describe($expr),
            'next' => $error ? [] : array_map(fn ($d) => $d->isoFormat('dd D MMM HH:mm'), Schedule::nextRuns($expr, 3)),
        ]);
    }

    public function destroy(Request $request, CronJob $cronjob): RedirectResponse
    {
        $this->authorizeJob($request, $cronjob);
        $label = $cronjob->website->domain.': '.$cronjob->name;
        $customer = $cronjob->website->customer_id;
        try {
            $this->cron->remove($cronjob);
        } catch (AgentException $e) {
            return back()->withErrors(['agent' => __($e->getMessage())]);
        }
        ActivityLog::record('cron.removed', $label, [], $customer);

        return redirect($this->route('index'))->with('status', __('Cron job removed.'));
    }

    // ------------------------------------------------------------- input

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            'type' => ['required', Rule::in(array_keys(CronJob::TYPES))],
            'command' => ['required', 'string', 'max:2000', 'not_regex:/[\r\n\x00]/'],
            'args' => ['nullable', 'string', 'max:1000', 'not_regex:/[\r\n\x00]/'],
            'schedule' => ['required', 'string', 'max:100'],
            'timeout' => ['required', 'integer', Rule::in(array_keys(CronJob::TIMEOUTS))],
            'enabled' => ['boolean'],
            'notify_failure' => ['boolean'],
            'notify_email' => ['nullable', 'email', 'max:255'],
        ];
    }

    private function gapError(string $expr): ?string
    {
        $min = $this->area === 'admin' ? 1 : max(1, (int) config('cron.min_interval', 1));
        $gap = Schedule::shortestGap($expr);

        return $min > 1 && $gap !== null && $gap < $min
            ? __('Your hosting allows a cron job to run at most once every :n minutes.', ['n' => $min]) : null;
    }

    /** Checks that need more than the rules: the schedule, the PHP path and the URL. */
    private function checkSettings(array $data): ?array
    {
        $schedule = Schedule::normalize($data['schedule']);
        if ($error = Schedule::error($schedule)) {
            return ['schedule' => __($error, ['field' => __(Schedule::errorField($schedule) ?? '')])];
        }
        if ($error = $this->gapError($schedule)) {
            return ['schedule' => $error];
        }
        $command = trim($data['command']);
        if ($data['type'] === 'php') {
            $script = ltrim($command, '/');
            if (! preg_match('#^[A-Za-z0-9_.][A-Za-z0-9_.@+-]{0,99}(/[A-Za-z0-9_.][A-Za-z0-9_.@+-]{0,99}){0,15}$#', $script)
                || collect(explode('/', $script))->contains(fn ($s) => $s === '.' || $s === '..')) {
                return ['command' => __('Give the path of the PHP script inside the website folder, like public/cron.php.')];
            }
        }
        if ($data['type'] === 'url' && ! preg_match('#^https?://[^\s"\'\\\\]{1,2000}$#', $command)) {
            return ['command' => __('The URL must start with http:// or https://.')];
        }

        return null;
    }

    private function settings(Request $request, array $data, ?CronJob $job = null): array
    {
        $command = trim($data['command']);

        return [
            'name' => trim($data['name']), 'type' => $data['type'],
            'command' => $data['type'] === 'php' ? ltrim($command, '/') : $command,
            'args' => $data['type'] === 'php' && filled($data['args'] ?? null) ? trim($data['args']) : null,
            'schedule' => Schedule::normalize($data['schedule']), 'timeout' => (int) $data['timeout'],
            'enabled' => $job ? $request->boolean('enabled', $job->enabled) : $request->boolean('enabled', true),
            'notify_failure' => $request->boolean('notify_failure'),
            'notify_email' => filled($data['notify_email'] ?? null) ? trim($data['notify_email']) : null,
        ];
    }
}
