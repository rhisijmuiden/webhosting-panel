# Team members

A customer (the **owner**) can invite other people, such as their web developer
or a colleague, as **team members**. A team member gets their own login to the
client panel, with access to only the websites and the areas the owner chose.
Every change in the client panel is written to the **activity log** with who
did it.

Client pages: **Account → Team** (`client.team.index`) and **Account → Activity
log** (`client.team.activity`). Only the owner sees them.

## Access

- **Websites:** all websites (also the ones added later), or a selection.
  Subdomains of a selected website come along.
- **Areas** (`App\Support\TeamAreas`): Website settings, Files, Databases,
  WordPress & apps, Git deployments, Email, DNS, Backups, Security, Orders.
  Backups and Orders reach data of every website, so they can only be given
  together with "all websites".
- Every team member can open the overview and their list of websites.
  The team pages and the activity log stay with the owner.
- The owner can require two-factor authentication for all team members
  (`customers.team_require_two_factor`). Members without it are sent to
  Profile → Security until they set it up.

## How it is enforced

- `App\Http\Middleware\TeamGate` runs inside the `client` middleware alias, so
  every client route (also from packages) passes it. A team member gets a 403
  on any client route that is not in one of their areas. **A client route that
  is in no area is closed to team members**, so new features are safe by
  default: add the routes to an area with `TeamAreas::extend('mail', ['client.webmail.*'])`
  or in `TeamAreas` itself.
- `App\Services\Team\WebsiteScope` is a global scope on `Website` and on every
  model with a `website_id` listed in `TeamServiceProvider::SCOPED_MODELS`.
  While a limited team member is logged in, those queries only return their
  websites, so lists, route model binding and `whereHas('website')` all follow.
  It does nothing for admins, owners, jobs and the CLI.
- The client menu, the quick links and the website page tiles hide what a team
  member cannot open (`Menu::filter()`, `SiteOverview::has()`).

## Invitations

`App\Services\Team\TeamManager::invite()` stores only the sha256 of a random
48-character token and mails the link `/team/join/<token>` in the language the
owner chose. The link is valid for 7 days (`WHP_TEAM_INVITE_DAYS`), works once
and can be sent again (new link) or withdrawn. The invitee chooses a name and
a password (12+ characters, upper and lower case, a number) and is logged in.
An e-mail address that already has a login cannot be invited. At most 25
members plus open invitations per customer (`WHP_TEAM_MAX_MEMBERS`).

## Activity log

- Every non-GET client request that succeeds and did not already write its
  own activity entry writes one `client.change` entry (route, area, website
  domain), labelled like "Files: Uploaded". Forms that come back with
  validation errors are not logged.
- `activity_logs.actor` keeps "name <email>", so the log still shows who it
  was after a team member is removed.
- Admins see team members marked on the customer page and all entries in the
  admin activity log.
