<?php

declare(strict_types=1);

namespace Rhis\CrmApi\Support;

use App\Models\Customer;
use App\Models\HostingPackage;
use Rhis\CrmApi\Models\Subscription;

/** Path ids: panel id, `ext:<external_id>` or (plans) `code:<code>`. */
final class Resolve
{
    public static function customer(string $id): Customer
    {
        $q = str_starts_with($id, 'ext:') ? Customer::where('crm_id', substr($id, 4)) : Customer::whereKey(ctype_digit($id) ? (int) $id : -1);

        return $q->first() ?? throw ApiError::notFound('Customer');
    }

    public static function plan(string $id): HostingPackage
    {
        $q = str_starts_with($id, 'code:') ? HostingPackage::where('crm_id', substr($id, 5)) : HostingPackage::whereKey(ctype_digit($id) ? (int) $id : -1);

        return $q->first() ?? throw ApiError::notFound('Plan');
    }

    public static function subscription(string $id): Subscription
    {
        $q = str_starts_with($id, 'ext:') ? Subscription::where('external_id', substr($id, 4)) : Subscription::whereKey($id);

        return $q->first() ?? throw ApiError::notFound('Subscription');
    }
}
