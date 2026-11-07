<?php

declare(strict_types=1);

namespace Rhis\DomainAliases\Http;

use App\Models\Website;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Rhis\DomainAliases\AliasException;
use Rhis\DomainAliases\Models\DomainAlias;

/** Customers: aliases of their own websites, within the limit of their package. */
final class ClientAliasController extends AliasController
{
    protected string $area = 'client';

    protected function websites(Request $request): Builder
    {
        return Website::query()->where('customer_id', $request->user()->customer_id ?? -1);
    }

    public function store(Request $request, Website $website): RedirectResponse
    {
        abort_unless(config('domain-aliases.customer_manage'), 403);

        return parent::store($request, $website);
    }

    public function destroy(Request $request, DomainAlias $alias): RedirectResponse
    {
        abort_unless(config('domain-aliases.customer_manage'), 403);

        return parent::destroy($request, $alias);
    }

    /** No room in the package: order the upgrade through the CRM. */
    public function upgrade(Request $request, DomainAlias $alias): RedirectResponse
    {
        $this->authorizeAlias($request, $alias);
        $data = $request->validate(['product' => ['required', 'string', 'max:64']]);
        try {
            $order = $this->manager->requestUpgrade($alias, $data['product']);
        } catch (AliasException $e) {
            return back()->withErrors(['alias' => $e->getMessage()]);
        }
        if ($order->checkout_url) {
            return redirect()->away($order->checkout_url);
        }

        return back()->with('status', __('Thanks! Your order has been sent to :provider. :domain becomes a website of its own as soon as the order is processed.', ['provider' => \App\Support\Brand::provider(), 'domain' => $alias->domain]));
    }
}
