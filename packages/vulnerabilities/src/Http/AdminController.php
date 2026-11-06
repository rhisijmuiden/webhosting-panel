<?php

declare(strict_types=1);

namespace Rhis\Vulnerabilities\Http;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Website;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Rhis\Vulnerabilities\Mail\SecurityAlertMail;
use Rhis\Vulnerabilities\Models\Finding;
use Rhis\Vulnerabilities\Models\Notice;
use Rhis\Vulnerabilities\Notifier;
use Rhis\Vulnerabilities\Options;
use Rhis\Vulnerabilities\Posture;
use Rhis\Vulnerabilities\Risk;
use Rhis\Vulnerabilities\Scanner;

final class AdminController extends Controller
{
    public function __construct(private readonly Options $options) {}

    /** The security overview: server posture, customers most at risk, newest findings. */
    public function index(Posture $posture): View
    {
        return view('vulnerabilities::admin.index', [
            'posture' => $posture->get(),
            'totals' => Risk::totals(),
            'ranking' => Risk::ranking(8),
            'latest' => Finding::open()->with('website.customer', 'install.website')->get()
                ->sortBy(fn (Finding $f) => [$f->isMalware() ? 0 : 1, Finding::rank($f->severity), -($f->cvss_score ?? 0)])->take(8)->values(),
            'notices' => Notice::with('customer')->latest('sent_at')->take(5)->get(),
            'source' => $this->options->source(),
            'mailEnabled' => $this->options->mailEnabled(),
        ]);
    }

    public function findings(Request $request): View
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in(['open', 'resolved', 'ignored', 'all'])],
            'severity' => ['nullable', Rule::in([...Finding::SEVERITIES, 'malware'])],
            'customer' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $status = $data['status'] ?? 'open';
        $findings = Finding::query()->with('website.customer', 'install.website')
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($data['severity'] ?? null, fn ($q, $s) => $s === 'malware' ? $q->where('kind', 'malware') : $q->where('kind', 'vulnerability')->where('severity', $s))
            ->when($data['customer'] ?? null, fn ($q, $c) => $q->forCustomer((int) $c))
            ->when($data['q'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q->where('title', 'like', "%$s%")->orWhere('component_slug', 'like', "%$s%")
                ->orWhere('cve', 'like', "%$s%")->orWhereHas('website', fn ($w) => $w->where('domain', 'like', "%$s%"))))
            ->orderByRaw("case when kind = 'malware' then 0 else 1 end")
            ->orderByRaw("case severity when 'critical' then 0 when 'high' then 1 when 'medium' then 2 when 'unknown' then 3 else 4 end")
            ->orderByDesc('cvss_score')->orderByDesc('id')
            ->paginate(30)->withQueryString();

        return view('vulnerabilities::admin.findings', [
            'findings' => $findings, 'filters' => $data + ['status' => $status],
            'customers' => Customer::orderBy('name')->get(['id', 'name', 'company']),
        ]);
    }

    public function scan(Request $request, Scanner $scanner, Notifier $notifier): RedirectResponse
    {
        $data = $request->validate(['website' => ['nullable', 'integer'], 'mail' => ['nullable', 'boolean']]);
        $sites = Website::where('status', 'active')->when($data['website'] ?? null, fn ($q, $id) => $q->whereKey($id))->get();
        $found = $resolved = 0;
        $errors = [];
        foreach ($sites as $site) {
            $r = $scanner->scanWebsite($site);
            $found += $r['found'];
            $resolved += $r['resolved'];
            array_push($errors, ...$r['errors']);
        }
        $mailed = ($data['mail'] ?? false) ? $notifier->run() : 0;
        ActivityLog::record('security.scan', null, ['sites' => $sites->count(), 'found' => $found, 'resolved' => $resolved]);
        $back = back()->with('status', __(':sites websites checked: :found new problems, :resolved resolved.', ['sites' => $sites->count(), 'found' => $found, 'resolved' => $resolved])
            .($mailed ? ' '.trans_choice(':count customer mailed.|:count customers mailed.', $mailed) : ''));

        return $errors ? $back->withErrors(['agent' => implode(' · ', array_slice($errors, 0, 5))]) : $back;
    }

    public function posture(Posture $posture): RedirectResponse
    {
        $posture->get(fresh: true);

        return back()->with('status', __('Server checks refreshed.'));
    }

    public function sync(AgentClient $agent): RedirectResponse
    {
        if (! $this->options->wordfenceKey()) {
            return back()->withErrors(['agent' => __('Enter a Wordfence Intelligence API key first.')]);
        }
        try {
            $agent->call('vuln.sync', ['api_key' => $this->options->wordfenceKey()], 30);
        } catch (AgentException $e) {
            return back()->withErrors(['agent' => $e->getMessage()]);
        }
        ActivityLog::record('security.vulndb_sync');

        return back()->with('status', __('The Wordfence database is being downloaded. This takes a few minutes.'));
    }

    public function ignore(Request $request, Finding $finding): RedirectResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:200']]);
        $finding->update(['status' => 'ignored', 'ignored_by' => $request->user()->id, 'ignore_reason' => $data['reason'] ?? null]);
        ActivityLog::record('security.finding_ignored', $finding->siteLabel().': '.$finding->title, [], $finding->website->customer_id);

        return back()->with('status', __('The problem is ignored and no longer mailed.'));
    }

    public function reopen(Finding $finding): RedirectResponse
    {
        $finding->update(['status' => 'open', 'ignored_by' => null, 'ignore_reason' => null, 'resolved_at' => null]);

        return back()->with('status', __('The problem is open again.'));
    }

    public function notify(Customer $customer, Notifier $notifier): RedirectResponse
    {
        $findings = $notifier->findingsFor($customer);
        if ($findings->isEmpty()) {
            return back()->withErrors(['agent' => __('There is nothing to report for this customer at the chosen minimum severity.')]);
        }
        if (! $notifier->send($customer, $findings, 'manual')) {
            return back()->withErrors(['agent' => __('This customer has no mail address or switched the security mails off.')]);
        }

        return back()->with('status', __('The security mail was sent to :name.', ['name' => $customer->displayName()]));
    }

    /** The mail as the customer sees it, in their language (or ?locale=). */
    public function preview(Request $request, Customer $customer, Notifier $notifier): Response
    {
        $locale = in_array($request->query('locale'), ['en', 'nl'], true) ? $request->query('locale') : ($customer->locale ?: config('app.locale'));
        $findings = $notifier->findingsFor($customer);
        if ($findings->isEmpty()) {
            $findings = Finding::open()->forCustomer($customer->id)->with('website', 'install.website')->get();
        }
        abort_if($findings->isEmpty(), 404);
        $mail = (new SecurityAlertMail($customer, $findings, false, $this->options->supportEmail()))->locale($locale);

        return response($mail->render())->header('Content-Security-Policy', "default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'");
    }

    public function settings(AgentClient $agent): View
    {
        try {
            $db = $agent->call('vuln.status', [], 10);
        } catch (AgentException) {
            $db = null;
        }

        return view('vulnerabilities::admin.settings', [
            'o' => $this->options, 'db' => $db, 'hasKey' => (bool) $this->options->wordfenceKey(),
        ]);
    }

    public function saveSettings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'source' => ['required', Rule::in(['wpvulnerability', 'wordfence'])],
            'wordfence_key' => ['nullable', 'string', 'max:256', 'regex:/^[A-Za-z0-9._-]{16,256}$/'],
            'forget_key' => ['nullable', 'boolean'],
            'mail_enabled' => ['nullable', 'boolean'],
            'min_severity' => ['required', Rule::in(['critical', 'high', 'medium', 'low'])],
            'reminder_days' => ['required', 'integer', 'min:0', 'max:90'],
            'max_reminders' => ['required', 'integer', 'min:0', 'max:10'],
            'bcc' => ['nullable', 'email', 'max:190'],
            'support_email' => ['nullable', 'email', 'max:190'],
        ]);
        if ($data['source'] === 'wordfence' && empty($data['wordfence_key']) && ! $this->options->wordfenceKey()) {
            return back()->withInput()->withErrors(['wordfence_key' => __('Wordfence needs an API key.')]);
        }
        $data['mail_enabled'] = (bool) ($data['mail_enabled'] ?? false);
        $this->options->save($data);
        ActivityLog::record('security.alert_settings', null, collect($data)->except('wordfence_key')->all());

        return redirect()->route('admin.vulns.settings')->with('status', __('Settings saved.'));
    }
}
