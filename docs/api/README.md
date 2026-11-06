# CRM API

The RHIS CRM (customers, contracts, invoices) drives hosting in the panel
through this API. The full contract is
[`crm-api.openapi.yaml`](crm-api.openapi.yaml) (OpenAPI 3.1; open it in any
OpenAPI viewer or generate a client from it).

Base URL: `https://<panel-host>:8443/api/v1/crm`

## Who owns what

| Data | Source of truth |
| --- | --- |
| Customers, prices, invoices, payment status | CRM |
| Plans (limits per package) | CRM creates/changes them, the panel enforces them |
| Sites, mailboxes, databases, usage, backups | Panel |

The CRM keeps its own ids; the panel stores them as `external_id` and every
`{id}` in a path also accepts `ext:<external_id>` (and `code:<code>` for
plans), so the CRM never needs to store panel ids.

## Typical flows

**New order paid**

1. Make sure the plan exists: `GET /plans/code:hosting-basic`, `POST /plans` if 404.
2. `POST /customers` with `external_id` = CRM customer id (409 when it exists: use it).
3. `POST /subscriptions` with `Idempotency-Key: <order id>` → `202` with a job.
4. Wait for the `subscription.provisioned` webhook (or poll `GET /jobs/{id}`).

**Upgrade / downgrade**: `PATCH /subscriptions/ext:<contract>` with the new `plan_id`.

**Invoice overdue**: `POST /subscriptions/ext:<contract>/suspend` with
`reason: unpaid`; after payment `POST .../unsuspend`.

**Contract ended**: `DELETE /subscriptions/ext:<contract>?keep_backup_days=30`.
A final full backup is kept; `POST .../restore` brings it back within that time.

**Overage billing**: `GET /usage?period=2026-10` once a month.

**Customer orders from inside the panel** (for example Microsoft 365
mailboxes from the M365 DNS wizard, or a bigger plan):

1. The CRM keeps the catalog in the panel up to date with webshop prices:
   `PUT /catalog` after every price change (`kind: m365` products appear in the
   M365 wizard, `plan_upgrade` on the plan page, `shop_url` is used for "Buy
   hosting" links).
2. The customer picks a product and quantity; the panel sends an
   `order.requested` webhook with the price it showed.
3. The CRM creates the order/quote and answers with
   `PATCH /orders/{id}` (`awaiting_payment` with a `checkout_url`, then
   `completed`, or `rejected` with a message). The customer sees the status in
   the panel.

**"Open hosting panel" button** in the customer portal:
`POST /customers/ext:<id>/login-link`, then redirect to the returned `url`.

```sh
curl -sS https://panel.example.nl:8443/api/v1/crm/subscriptions \
  -H "Authorization: Bearer $TOKEN" -H "Idempotency-Key: ORD-2026-0412" \
  -H "Content-Type: application/json" \
  -d '{"customer_id":"ext:C-1001","plan_id":"code:hosting-basic",
       "external_id":"ORD-2026-0412","primary_domain":"example.nl",
       "mail":{"provider":"rhis"},"dns":{"provider":"cloudflare"}}'
```

## Security

- Tokens are created in the panel (System > CRM API) with the `crm`
  ability, shown once, stored hashed, optionally limited to source IPs.
- HTTPS only. Rate limit: 120 requests per minute per token (`429` with
  `Retry-After`).
- Every call is written to the audit log with the token name.

## Webhooks

The panel signs every delivery. Verify in the CRM (Laravel example):

```php
$header = $request->header('X-WHP-Signature');            // t=1730841243,v1=5f1c...
parse_str(str_replace(',', '&', $header), $sig);
$expected = hash_hmac('sha256', $sig['t'].'.'.$request->getContent(), config('services.whp.webhook_secret'));
abort_unless(hash_equals($expected, $sig['v1'] ?? '') && abs(time() - (int) $sig['t']) <= 300, 401);
// Drop duplicates on X-WHP-Delivery, then handle $request->json('type').
```

Events: `subscription.provisioned`, `subscription.failed`,
`subscription.changed`, `subscription.suspended`, `subscription.unsuspended`,
`subscription.terminated`, `usage.limit_reached`, `backup.failed`,
`customer.updated`, `order.requested`, `order.cancelled`, `ping`. Retries: 1 min, 5 min, 30 min, 2 h, 6 h, 24 h.

## Implementation

The API is the `packages/crm-api` package (`Rhis\CrmApi\`), loaded by the
panel. Admins manage tokens and see webhook deliveries under
**System > CRM API**; customers see the catalog under **Order** in the client
panel. `php artisan crm-api:watch` (scheduled hourly) sends
`usage.limit_reached` and `backup.failed`.

Current limits of the implementation:

- Usage reports disk space and domains; mailbox, database and traffic
  counters follow when the mail and database modules report them.
- Suspension blocks the customer login and marks the site suspended. The
  503 suspension page on the server needs the agent actions `site.suspend`
  and `site.unsuspend`; until those exist the panel logs that it could not
  apply them.
- One customer has one plan in the panel, so all subscriptions of a customer
  share the plan of the most recent one.
