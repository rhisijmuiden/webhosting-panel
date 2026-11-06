<?php

declare(strict_types=1);

namespace Rhis\DomainAliases;

use Rhis\CrmApi\Models\Subscription;
use Rhis\DomainAliases\Models\DomainAlias;

/** JSON shape of a domain alias in the CRM API and webhooks (DomainAlias in the OpenAPI spec). */
final class Present
{
    public static function alias(DomainAlias $a): array
    {
        $subscription = class_exists(Subscription::class) ? Subscription::where('website_id', $a->website_id)->first() : null;

        return [
            'id' => (string) $a->id,
            'domain' => $a->domain,
            'names' => $a->names(),
            'website' => ['id' => (string) $a->website_id, 'domain' => $a->website?->domain],
            'subscription_id' => $subscription?->id,
            'subscription_external_id' => $subscription?->external_id,
            'customer_id' => $a->customer_id !== null ? (string) $a->customer_id : null,
            'mode' => $a->mode,
            'www' => $a->www,
            'mail' => $a->mail,
            'ssl' => $a->ssl_status,
            'status' => $a->status,
            'error' => $a->last_error,
            'upgrade_order_id' => $a->upgrade_order_id,
            'created_by' => $a->created_by,
            'created_at' => $a->created_at?->toIso8601String(),
            'updated_at' => $a->updated_at?->toIso8601String(),
        ];
    }
}
