<?php

declare(strict_types=1);

namespace Rhis\DomainAliases\Http\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Rhis\CrmApi\Models\Subscription;
use Rhis\CrmApi\Support\ApiError;
use Rhis\CrmApi\Support\Page;
use Rhis\CrmApi\Support\Resolve;
use Rhis\DomainAliases\AliasException;
use Rhis\DomainAliases\AliasManager;
use Rhis\DomainAliases\Models\DomainAlias;
use Rhis\DomainAliases\Present;

/** Domain aliases for the RHIS CRM (docs/api/crm-api.openapi.yaml, tag "Domain aliases"). */
final class DomainAliasApiController
{
    public function __construct(private readonly AliasManager $manager) {}

    public function index(Request $request): JsonResponse
    {
        $q = DomainAlias::with('website')->orderBy('domain');
        if (is_string($id = $request->query('subscription_id'))) {
            $q->where('website_id', Resolve::subscription($id)->website_id ?? -1);
        }
        if (is_string($domain = $request->query('domain'))) {
            $q->where('domain', AliasManager::normalise($domain));
        }

        return response()->json(Page::of($q, $request, fn (DomainAlias $a) => Present::alias($a)));
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(Present::alias($this->find($id)));
    }

    public function store(Request $request, string $id): JsonResponse
    {
        $subscription = Resolve::subscription($id);
        $website = $subscription->website ?? throw ApiError::conflict('This subscription has no website');
        if ($subscription->status === 'terminated') {
            throw ApiError::conflict('This subscription is terminated');
        }
        $data = $request->validate([
            'domain' => ['required', 'string', 'max:253'],
            'mode' => ['sometimes', Rule::in(DomainAlias::MODES)],
            'www' => ['sometimes', 'boolean'],
            'mail' => ['sometimes', 'boolean'],
            'ssl' => ['sometimes', 'boolean'],
            'enforce_limit' => ['sometimes', 'boolean'],
        ]);
        $alias = $this->attempt(fn () => $this->manager->add($website, $data, 'crm', $request->boolean('enforce_limit')));

        return response()->json(Present::alias($alias->fresh('website')), 202);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $alias = $this->find($id);
        $data = $request->validate([
            'mode' => ['sometimes', Rule::in(DomainAlias::MODES)],
            'mail' => ['sometimes', 'boolean'],
        ]);
        $this->attempt(function () use ($alias, $data) {
            if (isset($data['mode'])) {
                $this->manager->setMode($alias, $data['mode']);
            }
            if (array_key_exists('mail', $data)) {
                $this->manager->setMail($alias, (bool) $data['mail']);
            }
        });

        return response()->json(Present::alias($alias->fresh('website')));
    }

    public function destroy(string $id): Response
    {
        $alias = $this->find($id);
        $this->attempt(fn () => $this->manager->remove($alias, 'crm'));

        return response()->noContent();
    }

    /**
     * Turn the alias into a website of its own. Without a body the website
     * joins the customer's current package; with plan_id + external_id it
     * gets its own subscription on that plan.
     */
    public function convert(Request $request, string $id): JsonResponse
    {
        $alias = $this->find($id);
        $data = $request->validate([
            'plan_id' => ['nullable', 'string', 'required_with:external_id'],
            'external_id' => ['nullable', 'string', 'max:64', 'required_with:plan_id'],
            'php_version' => ['sometimes', Rule::in(['8.3', '8.4', '8.5'])],
        ]);
        $subscriptionData = isset($data['plan_id']) ? $data : null;
        $website = $this->attempt(fn () => $this->manager->convert($alias, 'crm', $subscriptionData));
        $subscription = Subscription::where('website_id', $website->id)->first();

        return response()->json([
            'website' => ['id' => (string) $website->id, 'domain' => $website->domain, 'status' => $website->status],
            'subscription_id' => $subscription?->id,
        ], 202);
    }

    private function find(string $id): DomainAlias
    {
        $q = str_starts_with($id, 'domain:')
            ? DomainAlias::where('domain', AliasManager::normalise(substr($id, 7)))
            : DomainAlias::whereKey(ctype_digit($id) ? (int) $id : -1);

        return $q->with('website')->first() ?? throw ApiError::notFound('Domain alias');
    }

    private function attempt(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (AliasException $e) {
            throw match ($e->reason) {
                'conflict', 'limit', 'agent' => ApiError::conflict($e->getMessage()),
                'not_found' => ApiError::notFound('Product'),
                default => new ApiError(422, 'validation_failed', $e->getMessage()),
            };
        }
    }
}
