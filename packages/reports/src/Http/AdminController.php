<?php

declare(strict_types=1);

namespace Rhis\Reports\Http;

use App\Models\ActivityLog;
use App\Models\Customer;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Rhis\Reports\Mail\MonthlyReportMail;
use Rhis\Reports\Models\Preference;
use Rhis\Reports\Models\Send;
use Rhis\Reports\Options;
use Rhis\Reports\ReportBuilder;
use Rhis\Reports\Sender;

class AdminController extends Controller
{
    public function __construct(private readonly Options $options) {}

    public function index(Request $request): View
    {
        $period = ReportBuilder::previousMonth();
        $search = trim((string) $request->query('q', ''));
        $customers = Customer::withCount('websites')->orderByRaw('coalesce(company, name)')
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', "%$search%")->orWhere('company', 'like', "%$search%")->orWhere('email', 'like', "%$search%")))
            ->paginate(25)->withQueryString();
        $prefs = Preference::whereIn('customer_id', $customers->pluck('id'))->get()->keyBy('customer_id');
        $last = Send::whereIn('id', Send::selectRaw('max(id)')->whereIn('customer_id', $customers->pluck('id'))->groupBy('customer_id'))->get()->keyBy('customer_id');

        $all = Customer::whereHas('websites')->where('status', '!=', 'suspended')->pluck('id');
        $off = Preference::whereIn('customer_id', $all)->where('enabled', false)->count();
        $receiving = $this->options->defaultOn()
            ? $all->count() - $off
            : Preference::whereIn('customer_id', $all)->where('enabled', true)->count();
        $now = CarbonImmutable::now(config('app.timezone'));
        [$h, $m] = array_map('intval', explode(':', $this->options->time()));
        $next = $now->startOfMonth()->setDay($this->options->day())->setTime($h, $m);
        if ($next->lte($now)) {
            $next = $next->addMonthNoOverflow();
        }

        return view('reports::admin.index', [
            'o' => $this->options,
            'customers' => $customers,
            'prefs' => $prefs,
            'last' => $last,
            'search' => $search,
            'period' => $period,
            'stats' => [
                'receiving' => $receiving,
                'eligible' => $all->count(),
                'sent' => Send::where('period', $period->format('Y-m'))->where('status', 'sent')->distinct()->count('customer_id'),
                'failed' => Send::where('period', $period->format('Y-m'))->where('status', 'failed')->distinct()->count('customer_id'),
                'next' => $next,
            ],
            'history' => Send::with('customer')->latest('id')->limit(10)->get(),
            'months' => collect(range(0, 11))->map(fn ($i) => $period->subMonthsNoOverflow($i)),
        ]);
    }

    public function saveSettings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'default_on' => ['required', 'boolean'],
            'day' => ['required', 'integer', 'between:1,28'],
            'time' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'bcc' => ['nullable', 'email', 'max:190'],
            'support_email' => ['nullable', 'email', 'max:190'],
        ]);
        foreach ($data as $key => $value) {
            $this->options->set($key, in_array($key, ['enabled', 'default_on'], true) ? (bool) $value : ($value ?? ''));
        }
        ActivityLog::record('report.settings', null, ['enabled' => (bool) $data['enabled'], 'day' => (int) $data['day']]);

        return back()->with('status', __('Monthly report settings saved.'));
    }

    public function preview(Request $request, Customer $customer, ReportBuilder $builder): Response
    {
        $locale = in_array($request->query('locale'), ['en', 'nl'], true) ? $request->query('locale') : ($customer->locale ?: config('app.locale'));
        $month = $this->month($request);
        $mail = (new MonthlyReportMail($customer, $builder->build($customer, $month), $this->options->supportEmail()))->locale($locale);

        return response($mail->render());
    }

    public function send(Request $request, Customer $customer, Sender $sender): RedirectResponse
    {
        $month = $this->month($request);
        $to = null;
        if ($request->boolean('to_me')) {
            $to = [strtolower((string) $request->user()->email)];
        } elseif (Preference::for($customer)->recipients() === []) {
            return back()->with('warning', __(':customer does not receive the monthly report. Switch it on first, or send it to yourself.', ['customer' => $customer->displayName()]));
        }
        $ok = $sender->send($customer, $month, $request->boolean('to_me') ? 'test' : 'manual', $to);

        return $ok
            ? back()->with('status', __('Report about :month sent to :to.', ['month' => $month->locale(app()->getLocale())->translatedFormat('F Y'), 'to' => implode(', ', $to ?? Preference::for($customer)->recipients())]))
            : back()->with('warning', __('The report could not be sent. Check the mail server settings.'));
    }

    public function delivery(Request $request, Customer $customer): RedirectResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        $p = Preference::for($customer);
        $p->fill(['enabled' => (bool) $data['enabled'], 'changed_by' => 'admin'])->save();
        ActivityLog::record('report.delivery', $customer->displayName(), ['enabled' => $p->enabled, 'by' => 'admin'], $customer->id);

        return back()->with('status', $p->enabled
            ? __(':customer receives the monthly report again.', ['customer' => $customer->displayName()])
            : __(':customer no longer receives the monthly report.', ['customer' => $customer->displayName()]));
    }

    public function sendAll(Request $request, Sender $sender): RedirectResponse
    {
        $month = $this->month($request);
        $result = $sender->run($month);
        ActivityLog::record('report.sent_all', $month->format('Y-m'), $result);

        return back()->with($result['failed'] ? 'warning' : 'status', __('Report about :month: :sent sent, :failed failed, :skipped had it already.', [
            'month' => $month->locale(app()->getLocale())->translatedFormat('F Y'), 'sent' => $result['sent'], 'failed' => $result['failed'], 'skipped' => $result['skipped'],
        ]));
    }

    private function month(Request $request): CarbonImmutable
    {
        $m = (string) $request->input('month', '');
        $latest = ReportBuilder::previousMonth();
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m)) {
            return $latest;
        }
        $month = ReportBuilder::month($m);

        // The running month is allowed for a preview ("so far"); the future is not.
        return $month->gt($latest->addMonthNoOverflow()) ? $latest : $month;
    }
}
