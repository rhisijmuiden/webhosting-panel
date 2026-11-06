<?php

declare(strict_types=1);

namespace Rhis\Vulnerabilities;

use App\Models\ActivityLog;
use App\Models\Customer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Rhis\Vulnerabilities\Mail\SecurityAlertMail;
use Rhis\Vulnerabilities\Models\Contact;
use Rhis\Vulnerabilities\Models\Finding;
use Rhis\Vulnerabilities\Models\Notice;

/**
 * Sends each customer one mail with everything that needs attention on their websites:
 * when something new is found, and as a reminder while it is still there.
 */
final class Notifier
{
    public function __construct(private readonly Options $options) {}

    /** Mail every customer that has something new or due for a reminder. Returns the number of mails. */
    public function run(): int
    {
        if (! $this->options->mailEnabled()) {
            return 0;
        }
        $sent = 0;
        $customerIds = Finding::open()->atLeast($this->options->minSeverity())
            ->join('websites', 'websites.id', '=', 'security_findings.website_id')
            ->distinct()->pluck('websites.customer_id');
        foreach (Customer::whereIn('id', $customerIds)->get() as $customer) {
            $findings = $this->findingsFor($customer);
            $due = $findings->filter(fn (Finding $f) => $this->isDue($f));
            if ($due->isEmpty()) {
                continue;
            }
            $kind = $due->contains(fn (Finding $f) => $f->notified_at === null) ? 'alert' : 'reminder';
            if ($this->send($customer, $findings, $kind)) {
                $sent++;
            }
        }

        return $sent;
    }

    /** Open findings of a customer that are mailed, worst first. */
    public function findingsFor(Customer $customer): Collection
    {
        return Finding::open()->forCustomer($customer->id)->atLeast($this->options->minSeverity())
            ->with('website', 'install.website')->get()
            ->sortBy(fn (Finding $f) => [$f->isMalware() ? 0 : 1, Finding::rank($f->severity), $f->website->domain])->values();
    }

    private function isDue(Finding $f): bool
    {
        if ($f->notified_at === null) {
            return true;
        }
        $days = $this->options->reminderDays();

        return $days > 0 && $f->notify_count <= $this->options->maxReminders()
            && $f->notified_at->lte(now()->subDays($days));
    }

    /** Send the mail now (also used by the "send now" button). False when nobody can receive it. */
    public function send(Customer $customer, Collection $findings, string $kind = 'manual'): bool
    {
        $contact = Contact::for($customer)->setRelation('customer', $customer);
        $to = $contact->recipients();
        if ($to === [] || $findings->isEmpty()) {
            return false;
        }
        $locale = $customer->locale ?: config('app.locale');
        $mail = Mail::to($to)->locale($locale);
        if ($bcc = $this->options->bcc()) {
            $mail->bcc($bcc);
        }
        $mail->send(new SecurityAlertMail($customer, $findings, $kind === 'reminder', $this->options->supportEmail()));

        foreach ($findings as $f) {
            $f->forceFill(['notified_at' => now(), 'notify_count' => $f->notify_count + 1])->save();
        }
        Notice::create([
            'customer_id' => $customer->id, 'kind' => $kind, 'recipients' => $to,
            'finding_ids' => $findings->pluck('id')->all(), 'locale' => $locale, 'sent_at' => now(),
        ]);
        ActivityLog::record('security.alert_sent', $customer->displayName(), ['kind' => $kind, 'findings' => $findings->count(), 'to' => $to], $customer->id);

        return true;
    }
}
