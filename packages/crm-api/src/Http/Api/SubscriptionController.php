<?php

declare(strict_types=1);

namespace Rhis\CrmApi\Http\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Rhis\CrmApi\Models\CrmJob;
use Rhis\CrmApi\Models\Subscription;
use Rhis\CrmApi\Services\Subscriptions;
use Rhis\CrmApi\Support\ApiError;
use Rhis\CrmApi\Support\Page;
use Rhis\CrmApi\Support\Present;
use Rhis\CrmApi\Support\Resolve;

final class SubscriptionController
{
    private const LIMITS = ['disk_mb', 'traffic_gb_month', 'domains', 'subdomains', 'mailboxes', 'mailbox_quota_mb', 'mail_aliases', 'databases', 'ftp_accounts', 'cron_jobs', 'php_workers', 'php_memory_limit_mb'];

    private const DOMAIN = '/^(?=.{1,253}$)(?!-)([a-z0-9-]{1,63}(?<!-)\.)+[a-z]{2,63}$/';

    public function __construct(private readonly Subscriptions $subscriptions) {}

    public function index(Request $request): JsonResponse
    {
        $q = Subscription::with(['package', 'website']);
        foreach (['external_id' => 'external_id', 'status' => 'status', 'domain' => 'primary_domain'] as $param => $col) {
            if (is_string($v = $request->query($param))) {
                $q->where($col, $v);
            }
        }
        if (is_string($c = $request->query('customer_id'))) {
            $q->where('customer_id', Resolve::customer($c)->id);
        }

        return response()->json(Page::of($q, $request, fn ($s) => Present::subscription($s)));
    }

    public function store(Request $request): JsonResponse
    {
        if (is_string($request->input('primary_domain'))) {
            $request->merge(['primary_domain' => rtrim(strtolower(trim($request->input('primary_domain'))), '.')]);
        }
        $data = $request->validate([
            'customer_id' => ['required', 'string'],
            'plan_id' => ['required', 'string'],
            'external_id' => ['required', 'string', 'max:64'],
            'primary_domain' => ['required', 'string', 'regex:'.self::DOMAIN],
            'php_version' => ['sometimes', Rule::in(['8.3', '8.4', '8.5'])],
            'dns' => ['sometimes', 'array'],
            'dns.provider' => ['sometimes', Rule::in(['cloudflare', 'external'])],
            'mail' => ['sometimes', 'array'],
            'mail.provider' => ['sometimes', Rule::in(['rhis', 'm365', 'none'])],
            'install' => ['sometimes', 'array'],
            'install.app' => ['sometimes', Rule::in(['wordpress'])],
            'install.admin_email' => ['sometimes', 'email'],
            'install.locale' => ['sometimes', Rule::in(['en_US', 'nl_NL'])],
            ...$this->limitRules(),
        ]);
        if (Subscription::where('external_id', $data['external_id'])->exists()) {
            throw ApiError::conflict('A subscription with this external_id exists already');
        }
        $customer = Resolve::customer($data['customer_id']);
        $plan = Resolve::plan($data['plan_id']);

        [$subscription, $job] = $this->subscriptions->create($customer, $plan, $data);

        return response()->json(['subscription' => Present::subscription($subscription), 'job' => Present::job($job)], 202);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(Present::subscription(Resolve::subscription($id)));
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $s = Resolve::subscription($id);
        $data = $request->validate([
            'plan_id' => ['sometimes', 'string'],
            'php_version' => ['sometimes', Rule::in(['8.3', '8.4', '8.5'])],
            'external_id' => ['sometimes', 'string', 'max:64', Rule::unique('crm_subscriptions', 'external_id')->ignore($s->id)],
            ...$this->limitRules(),
        ]);
        if (isset($data['plan_id'])) {
            $plan = Resolve::plan($data['plan_id']);
            if (! $plan->is_active) {
                throw ApiError::conflict('This plan is not active');
            }
            $data['plan_id'] = $plan->id;
        }
        $job = $this->subscriptions->change($s, $data);

        return response()->json(['subscription' => Present::subscription($s->fresh()), 'job' => Present::job($job->fresh())], 202);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $s = Resolve::subscription($id);
        $data = $request->validate(['keep_backup_days' => ['sometimes', 'integer', 'min:0', 'max:365']]);
        $job = $this->subscriptions->terminate($s, (int) ($data['keep_backup_days'] ?? config('crm-api.keep_backup_days', 30)));

        return response()->json(['subscription' => Present::subscription($s->fresh()), 'job' => Present::job($job->fresh())], 202);
    }

    public function suspend(Request $request, string $id): JsonResponse
    {
        $s = Resolve::subscription($id);
        $data = $request->validate([
            'reason' => ['required', Rule::in(['unpaid', 'abuse', 'customer_request', 'other'])],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
            'keep_mail_flowing' => ['sometimes', 'boolean'],
        ]);

        return response()->json(Present::subscription($this->subscriptions->suspend($s, $data)));
    }

    public function unsuspend(string $id): JsonResponse
    {
        return response()->json(Present::subscription($this->subscriptions->unsuspend(Resolve::subscription($id))));
    }

    public function restore(string $id): JsonResponse
    {
        $s = Resolve::subscription($id);
        $job = $this->subscriptions->restore($s);

        return response()->json(['subscription' => Present::subscription($s->fresh()), 'job' => Present::job($job->fresh())], 202);
    }

    public function usage(string $id): JsonResponse
    {
        return response()->json(Present::usage(Resolve::subscription($id)));
    }

    public function allUsage(Request $request): JsonResponse
    {
        $data = $request->validate(['period' => ['required', 'regex:/^[0-9]{4}-(0[1-9]|1[0-2])$/']]);
        $q = Subscription::with(['package', 'website'])->whereNot('status', 'terminated');

        return response()->json(Page::of($q, $request, fn ($s) => ['period' => $data['period']] + Present::usage($s)));
    }

    public function job(string $id): JsonResponse
    {
        $job = CrmJob::find($id) ?? throw ApiError::notFound('Job');

        return response()->json(Present::job($job));
    }

    private function limitRules(): array
    {
        $rules = ['limit_overrides' => ['sometimes', 'nullable', 'array']];
        foreach (self::LIMITS as $l) {
            $rules["limit_overrides.$l"] = ['sometimes', 'nullable', 'integer', 'min:0'];
        }

        return $rules;
    }
}
