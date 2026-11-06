<?php

declare(strict_types=1);

namespace Rhis\CrmApi\Http\Api;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Rhis\CrmApi\Mail\CustomerWelcome;
use Rhis\CrmApi\Models\Subscription;
use Rhis\CrmApi\Services\LoginLinks;
use Rhis\CrmApi\Support\ApiError;
use Rhis\CrmApi\Support\Page;
use Rhis\CrmApi\Support\Present;
use Rhis\CrmApi\Support\Resolve;

final class CustomerController
{
    public function index(Request $request): JsonResponse
    {
        $q = Customer::query();
        if (is_string($ext = $request->query('external_id'))) {
            $q->where('crm_id', $ext);
        }
        if (is_string($email = $request->query('email'))) {
            $q->where('email', $email);
        }

        return response()->json(Page::of($q, $request, fn ($c) => Present::customer($c)));
    }

    public function store(Request $request, LoginLinks $links): JsonResponse
    {
        $data = $request->validate($this->rules(true));
        if (Customer::where('crm_id', $data['external_id'])->exists()) {
            throw ApiError::conflict('A customer with this external_id exists already');
        }
        if (User::where('email', $data['email'])->exists()) {
            throw ApiError::conflict('A panel login with this e-mail address exists already');
        }

        $customer = DB::transaction(function () use ($data) {
            $customer = Customer::create([
                'name' => $data['name'], 'company' => $data['company'] ?? null, 'email' => $data['email'],
                'phone' => $data['phone'] ?? null, 'crm_id' => $data['external_id'], 'locale' => $data['locale'] ?? 'en',
                'status' => 'active',
            ]);
            $customer->forceFill(['address' => isset($data['address']) ? json_encode($data['address']) : null])->save();
            User::create([
                'name' => $data['name'], 'email' => $data['email'], 'password' => Str::random(40),
                'role' => User::ROLE_CLIENT, 'customer_id' => $customer->id, 'locale' => $data['locale'] ?? 'en',
            ]);

            return $customer;
        });
        ActivityLog::record('customer.created', $customer->displayName(), ['via' => 'crm'], $customer->id);

        if ($data['send_welcome'] ?? true) {
            $user = $customer->users()->first();
            $link = $links->create($user, 7 * 24 * 60, route('profile.edit', [], false));
            Mail::to($user)->locale($user->preferredLocale())->queue(new CustomerWelcome($customer, $link['url']));
        }

        return response()->json(Present::customer($customer->fresh()), 201);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(Present::customer(Resolve::customer($id)));
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $customer = Resolve::customer($id);
        $data = $request->validate($this->rules(false));
        $customer->fill(collect($data)->only(['name', 'company', 'email', 'phone', 'locale'])->all());
        if (array_key_exists('address', $data)) {
            $customer->forceFill(['address' => json_encode($data['address'])]);
        }
        $customer->save();
        ActivityLog::record('customer.updated', $customer->displayName(), ['via' => 'crm'], $customer->id);

        return response()->json(Present::customer($customer->fresh()));
    }

    public function destroy(string $id): JsonResponse
    {
        $customer = Resolve::customer($id);
        if (Subscription::where('customer_id', $customer->id)->whereNot('status', 'terminated')->exists() || $customer->websites()->exists()) {
            throw ApiError::conflict('This customer still has hosting');
        }
        $customer->delete();
        ActivityLog::record('customer.deleted', $customer->displayName(), ['via' => 'crm']);

        return response()->json(null, 204);
    }

    public function loginLink(Request $request, string $id, LoginLinks $links): JsonResponse
    {
        $customer = Resolve::customer($id);
        $data = $request->validate(['redirect' => ['sometimes', 'nullable', 'string', 'max:500', 'regex:/^\/(?!\/)[A-Za-z0-9\/_.?=&-]*$/']]);
        $user = $customer->users()->where('is_active', true)->orderBy('id')->first();
        if (! $user) {
            throw ApiError::conflict('This customer has no active panel login');
        }
        ActivityLog::record('crm.login_link_created', $user->email, customerId: $customer->id);

        return response()->json($links->create($user, (int) config('crm-api.login_link_minutes', 5), $data['redirect'] ?? null), 201);
    }

    private function rules(bool $create): array
    {
        $req = $create ? 'required' : 'sometimes';

        return array_filter([
            'external_id' => $create ? ['required', 'string', 'max:64'] : null,
            'name' => [$req, 'string', 'max:150'],
            'company' => ['sometimes', 'nullable', 'string', 'max:150'],
            'email' => [$req, 'email', 'max:190'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'locale' => ['sometimes', Rule::in(['en', 'nl'])],
            'address' => ['sometimes', 'array'],
            'address.street' => ['sometimes', 'nullable', 'string', 'max:200'],
            'address.postal_code' => ['sometimes', 'nullable', 'string', 'max:20'],
            'address.city' => ['sometimes', 'nullable', 'string', 'max:100'],
            'address.country' => ['sometimes', 'nullable', 'regex:/^[A-Z]{2}$/'],
            'send_welcome' => $create ? ['sometimes', 'boolean'] : null,
        ]);
    }
}
