<?php

declare(strict_types=1);

namespace Rhis\Certificates;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Rhis\Certificates\Mail\CertificateExpiring;
use Rhis\Certificates\Models\SiteCertificate;
use Throwable;

/**
 * Warns before certificates expire: administrators about every website,
 * customers about the own certificates they uploaded (Let's Encrypt renews
 * itself; it only warns when renewals keep failing).
 */
final class ExpiryWarnings
{
    /** The warning step a certificate has reached now, or null. */
    public function step(SiteCertificate $c): ?int
    {
        $days = $c->daysLeft();
        if ($c->source === 'none' || $days === null) {
            return null;
        }
        $steps = (array) config($c->renewsAutomatically() ? 'certificates.letsencrypt_warn_days' : 'certificates.warn_days', []);
        $hit = null;
        foreach ($steps as $s) {
            if ($days <= (int) $s && ($hit === null || (int) $s < $hit)) {
                $hit = (int) $s;
            }
        }
        if ($days < 0) {
            $hit = 0;
        }

        return $hit;
    }

    /** @param Collection<int, SiteCertificate> $records @return int mails sent */
    public function send(Collection $records): int
    {
        $due = $records->filter(function (SiteCertificate $c) {
            $step = $this->step($c);

            return $step !== null && ($c->warned_days === null || $step < $c->warned_days);
        })->values();
        if ($due->isEmpty()) {
            return 0;
        }
        $due = (new \Illuminate\Database\Eloquent\Collection($due->all()))->load('website.customer');
        $sent = 0;

        $admins = User::query()->where('role', User::ROLE_ADMIN)->where('is_active', true)->get();
        foreach ($admins as $admin) {
            $sent += $this->mail($admin->email, $admin->locale ?: config('app.locale'), $due, true);
        }
        $due->filter(fn (SiteCertificate $c) => $c->source === 'custom' && $c->website?->customer?->email)
            ->groupBy(fn (SiteCertificate $c) => $c->website->customer_id)
            ->each(function (Collection $list) use (&$sent) {
                $customer = $list->first()->website->customer;
                $sent += $this->mail($customer->email, $customer->locale ?: config('app.locale'), $list->values(), false);
            });

        foreach ($due as $c) {
            $c->update(['warned_days' => $this->step($c)]);
        }

        return $sent;
    }

    private function mail(string $to, string $locale, Collection $list, bool $admin): int
    {
        try {
            Mail::to($to)->locale($locale)->send(new CertificateExpiring($list->all(), $admin));

            return 1;
        } catch (Throwable $e) {
            report($e);

            return 0;
        }
    }
}
