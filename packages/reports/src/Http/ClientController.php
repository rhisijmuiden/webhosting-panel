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
use Rhis\Reports\Options;
use Rhis\Reports\ReportBuilder;

class ClientController extends Controller
{
    public function __construct(private readonly Options $options) {}

    private function customer(Request $request): Customer
    {
        return Customer::findOrFail($request->user()->customer_id);
    }

    /** The months a customer can look at: from the month they started, up to last month (at most two years). */
    private function months(Customer $customer)
    {
        $latest = ReportBuilder::previousMonth();
        $first = CarbonImmutable::parse($customer->created_at ?? $latest)->setTimezone(config('app.timezone'))->startOfMonth();

        return collect(range(0, 23))->map(fn ($i) => $latest->subMonthsNoOverflow($i))
            ->filter(fn ($m) => $m->gte($first->subMonthNoOverflow()))->values()
            ->whenEmpty(fn ($c) => $c->push($latest));
    }

    private function selected(Request $request, Customer $customer): CarbonImmutable
    {
        $months = $this->months($customer);
        $wanted = (string) $request->query('month', '');

        return $months->first(fn ($m) => $m->format('Y-m') === $wanted) ?? $months->first();
    }

    public function index(Request $request, ReportBuilder $builder): View
    {
        $customer = $this->customer($request);
        $month = $this->selected($request, $customer);
        $report = $builder->build($customer, $month);
        $monthName = $month->locale(app()->getLocale())->translatedFormat('F Y');

        return view('reports::client.index', MonthlyReportMail::viewData($customer, $report, $this->options->supportEmail(), $monthName) + [
            'pref' => Preference::for($customer),
            'months' => $this->months($customer),
            'month' => $month,
            'logo' => null,
        ]);
    }

    /** The report as the mail looks, full page (for printing or saving). */
    public function view(Request $request, ReportBuilder $builder): Response
    {
        $customer = $this->customer($request);
        $month = $this->selected($request, $customer);
        $mail = (new MonthlyReportMail($customer, $builder->build($customer, $month), $this->options->supportEmail()))->locale(app()->getLocale());

        return response($mail->render());
    }

    public function delivery(Request $request): RedirectResponse
    {
        $customer = $this->customer($request);
        $data = $request->validate([
            'enabled' => ['nullable', 'boolean'],
            'extra_emails' => ['nullable', 'string', 'max:1000'],
        ]);
        $emails = collect(preg_split('/[\s,;]+/', (string) ($data['extra_emails'] ?? ''), -1, PREG_SPLIT_NO_EMPTY))->map(fn ($e) => strtolower($e))->unique()->values();
        if ($emails->contains(fn ($e) => ! filter_var($e, FILTER_VALIDATE_EMAIL)) || $emails->count() > 5) {
            return back()->withInput()->withErrors(['extra_emails' => __('Enter up to 5 valid mail addresses, separated by commas.')]);
        }
        $p = Preference::for($customer);
        $p->fill(['enabled' => (bool) ($data['enabled'] ?? false), 'extra_emails' => $emails->all(), 'changed_by' => 'customer'])->save();
        ActivityLog::record('report.delivery', $customer->displayName(), ['enabled' => $p->enabled, 'by' => 'customer'], $customer->id);

        return redirect()->route('client.reports.index')->with('status', $p->enabled
            ? __('Saved. You will receive the next report at the start of next month.')
            : __('Saved. You will no longer receive the monthly report by mail.'));
    }
}
