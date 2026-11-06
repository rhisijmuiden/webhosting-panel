<?php

declare(strict_types=1);

namespace Rhis\CrmApi\Http\Api;

use App\Models\ActivityLog;
use App\Models\HostingPackage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Rhis\CrmApi\Models\Subscription;
use Rhis\CrmApi\Support\ApiError;
use Rhis\CrmApi\Support\Page;
use Rhis\CrmApi\Support\Present;
use Rhis\CrmApi\Support\Resolve;

final class PlanController
{
    /** Limits that map onto panel columns; the rest is kept in crm_limits. */
    private const COLUMNS = ['disk_mb' => 'disk_mb', 'traffic_gb_month' => 'traffic_gb_month', 'domains' => 'max_websites', 'mailboxes' => 'max_mailboxes', 'databases' => 'max_databases', 'cron_jobs' => 'max_cronjobs', 'php_workers' => 'php_workers'];

    private const LIMITS = ['disk_mb', 'traffic_gb_month', 'domains', 'domain_aliases', 'subdomains', 'mailboxes', 'mailbox_quota_mb', 'mail_aliases', 'databases', 'ftp_accounts', 'cron_jobs', 'php_workers', 'php_memory_limit_mb'];

    public function index(Request $request): JsonResponse
    {
        return response()->json(Page::of(HostingPackage::query(), $request, fn ($p) => Present::plan($p)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request, true);
        if (HostingPackage::where('crm_id', $data['code'])->exists()) {
            throw ApiError::conflict('A plan with this code exists already');
        }
        $plan = new HostingPackage;
        $this->fill($plan, $data);
        ActivityLog::record('package.created', $plan->name, ['via' => 'crm']);

        return response()->json(Present::plan($plan), 201);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(Present::plan(Resolve::plan($id)));
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $plan = Resolve::plan($id);
        $data = $this->validated($request, false);
        if (isset($data['code']) && HostingPackage::where('crm_id', $data['code'])->whereKeyNot($plan->id)->exists()) {
            throw ApiError::conflict('A plan with this code exists already');
        }
        $this->fill($plan, $data);
        ActivityLog::record('package.updated', $plan->name, ['via' => 'crm']);

        return response()->json(Present::plan($plan->fresh()));
    }

    public function destroy(string $id): JsonResponse
    {
        $plan = Resolve::plan($id);
        if (Subscription::where('hosting_package_id', $plan->id)->whereNot('status', 'terminated')->exists() || $plan->customers()->exists()) {
            throw ApiError::conflict('This plan is still in use');
        }
        $plan->delete();
        ActivityLog::record('package.deleted', $plan->name, ['via' => 'crm']);

        return response()->json(null, 204);
    }

    private function validated(Request $request, bool $create): array
    {
        $req = $create ? 'required' : 'sometimes';
        $rules = [
            'code' => [$req, 'string', 'regex:/^[a-z0-9][a-z0-9_-]{0,63}$/'],
            'name' => [$req, 'string', 'max:100'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'limits' => ['sometimes', 'array'],
            'features' => ['sometimes', 'array'],
            'features.ssh_access' => ['sometimes', 'boolean'],
            'features.wordpress_toolkit' => ['sometimes', 'boolean'],
            'features.page_cache' => ['sometimes', 'boolean'],
            'features.backups' => ['sometimes', 'boolean'],
            'features.customer_restore' => ['sometimes', 'boolean'],
            'features.backup_retention_days' => ['sometimes', 'integer', 'min:1', 'max:365'],
            'features.php_versions' => ['sometimes', 'array'],
            'features.php_versions.*' => ['in:8.3,8.4,8.5'],
            'active' => ['sometimes', 'boolean'],
        ];
        foreach (self::LIMITS as $l) {
            $rules["limits.$l"] = ['sometimes', 'nullable', 'integer', 'min:0'];
        }

        return $request->validate($rules);
    }

    private function fill(HostingPackage $plan, array $data): void
    {
        foreach (['code' => 'crm_id', 'name' => 'name', 'description' => 'description', 'active' => 'is_active'] as $in => $col) {
            if (array_key_exists($in, $data)) {
                $plan->{$col} = $data[$in];
            }
        }
        $extra = Present::json($plan->crm_limits);
        foreach ($data['limits'] ?? [] as $key => $value) {
            if (isset(self::COLUMNS[$key])) {
                // null = unlimited.
                $plan->{self::COLUMNS[$key]} = $value;
            } else {
                $extra[$key] = $value;
            }
        }
        $features = Present::json($plan->crm_features);
        foreach ($data['features'] ?? [] as $key => $value) {
            if ($key === 'ssh_access') {
                $plan->allow_ssh = (bool) $value;
            } else {
                $features[$key] = $value;
            }
        }
        $plan->forceFill(['crm_limits' => json_encode((object) $extra), 'crm_features' => json_encode((object) $features)]);
        $plan->save();
    }
}
