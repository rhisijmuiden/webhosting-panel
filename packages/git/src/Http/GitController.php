<?php

declare(strict_types=1);

namespace Rhis\Git\Http;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Website;
use App\Services\Agent\AgentException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Rhis\Git\Deployer;
use Rhis\Git\Models\GitDeployment;
use Rhis\Git\Models\GitRepository;

/** The screens admins and customers share; the subclasses decide who may see which repository. */
abstract class GitController extends Controller
{
    /** 'admin' or 'client': route names, layout and views. */
    protected string $area;

    public function __construct(protected readonly Deployer $deployer) {}

    /** The customer whose websites this user may use (null = all, for admins). */
    abstract protected function customerId(Request $request): ?int;

    protected function route(string $name, mixed $params = []): string
    {
        return route("{$this->area}.git.{$name}", $params);
    }

    protected function authorizeRepo(Request $request, GitRepository $repo): void
    {
        $id = $this->customerId($request);
        abort_unless($repo->website && ($id === null || $repo->website->customer_id === $id), 404);
    }

    protected function websites(Request $request): Collection
    {
        return Website::query()->where('status', '!=', 'removing')
            ->when($this->customerId($request), fn ($q, $id) => $q->where('customer_id', $id))
            ->orderBy('domain')->get();
    }

    protected function user(Request $request): ?string
    {
        return $request->user()?->name;
    }

    public function index(Request $request): View
    {
        $repos = GitRepository::with(['website.customer', 'deployments' => fn ($q) => $q->limit(1)])
            ->when($this->customerId($request), fn ($q, $id) => $q->forCustomer($id))
            ->get()->sortBy(fn ($r) => $r->label())->values();
        $recent = GitDeployment::with('repository.website')
            ->whereIn('git_repository_id', $repos->pluck('id'))->latest('id')->limit(10)->get();

        return view("git::{$this->area}.index", ['repos' => $repos, 'recent' => $recent, 'area' => $this->area]);
    }

    public function create(Request $request): View
    {
        $websites = $this->websites($request);
        abort_if($websites->isEmpty() && $this->area === 'client', 404);
        $website = $websites->firstWhere('id', (int) $request->query('website')) ?? $websites->first();

        return view("git::{$this->area}.create", [
            'websites' => $websites, 'website' => $website, 'area' => $this->area,
            'defaults' => $websites->mapWithKeys(fn (Website $w) => [$w->id => [
                'path' => Deployer::defaultPath($w), 'shared' => implode("\n", Deployer::defaultShared($w)),
                'steps' => collect(Deployer::defaultSteps($w))->pluck('type')->all(),
            ]]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $websites = $this->websites($request);
        $data = $request->validate(['website_id' => ['required', 'integer', Rule::in($websites->pluck('id'))]] + $this->rules());
        $website = $websites->firstWhere('id', (int) $data['website_id']);
        if (GitRepository::where('website_id', $website->id)->count() >= (int) config('git.max_per_website', 5)) {
            return back()->withInput()->withErrors(['url' => __('This website already has the maximum number of repositories.')]);
        }
        if ($this->pathTaken($website, $data['deploy_path'])) {
            return back()->withInput()->withErrors(['deploy_path' => __('Another repository already deploys to this folder.')]);
        }
        try {
            $repo = $this->deployer->create($website, $this->settings($data));
        } catch (AgentException $e) {
            return back()->withInput()->withErrors(['agent' => __($e->getMessage())]);
        }
        ActivityLog::record('git.create', $repo->label(), ['url' => $repo->url, 'branch' => $repo->branch], $website->customer_id);

        return redirect($this->route('show', $repo))->with('git_new', true)
            ->with('status', __('Repository linked. Add the deploy key and the webhook to your repository, then deploy.'));
    }

    public function show(Request $request, GitRepository $repo): View
    {
        $this->authorizeRepo($request, $repo);
        $status = null;
        $agentErrors = [];
        try {
            $status = $this->deployer->status($repo);
        } catch (AgentException $e) {
            $agentErrors[] = $e->getMessage();
        }
        $deployments = $repo->deployments()->limit(15)->get();
        foreach ($deployments->where('status', 'running') as $d) {
            $this->deployer->follow($d->setRelation('repository', $repo));
        }

        return view("git::{$this->area}.show", [
            'repo' => $repo->refresh(), 'status' => $status, 'deployments' => $repo->deployments()->limit(15)->get(),
            'agentErrors' => $agentErrors, 'area' => $this->area, 'branches' => session('git_branches'),
            'commandsAllowed' => $this->deployer->commandsAllowed($repo),
        ]);
    }

    public function update(Request $request, GitRepository $repo): RedirectResponse
    {
        $this->authorizeRepo($request, $repo);
        $data = $request->validate($this->rules());
        if ($this->pathTaken($repo->website, $data['deploy_path'], $repo->id)) {
            return back()->withInput()->withErrors(['deploy_path' => __('Another repository already deploys to this folder.')]);
        }
        $settings = $this->settings($data, $repo);
        if ($repo->deployed_commit && ($settings['mode'] !== $repo->mode || $settings['deploy_path'] !== $repo->deploy_path)) {
            return back()->withInput()->withErrors(['mode' => __('The folder and the deploy mode cannot change after the first deployment. Remove the repository and link it again to change them.')]);
        }
        $repo->fill($settings);
        $repo->provider = GitRepository::providerFor($repo->url);
        $repo->save();
        ActivityLog::record('git.update', $repo->label(), ['branch' => $repo->branch, 'auto_deploy' => $repo->auto_deploy], $repo->website->customer_id);

        return back()->with('status', __('Settings saved.'));
    }

    public function check(Request $request, GitRepository $repo): RedirectResponse
    {
        $this->authorizeRepo($request, $repo);
        try {
            $out = $this->deployer->check($repo);
        } catch (AgentException $e) {
            return back()->withErrors(['agent' => __($e->getMessage())]);
        }
        $names = collect($out['branches'] ?? [])->pluck('name');
        $msg = $names->contains($repo->branch)
            ? __('Connection works. Branch :branch found.', ['branch' => $repo->branch])
            : __('Connection works, but the branch :branch does not exist. Choose one of: :list', ['branch' => $repo->branch, 'list' => $names->take(10)->implode(', ')]);

        return back()->with($names->contains($repo->branch) ? 'status' : 'warning', $msg)->with('git_branches', $names->all());
    }

    /** Branches of the repository, for the branch field (JSON). */
    public function branches(Request $request, GitRepository $repo): JsonResponse
    {
        $this->authorizeRepo($request, $repo);
        try {
            return response()->json($this->deployer->check($repo));
        } catch (AgentException $e) {
            return response()->json(['error' => __($e->getMessage())], 422);
        }
    }

    public function fetch(Request $request, GitRepository $repo): RedirectResponse
    {
        $this->authorizeRepo($request, $repo);
        try {
            $latest = $this->deployer->fetchLatest($repo);
        } catch (AgentException $e) {
            return back()->withErrors(['agent' => __($e->getMessage())]);
        }

        return back()->with('status', $repo->refresh()->hasNewCommits()
            ? __('New commit on :branch: :commit', ['branch' => $repo->branch, 'commit' => ($latest['short'] ?? '').' '.($latest['message'] ?? '')])
            : __('No new commits. The website runs the newest version.'));
    }

    public function deploy(Request $request, GitRepository $repo): RedirectResponse
    {
        $this->authorizeRepo($request, $repo);
        $data = $request->validate(['commit' => ['nullable', 'regex:/^[0-9a-f]{40}$/']]);
        $deployment = $this->deployer->deploy($repo, 'manual', $this->user($request), $data['commit'] ?? null);
        ActivityLog::record('git.deploy', $repo->label(), ['branch' => $repo->branch] + array_filter(['commit' => $data['commit'] ?? null]), $repo->website->customer_id);
        if ($deployment->status === 'failed') {
            return back()->withErrors(['agent' => $deployment->error]);
        }

        return redirect($this->route('deployments.show', [$repo, $deployment]));
    }

    public function rollback(Request $request, GitRepository $repo): RedirectResponse
    {
        $this->authorizeRepo($request, $repo);
        $data = $request->validate(['release' => ['required', 'regex:/^\d{14}-[0-9a-f]{7,40}$/']]);
        try {
            $this->deployer->rollback($repo, $data['release'], $this->user($request));
        } catch (AgentException $e) {
            return back()->withErrors(['agent' => __($e->getMessage())]);
        }
        ActivityLog::record('git.rollback', $repo->label(), $data, $repo->website->customer_id);

        return back()->with('status', __('The website runs release :release again.', ['release' => $data['release']]));
    }

    public function rotateKey(Request $request, GitRepository $repo): RedirectResponse
    {
        $this->authorizeRepo($request, $repo);
        try {
            $this->deployer->rotateKey($repo);
        } catch (AgentException $e) {
            return back()->withErrors(['agent' => __($e->getMessage())]);
        }
        ActivityLog::record('git.rotate_key', $repo->label(), [], $repo->website->customer_id);

        return back()->with('warning', __('A new deploy key was made. Replace the old key in your repository, otherwise the next deployment fails.'));
    }

    public function rotateSecret(Request $request, GitRepository $repo): RedirectResponse
    {
        $this->authorizeRepo($request, $repo);
        $this->deployer->rotateSecret($repo);
        ActivityLog::record('git.rotate_secret', $repo->label(), [], $repo->website->customer_id);

        return back()->with('warning', __('A new webhook secret was made. Update it in the webhook settings of your repository.'));
    }

    public function destroy(Request $request, GitRepository $repo): RedirectResponse
    {
        $this->authorizeRepo($request, $repo);
        abort_if($repo->deployments()->where('status', 'running')->exists(), 409, __('Wait until the deployment is finished.'));
        $label = $repo->label();
        $customer = $repo->website->customer_id;
        try {
            $this->deployer->remove($repo);
        } catch (AgentException $e) {
            return back()->withErrors(['agent' => __($e->getMessage())]);
        }
        ActivityLog::record('git.remove', $label, [], $customer);

        return redirect($this->route('index'))->with('status', __('Repository removed. The files of the website were kept.'));
    }

    public function deployment(Request $request, GitRepository $repo, GitDeployment $deployment): View|JsonResponse
    {
        $this->authorizeRepo($request, $repo);
        abort_unless($deployment->git_repository_id === $repo->id, 404);
        $deployment->setRelation('repository', $repo);
        $this->deployer->follow($deployment);
        $deployment->refresh();

        if ($request->wantsJson()) {
            return response()->json([
                'status' => $deployment->status, 'log' => $deployment->log ?? [], 'error' => $deployment->error,
                'finished' => $deployment->isFinished(),
            ]);
        }

        return view("git::{$this->area}.deployment", ['repo' => $repo, 'deployment' => $deployment, 'area' => $this->area]);
    }

    // ------------------------------------------------------------- input

    protected function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:80'],
            'url' => ['required', 'string', 'max:400', function ($attr, $value, $fail) {
                $host = '[A-Za-z0-9](?:[A-Za-z0-9-]{0,62})(?:\.[A-Za-z0-9-]{1,63})*';
                $path = '[A-Za-z0-9_~][A-Za-z0-9._~\/-]{0,255}';
                $ok = preg_match("#^https://{$host}(?::\d{1,5})?/{$path}$#", $value)
                    || preg_match("#^ssh://[A-Za-z0-9._-]{1,64}@{$host}(?::\d{1,5})?/{$path}$#", $value)
                    || preg_match("#^[A-Za-z0-9._-]{1,64}@{$host}:{$path}$#", $value);
                if (! $ok || str_contains($value, '..')) {
                    $fail(__('Use the SSH address (git@github.com:user/repo.git) or the https:// address of the repository.'));
                }
            }],
            'branch' => ['required', 'string', 'max:200', 'regex:/^[A-Za-z0-9_][A-Za-z0-9._\/-]*$/', 'not_regex:/\.\.|\/\/|\.lock$|\/$|@\{/'],
            'deploy_path' => ['required', 'string', 'max:200', 'regex:/^\/?[A-Za-z0-9_.][A-Za-z0-9_.-]*(\/[A-Za-z0-9_.][A-Za-z0-9_.-]*)*\/?$/',
                'not_regex:/(^|\/)\.\.?(\/|$)/', 'not_regex:/^\/?(git|logs|tmp|sessions|\.ssh|\.config|\.cache)(\/|$)/'],
            'mode' => ['required', Rule::in(['direct', 'releases'])],
            'auto_deploy' => ['sometimes', 'boolean'],
            'steps' => ['sometimes', 'array'],
            'steps.*' => [Rule::in(config('git.steps'))],
            'command' => ['nullable', 'string', 'max:4000'],
            'shared' => ['nullable', 'string', 'max:2000'],
            'keep_releases' => ['nullable', 'integer', 'min:1', 'max:20'],
        ];
    }

    protected function settings(array $data, ?GitRepository $repo = null): array
    {
        $steps = collect(config('git.steps'))->filter(fn ($s) => in_array($s, $data['steps'] ?? [], true))
            ->map(fn ($s) => ['type' => $s])->values();
        $command = trim(str_replace("\r\n", "\n", (string) ($data['command'] ?? '')));
        $website = $repo?->website ?? Website::find($data['website_id'] ?? null);
        $allowed = $repo ? $this->deployer->commandsAllowed($repo) : ((bool) config('git.customer_commands') || $this->area === 'admin');
        if ($command !== '' && $allowed) {
            $steps->push(['type' => 'command', 'command' => $command]);
        } elseif ($repo && ! $allowed && $repo->customCommand() !== '') {
            $steps->push(['type' => 'command', 'command' => $repo->customCommand()]); // keep what an admin set
        }
        $shared = collect(preg_split('/[\r\n,]+/', (string) ($data['shared'] ?? '')))->map(fn ($s) => trim($s))
            ->filter(fn ($s) => $s !== '' && preg_match('#^[A-Za-z0-9_.][A-Za-z0-9_./-]*$#', $s) && ! preg_match('#(^|/)\.\.?(/|$)#', rtrim($s, '/')))
            ->unique()->take(30)->values()->all();
        $url = trim($data['url']);

        return [
            'name' => trim((string) ($data['name'] ?? '')) ?: (preg_match('#([^/:]+?)(?:\.git)?$#', $url, $m) ? $m[1] : 'repository'),
            'url' => $url, 'branch' => $data['branch'], 'deploy_path' => trim($data['deploy_path'], '/'),
            'mode' => $data['mode'], 'auto_deploy' => (bool) ($data['auto_deploy'] ?? false),
            'steps' => $steps->all(), 'shared' => $shared,
            'keep_releases' => (int) ($data['keep_releases'] ?? $repo?->keep_releases ?? config('git.keep_releases', 5)),
        ];
    }

    private function pathTaken(Website $website, string $path, ?int $except = null): bool
    {
        $path = trim($path, '/');

        return GitRepository::where('website_id', $website->id)->when($except, fn ($q) => $q->whereKeyNot($except))->get()
            ->contains(fn ($r) => $r->deploy_path === $path || str_starts_with($path.'/', $r->deploy_path.'/') || str_starts_with($r->deploy_path.'/', $path.'/'));
    }
}
