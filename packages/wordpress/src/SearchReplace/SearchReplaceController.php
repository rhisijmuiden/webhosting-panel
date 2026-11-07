<?php

declare(strict_types=1);

namespace Rhis\WordPress\SearchReplace;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Services\Agent\AgentException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Rhis\WordPress\Models\WordPressInstall;

/** Search & replace and the change-domain wizard, for admins and customers (the route name says which). */
final class SearchReplaceController extends Controller
{
    public function __construct(private readonly SearchReplacer $replacer) {}

    private function area(Request $request): string
    {
        return str_starts_with((string) $request->route()?->getName(), 'admin.') ? 'admin' : 'client';
    }

    private function authorizeInstall(Request $request, WordPressInstall $install): void
    {
        if ($this->area($request) === 'client') {
            abort_unless(config('wordpress.customer_toolkit'), 404);
            abort_unless($install->website && $install->website->customer_id === $request->user()->customer_id, 404);
        }
    }

    private function view(Request $request, string $view, array $data): View
    {
        return view("wordpress::search-replace.{$view}", $data + ['area' => $this->area($request)]);
    }

    /** Recent runs of this install, newest first. */
    private function history(WordPressInstall $install)
    {
        return SearchReplaceRun::with('user')->where('wordpress_install_id', $install->id)->latest('id')->limit(10)->get();
    }

    public function form(Request $request, WordPressInstall $install): View
    {
        $this->authorizeInstall($request, $install);
        $from = $request->integer('again') ? SearchReplaceRun::where('wordpress_install_id', $install->id)->find($request->integer('again')) : null;

        return $this->view($request, 'form', ['install' => $install, 'from' => $from?->mode === 'text' ? $from->params : [],
            'history' => $this->history($install)]);
    }

    public function preview(Request $request, WordPressInstall $install): RedirectResponse
    {
        $this->authorizeInstall($request, $install);
        $data = $request->validate([
            'search' => ['required', 'string', 'min:3', 'max:1000', 'not_regex:/^-/', 'not_regex:/[\x00\r\n]/'],
            'replace' => ['nullable', 'string', 'max:1000', 'not_regex:/^-/', 'not_regex:/[\x00\r\n]/', 'different:search'],
            'tables' => ['nullable', Rule::in(['wordpress', 'all'])],
            'skip_guid' => ['nullable', 'boolean'],
        ], [
            'search.not_regex' => __('The text may not start with a dash or contain line breaks.'),
            'replace.not_regex' => __('The text may not start with a dash or contain line breaks.'),
        ]);

        return $this->start($request, $install, ['mode' => 'text', 'search' => $data['search'], 'replace' => (string) ($data['replace'] ?? ''),
            'tables' => $data['tables'] ?? 'wordpress', 'skip_guid' => $request->boolean('skip_guid', true)]);
    }

    public function domainForm(Request $request, WordPressInstall $install): View
    {
        $this->authorizeInstall($request, $install);
        $addresses = null;
        $errors = [];
        try {
            $addresses = $this->replacer->addresses($install);
        } catch (AgentException $e) {
            $errors[] = $e->getMessage();
        }
        $aliasRoute = $this->area($request).'.domain-aliases.index';

        return $this->view($request, 'domain', ['install' => $install, 'addresses' => $addresses, 'agentErrors' => $errors,
            'currentHost' => parse_url((string) ($addresses['home'] ?? ''), PHP_URL_HOST),
            'currentScheme' => parse_url((string) ($addresses['home'] ?? ''), PHP_URL_SCHEME),
            'aliasUrl' => Route::has($aliasRoute) ? route($aliasRoute) : null,
            'history' => $this->history($install)->where('mode', 'domain')]);
    }

    public function domainPreview(Request $request, WordPressInstall $install): RedirectResponse
    {
        $this->authorizeInstall($request, $install);
        $data = $request->validate([
            'host' => ['required', 'string', 'max:253', 'regex:/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9-]{2,63}$/i'],
            'scheme' => ['required', Rule::in(['https', 'http'])],
        ]);

        return $this->start($request, $install, ['mode' => 'domain', 'host' => strtolower($data['host']), 'scheme' => $data['scheme'],
            'tables' => 'wordpress', 'skip_guid' => true]);
    }

    private function start(Request $request, WordPressInstall $install, array $params): RedirectResponse
    {
        if (SearchReplaceRun::where('wordpress_install_id', $install->id)->whereIn('status', ['previewing', 'applying'])
            ->where('updated_at', '>', now()->subHours(2))->exists()) {
            return back()->withInput()->withErrors(['agent' => __('A search & replace is already running for this site. Wait until it is finished.')]);
        }
        try {
            $run = $this->replacer->preview($install, $params, $request->user()?->id);
        } catch (AgentException $e) {
            return back()->withInput()->withErrors(['agent' => $e->getMessage()]);
        }

        return redirect()->route($this->area($request).'.wordpress.sr.show', $run);
    }

    public function show(Request $request, SearchReplaceRun $run): View
    {
        $this->authorizeInstall($request, $run->install);
        $state = null;
        $errors = [];
        try {
            $state = $this->replacer->sync($run);
        } catch (AgentException $e) {
            $errors[] = $e->getMessage();
        }

        return $this->view($request, 'run', ['run' => $run->fresh(['install.website']), 'install' => $run->install, 'job' => $state,
            'agentErrors' => $errors]);
    }

    public function apply(Request $request, SearchReplaceRun $run): RedirectResponse
    {
        $this->authorizeInstall($request, $run->install);
        abort_unless($run->status === 'previewed', 409);
        if (! $run->canApply()) {
            return back()->withErrors(['agent' => __('This preview is too old. Run the preview again so you see what will change now.')]);
        }
        // Customers always get a snapshot; admins may skip it on a very large database.
        $snapshot = $this->area($request) === 'client' || ! $request->boolean('skip_snapshot');
        try {
            $this->replacer->apply($run, $snapshot);
        } catch (AgentException $e) {
            return back()->withErrors(['agent' => $e->getMessage()]);
        }
        ActivityLog::record($run->mode === 'domain' ? 'wordpress.change_domain' : 'wordpress.search_replace', $run->install->label(),
            ['change' => $run->summary(), 'replacements' => (int) ($run->preview['total'] ?? 0), 'snapshot' => $snapshot],
            $run->install->website->customer_id);

        return redirect()->route($this->area($request).'.wordpress.sr.show', $run);
    }
}
