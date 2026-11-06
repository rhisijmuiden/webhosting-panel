# Subscriptions

Like Plesk, the hosting package belongs to a **website**, not to a customer.
A subscription is one website on one hosting package:

| Field | Meaning |
| --- | --- |
| website | the website (domain). One subscription per website (`subscriptions.website_id` is unique). |
| package | `hosting_package_id`; empty = no package, everything unlimited. |
| owner | the customer of the website (`websites.customer_id`), "subscription holder" in the UI. |
| status | `active` or `suspended` (+ `suspended_reason`). Suspended: 503 page, SFTP closed. |
| setup date | `created_at` (Plesk migration keeps the Plesk date). |
| expiry date | `expires_on`, optional. The day after it the subscription is suspended (`whp:subscriptions:expire`, daily 00:10). |

A customer can have any number of subscriptions, each on its own package.
Every limit of the package (disk, traffic, mailboxes, databases, cron jobs,
CPU, memory, IO, processes, PHP workers, database connections) applies to that
one website. Its resource jail is `whp-sites-s<subscription id>.slice` (see
[limits.md](limits.md)).

## Code

- Model `App\Models\Subscription`, created automatically with every website
  (`Website::created`; the package comes from `$website->initialPackageId`).
  `$website->package` is the package of the subscription; a subdomain
  (`websites.parent_id`) uses its main website's subscription (`effectiveSubscription()`).
- All changes go through `App\Services\SubscriptionManager`: `changePackage()`
  (disk quota + resource jail), `changeOwner()` (moves every row that has both
  `website_id` and `customer_id`, plus the mail domain and its alias domains),
  `suspend()`, `activate()`, `setExpiry()`, `expire()`.
- Admin: Hosting → Subscriptions (`admin.subscriptions.index`): search on domain,
  owner and package, sorting, 10/25/100/all per page, bulk actions change
  package, change owner, set status, expiry date and remove. Filters:
  `?customer=<id>`, `?package=<id>|none`, `?status=active|suspended`.
- New website (admin) = new subscription: choose the package and an optional expiry date.
- Client panel: each website shows its subscription (package, setup and expiry
  date, usage against the package).

## CRM API

A CRM subscription (`crm_subscriptions`) stays the CRM's record (external id,
plan, overrides). Creating it creates the website on the plan; a plan change
also changes the panel subscription's package; suspend/unsuspend from the CRM
suspends or activates the panel subscription.

## Plesk migration

Plesk subscriptions map 1:1. The inventory has `plan`, `created` and `expires`
per domain (from `Subscriptions`, `PlansSubscriptions`, `Templates`, `Limits`).
The service plan becomes the package with the same name (case-insensitive); if
there is none, a package with that name is created with everything unlimited,
for the admin to fill in. Domains with their own website inside one Plesk
subscription (additional domains) each become a subscription on the same package here.

## Upgrade from per-customer packages

Migration `2026_11_06_001500_create_subscriptions_table` gives every existing
website a subscription with the package its customer had, the website's
creation date and the traffic alert of the customer, then drops
`customers.hosting_package_id`. On the next update the panel moves every site
into its subscription slice (`whp:limits:apply`) and installer migration
`0003-subscription-slices.sh` removes customer slices that no site uses.
