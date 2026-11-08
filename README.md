# Shared costs

A Drupal field that splits an amount over other entities, equally or by
percentage, and stores each part in cents that always add up to the total.
Views can then add the parts up per sharer and per month.

It comes with **Stable Manager**: recipes that turn a Drupal site into a
cost tracker for a horse stable (expenses with receipts, inventory, a
dashboard with charts, a monthly overview, a statement per horse and CSV
exports), in English or Dutch, with a JSON:API for apps. Stable Manager started
as a Next.js app; the recipes rebuild it with configuration only, on top of
this one small module. Its front-end theme, in [`themes/stable_manager_theme`](themes/stable_manager_theme),
makes it an app: HTMX navigation, live filters, view transitions and dark
mode. Forms and admin pages use Gin.

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Upgrading from 1.0](#upgrading-from-10)
- [Using the field](#using-the-field)
- [Views](#views)
- [The Stable Manager recipes](#the-stable-manager-recipes)
- [JSON:API (for apps)](#jsonapi-for-apps)
- [The Stable Manager theme](#the-stable-manager-theme)
- [Development](#development)
- [Known issues](#known-issues)

## Features

- **A field type, _Shared costs_.** Each item refers to a sharer (any content
  entity: horses, housemates, departments) with an optional percentage. Sharers
  without a percentage get an equal part of what the percentages leave.
- **Cent-exact amounts.** When the content is saved, the total (a decimal,
  float or integer field on the same entity) is split in integer cents with
  the largest-remainder method: 12.35 over two sharers is 6.18 + 6.17, never
  6.18 + 6.18. The amounts are stored, so reports never round again.
- **Validation.** The percentages may not add up to more than 100 %, must add
  up to exactly 100 % when every sharer has one, and a sharer can't be listed
  twice.
- **A table widget** with a checkbox and a percentage per sharer, and a live
  preview of the amounts while you type, with core's
  [HTMX](https://www.drupal.org/docs/develop/drupal-apis/htmx) (no custom
  JavaScript). Sharers that can no longer be picked (an unpublished horse) stay
  on old content as read-only rows: editing never silently re-splits it.
- **A formatter**: "Luna · 50 % · € 12.50", with your own separators, prefix
  and suffix.
- **Views integration**: relationships to and from the sharers, the amount and
  share as numbers you can SUM, a _year and month_ field to group by month, and
  a month argument titled "November 2026" (core's shows "202611").
- **Default content**: core's `content:export` exports the sharers by UUID, so
  content with shared costs can ship in recipes.
- **Dutch** interface translation included.

Custom code is kept small: one field type, widget, formatter and constraint,
two small Views plugins, hooks as `#[Hook]` methods with autowired services,
and an event subscriber. Everything else (content types, views, charts,
permissions, translations) is configuration in the recipes.

## Requirements

- Drupal 11.4 or later, or Drupal 12 (see [Known issues](#known-issues)).
- PHP 8.3 or later (Drupal 12: PHP 8.5).
- For the recipes:
  - [Charts](https://www.drupal.org/project/charts) 5.2.4 or later, with its
    Chart.js submodule;
  - [Prepopulate](https://www.drupal.org/project/prepopulate) 2.5, for "Add as
    expense" on the inventory;
  - [Field Group](https://www.drupal.org/project/field_group) 4, for the cards
    on the forms (the expense form has Expense and Amount, with the quantity,
    price and total in a row);
  - [Views Data Export](https://www.drupal.org/project/views_data_export) 1.10,
    for the CSV download;
  - a [private file system](https://www.drupal.org/docs/8/core/modules/file/overview#content-accessing-private-files)
    for receipts: set `$settings['file_private_path']` in `settings.php`.
- For the front end and demo recipes: [Gin](https://www.drupal.org/project/gin) 5,
  [Gin Toolbar](https://www.drupal.org/project/gin_toolbar) 3 and
  [Admin Toolbar](https://www.drupal.org/project/admin_toolbar) 3.

## Installation

```bash
composer require drupal/shared_costs
drush pm:install shared_costs
```

The module isn't on drupal.org yet: until it is, add this repository to your
project's `composer.json` first:

```bash
composer config repositories.shared_costs \
  vcs https://github.com/RobbeSaelens/shared_costs
```

## Upgrading from 1.0

Stal Manager is now called Stable Manager, and its recipes and theme have new
machine names:

| 1.0 | Now |
|---|---|
| `stal_manager` | `stable_manager` |
| `stal_manager_demo` | `stable_manager_demo` |
| `stal_manager_nl` | `stable_manager_nl` |
| `stal_manager_theme` | `stable_manager_theme` |

A site that uses the old theme (the demo recipe installed it) enables the new
one, which brings its own blocks, and uninstalls the old one:

```bash
drush theme:install stable_manager_theme
drush config:set system.theme default stable_manager_theme
drush theme:uninstall stal_manager_theme
```

Browsers forget the light or dark choice and the collapsed sidebar once: the
theme keeps them under new names. Recipes are applied once, so nothing else
changes on a site that applied them.

## Upgrading from 2.1

2.2 groups the fields of the horse, inventory item and expense forms into
cards (Field Group), the logo no longer tilts when you hover over it, and the
recipes need one more module. A site that applied the recipes before gets the
cards like this (the displays are deleted so that the recipe can add them again:
see [Updating a site](#updating-a-site), and note that it drops changes the site
made to these three form displays):

```bash
composer require drupal/field_group
composer update drupal/shared_costs
drush pm:install field_group
drush config:delete core.entity_form_display.node.expense.default
drush config:delete core.entity_form_display.node.horse.default
drush config:delete core.entity_form_display.node.inventory_item.default
# stable_manager_nl includes stable_manager: use it on a site in Dutch.
drush recipe modules/contrib/shared_costs/recipes/stable_manager
drush cache:rebuild
```

## Updating a site

`composer update drupal/shared_costs` updates the module's code and its theme,
and nothing else. A recipe is applied once, and applying it again (they are
idempotent) **keeps the configuration the site has** and only adds what is
missing (`strict: false`). So a release that changes a recipe's configuration
doesn't change a site that applied the recipe before, until you do this:

1. **The modules a release needs.** The modules of
   [Requirements](#requirements) are `require-dev` of this module, which a site
   never installs. Run `composer require` for each module the release adds on
   the site, or the recipe fails on the module that's missing. The upgrade
   section of the release says which.
2. **New configuration** (a field, a view, a role) arrives when you apply the
   recipes again, in the order of [the recipes](#the-stable-manager-recipes):
   `stable_manager` (or `stable_manager_nl`, which includes it) first, then the
   others.
3. **Changed configuration** (a form display, a view that exists) doesn't
   arrive, whatever the recipe says. Either delete that configuration with
   `drush config:delete` and apply the recipe again, which drops what the site
   changed in it, or copy the change by hand from
   [`recipes/stable_manager/config`](recipes/stable_manager/config). Look at
   `git log -p -- recipes` between the two releases to see what changed.
4. **A site that keeps its configuration in Git** (`config/sync`) exports it
   afterwards and reviews the diff before committing. A configuration entity
   that was deleted and added again has a new UUID: put the old `uuid:` back,
   or the next `config:import` on another copy of the site deletes and adds the
   entity.

The module is installed from this repository's tags, and `composer.lock` pins
the exact commit: a change that isn't committed, pushed and tagged here never
reaches a site, however long you have been working on it. See
[Releasing](#releasing).

## Using the field

1. On the entity that holds the costs (say an _Expense_ content type), add a
   number field for the total.
2. Add a _Shared costs_ field. In its settings, pick what can share the costs
   (for example the _Horse_ content type) and which field holds the total.
3. Optionally set a default value, for example all the horses, so new costs
   start out shared by all of them.

The amounts are worked out when the entity is saved; without a total they stay
empty.

## Views

With aggregation on, the field gives you what most cost reports need:

| To show | Use |
|---|---|
| Costs per sharer | The relationship to the sharer, its label, and SUM of _amount_ |
| Costs per month | The date field's _year and month_ field, and SUM of the total's number field |
| One month, with a list of months | The date field's _year and month_ argument, with a summary |
| A sharer's costs | The reverse relationship, from the sharer to the costs |

The totals' and amounts' number fields SUM a single column, which works the
same on MySQL/MariaDB, PostgreSQL and SQLite. The _year and month_ field sorts
by its own column, because sorting by the date itself is refused under GROUP BY
by MySQL and PostgreSQL.

## The Stable Manager recipes

Five recipes, in [`recipes/`](recipes):

| Recipe | What it does |
|---|---|
| `stable_manager` | Horses, inventory items and expenses with their receipts (content types and fields), nine cost categories, the views below, and a _Stable manager_ role. Doesn't touch themes or site settings, so it can go on an existing site. |
| `stable_manager_frontend` | `stable_manager` on a new site, looking like the app: the Stable Manager theme, Gin (in Stable Manager's brown, light by default) for forms and admin pages with Admin Toolbar's dropdown menus, the dashboard as front page, and a private site (visitors get the login form, and may do nothing else). No example content, and it leaves the site's name alone. |
| `stable_manager_demo` | `stable_manager_frontend` on a new site to try it, named _Stable Manager_, with two horses and a year of example costs. |
| `stable_manager_nl` | `stable_manager` in Dutch: Dutch as the default language, with Dutch labels, categories and number formats (€ 1.234,56). |
| `stable_manager_api` | `stable_manager` for apps: JSON:API, for stable managers logged in with a normal session. See [JSON:API (for apps)](#jsonapi-for-apps). |

The pages, all for the _Stable manager_ role (and the main menu):

- `/stable`: the dashboard. This month's costs, the latest expenses, and charts
  of the costs per horse and per month this year.
- `/stable/expenses`: all expenses by month, with who shares them, filtered by
  category or description. _Download CSV_ downloads what the filters show,
  with plain numbers for a spreadsheet (in Dutch: `;` and a decimal comma).
- `/stable/overview`: one month's costs per category and per horse, with the
  months to pick from. A horse links to its statement.
- `/stable/statement/{horse}/{month}`: a horse's statement, to print or send
  to its owner: its total, and its part of each expense of the month.
- `/stable/horses`, with each horse's statement of this month.
- `/stable/inventory`: what you buy regularly. _Add as expense_ opens the
  expense form filled in with the item, its category and price. Items marked
  _Quick add_ are one-tap buttons in the app.

An expense can have a receipt (a photo or PDF). Receipts are private files:
only people who can see the expense can open them.

Gin is light by default, whatever the system uses. Everyone can choose dark for
their own account (edit the account, then _Admin theme settings_: _Enable
overrides_ and _Appearance_): the front end recipe turns on Gin's _Users can
override Gin settings_ for that. To make the whole site dark, or to follow the
system, set `enable_darkmode` in `gin.settings` to `'1'` or `'auto'`.

Horses are never deleted (the role can't): a horse that leaves the stable is
unpublished, so its share of past costs stays.

To try it on a new site:

```bash
composer require drupal/shared_costs drupal/charts \
  drupal/prepopulate drupal/field_group drupal/views_data_export \
  drupal/gin drupal/gin_toolbar drupal/admin_toolbar
drush site:install minimal
# Receipts are private files: in settings.php, set a folder outside the web
# root, such as $settings['file_private_path'] = '../private';
# The demo, with example costs (for your own site: see below).
drush recipe modules/contrib/shared_costs/recipes/stable_manager_demo
# Optional: in Dutch.
drush recipe modules/contrib/shared_costs/recipes/stable_manager_nl
drush locale:check && drush locale:update
# Optional: the JSON:API for apps.
drush recipe modules/contrib/shared_costs/recipes/stable_manager_api
```

For a site of your own, apply `stable_manager_frontend` instead of
`stable_manager_demo`: the same theme, admin and front page, without the
example content, and with the site's name still yours (set it in _Basic site
settings_, or `drush config:set system.site name 'My stable'`). It's meant for
a new site, and the app is private by design: it sets the default and admin
themes, makes the dashboard the front page, shows visitors the login form when
they ask for what is private (`page.403` is `/user/login`), and empties the
permissions of visitors that aren't logged in. A page that has to be public
needs its permission back afterwards.

Recipes can't import interface translations, so `stable_manager_nl` ends with a
`locale:update`: it loads this module's `translations/nl.po` and core's Dutch
translation from localize.drupal.org. The Chart.js library is loaded from a CDN
by default; see the Charts module to serve it yourself.

Not ported from the Next.js app: an expense that counts in full for every
horse (add one expense per horse instead). Working offline is left to the app
itself (a PWA), with this site as its backend: see `stable_manager_api`.

## JSON:API (for apps)

`stable_manager_api` opens Stable Manager to apps, such as its own mobile app (a
PWA that works offline), through core's
[JSON:API](https://www.drupal.org/docs/core-modules-and-themes/core-modules/jsonapi-module).
Apps log in as people do, with Drupal's session cookie rather than OAuth: so
an app reaches Drupal on its own site, for example through a proxy at
`/drupal/*`, without CORS. The recipe:

- applies `stable_manager`, installs JSON:API and lets it write (`read_only:
  false`);
- lets the _Stable manager_ role see the horses, inventory items and expenses,
  add, change and delete expenses and inventory items, and change horses,
  including unpublishing one that leaves the stable (_Administer node published
  status_);
- takes every permission from visitors that aren't logged in, as the demo
  does: JSON:API lists all they may see, and costs are private.

The tests in
[`StableManagerApiRecipeTest`](tests/src/Functional/Recipe/StableManagerApiRecipeTest.php)
make the requests below, as an app does.

### Logging in

| Request | Answer |
|---|---|
| `POST /user/login?_format=json` with `{"name": "…", "pass": "…"}` | 200 with the session cookie, and `{"current_user": {"uid": "2", "name": "…"}, "csrf_token": "…", "logout_token": "…"}`. A wrong password gives 400, and someone who is logged in already gets 403. |
| `GET /user/login_status?_format=json` | `1` when logged in, `0` if not. |
| `GET /session/token` | A CSRF token, as text: to get one again after the app reloads. |
| `POST /user/logout?_format=json&token={logout_token}` | 204: the session is over. |

Every request that changes something (`POST`, `PATCH`, `DELETE`) sends the
CSRF token as `X-CSRF-Token` (from the login, or from `/session/token`), and
`Content-Type: application/vnd.api+json`. Without the token the answer is 403.

### Resources

| Type and path | Attributes | Relationships |
|---|---|---|
| `node--horse`, `/jsonapi/node/horse` | `title`, `field_horse_type` (`own` or `livery`), `status` | |
| `node--inventory_item`, `/jsonapi/node/inventory_item` | `title`, `field_cost_category`, `field_default_price`, `field_unit`, `field_quick_add` (a one-tap button in the app; `false` by default) | |
| `node--expense`, `/jsonapi/node/expense` | `title`, `field_expense_date` (`2026-11-20`), `field_cost_category`, `field_total`, `field_quantity`, `field_unit_price` | `field_shared_with` (horses), `field_inventory_item`, `field_receipt` (a file) |

- Ids are UUIDs. Numbers with decimals are strings, such as `"12.35"`; send
  them as strings too.
- The cost categories: `vet`, `lesson`, `transport`, `farrier`, `feed`,
  `bedding`, `supplements`, `inventory` and `other`.
- Lists hold published content only (a horse that left the stable is
  unpublished), 50 at a time: follow `links.next`. For example, the quick adds
  are `GET /jsonapi/node/inventory_item?filter[field_quick_add]=1`, and a
  month of expenses, the newest first, with their horses and items (with `>=`
  and `<` URL-encoded):

  ```
  GET /jsonapi/node/expense
    ?filter[from][condition][path]=field_expense_date
    &filter[from][condition][operator]=>=
    &filter[from][condition][value]=2026-11-01
    &filter[to][condition][path]=field_expense_date
    &filter[to][condition][operator]=<
    &filter[to][condition][value]=2026-12-01
    &sort=-field_expense_date
    &include=field_shared_with,field_inventory_item
  ```

- Receipts are private files: `include=field_receipt` gives the file's
  `uri.url`, which the session can download.

The horses that share an expense, with each one's share and amount in the
`meta` of its resource identifier:

```json
"field_shared_with": {
  "data": [
    {
      "type": "node--horse",
      "id": "…",
      "meta": {
        "share": "75.00",
        "amount": "60.00",
        "drupal_internal__target_id": 1
      }
    },
    {
      "type": "node--horse",
      "id": "…",
      "meta": {
        "share": null,
        "amount": "20.00",
        "drupal_internal__target_id": 2
      }
    }
  ]
}
```

`share` is a percentage, or `null` for an equal part of what the percentages
leave. `amount` is worked out when the expense is saved, to the cent; it's
ignored when sent. The answer to a `POST` or `PATCH` gives the share as it was
sent (`"60"`), later reads as it's stored (`"60.00"`): read both as numbers.

### Adding, changing and deleting

The app makes the id of what it adds (`crypto.randomUUID()`), so that it can
save offline and send it later. `POST /jsonapi/node/expense`:

```json
{
  "data": {
    "type": "node--expense",
    "id": "5a0c9f5e-2b7d-4c1e-9d3a-6f1e8b2c4d70",
    "attributes": {
      "title": "Hay",
      "field_expense_date": "2026-11-20",
      "field_cost_category": "feed",
      "field_total": "12.35",
      "field_quantity": "1",
      "field_unit_price": "12.35"
    },
    "relationships": {
      "field_inventory_item": {
        "data": {"type": "node--inventory_item", "id": "…"}
      },
      "field_shared_with": {"data": [
        {"type": "node--horse", "id": "…", "meta": {"share": null}},
        {"type": "node--horse", "id": "…", "meta": {"share": null}}
      ]}
    }
  }
}
```

The answer is 201, with the expense under the same id and its amounts (here
6.18 and 6.17). The `meta` can be left out: no share.

**Sending it again** (the app doesn't know whether it arrived: it was offline,
or timed out) adds nothing: the answer is **409 Conflict**, `"Conflict: Entity
already exists."`. Treat 409 as saved. The request is checked before that, so
if something changed in between (a horse left), a repeat can get 422 instead;
when in doubt, `GET /jsonapi/node/expense/{id}`: 200 means it's saved.

- `PATCH /jsonapi/node/expense/{id}` sends only what changes; the amounts
  follow the total and the shares. `field_shared_with` replaces all horses.
- `DELETE /jsonapi/node/expense/{id}`: 204.
- Inventory items are added, changed and deleted the same way.
- Horses are changed with `PATCH`; one that leaves gets `"status": false`.
  They can't be deleted (403): their part of past costs stays.
- Content that isn't valid gives 422, with an `errors[].detail` for each
  problem: shares over 100 %, an unknown category, no horses, an id that isn't
  a UUID.

### What visitors see

Without a session, lists are empty (`"data": []`; `meta.omitted` links to
what was left out), and reading one item or changing anything gives 403.
JSON:API offers every entity type, as far as entity access allows: so
visitors do see what core treats as public, such as user names (only
`display_name`, at `/jsonapi/user/user`), blocks, date formats and menu
names. And `meta.omitted` gives the ids of what a visitor may not see, even
in an empty list.

The recipe can't close that with configuration: JSON:API has no setting for
it, and core lets everyone see a user's label. A site that wants it closed
requires a logged-in user on every JSON:API route, with a route subscriber in
a site module (the Stable Manager site does this). The services file:

```yaml
services:
  Drupal\my_module\Routing\JsonApiRouteSubscriber:
    tags:
      - { name: event_subscriber }
```

And `src/Routing/JsonApiRouteSubscriber.php`:

```php
final class JsonApiRouteSubscriber extends RouteSubscriberBase {

  protected function alterRoutes(RouteCollection $collection): void {
    foreach ($collection as $route) {
      // JSON:API flags all of its routes (`_is_jsonapi`), under any base path.
      if ($route->getDefault(Routes::JSON_API_ROUTE_FLAG_KEY)) {
        $route->setRequirement('_user_is_logged_in', 'TRUE');
      }
    }
  }

}
```

(`use Drupal\Core\Routing\RouteSubscriberBase`, `Drupal\jsonapi\Routing\Routes`
and `Symfony\Component\Routing\RouteCollection`.) Visitors then get 403 on
every JSON:API route, `/jsonapi` itself included. Logging in isn't a JSON:API
route, so `/user/login`, `/user/login_status` and `/session/token` stay open.

### Behind a proxy: the session cookie

An app can reach Drupal through a proxy on its own domain, such as Stable
Manager's PWA on Vercel, which proxies `/drupal/*` to Drupal: no CORS, but
the login seems to work and doesn't stay. `POST /user/login` answers 200, and
the next request is anonymous.

The cause is the session cookie's domain. Drupal sets it as `Domain=.<the host
it was asked for>` (`SessionConfiguration::getCookieDomain()`), and a proxy
asks for Drupal's own host. So the browser gets a cookie for Drupal's domain
from the app's domain, and rejects it. Two fixes; one is enough:

- **A host-only cookie**, in the Drupal site's `services.yml`:

  ```yaml
  parameters:
    session.storage.options:
      cookie_domain: ''
  ```

  The cookie then has no `Domain`: it belongs to whatever host the browser
  asked, the app's domain through the proxy and Drupal's own for its pages.
  The proxy forwards `Set-Cookie` and `Cookie` unchanged, and asks Drupal's own
  `Host`. The cookie's name no longer depends on the host name, so don't set
  this on several Drupal sites that are reached through one host name.
- **A proxy that rewrites the cookie**: without `Domain`, with `Path` set to
  the proxy's prefix (`/drupal`), and `Secure` on https. That's what the PWA's
  route handler does, and Drupal needs no change.

More about such a proxy:

- Drupal's `reverse_proxy` settings aren't needed when only the app's server
  calls Drupal: logging in, the cookie and the CSRF token don't depend on them.
  Without them, Drupal sees the proxy's address as the visitor's.
- So the login flood limits (`user.flood`: 50 failed logins an hour per IP
  address, 5 per account) are per proxy address, shared by all of the app's
  users.
- Links in JSON:API answers point at Drupal's own host, not at the proxy's:
  build the requests from the ids.

## The Stable Manager theme

A Starterkit-generated theme, without a base theme. See its
[README](themes/stable_manager_theme/README.md):

- an app shell with a collapsible sidebar, and a tab bar on phones;
- HTMX navigation and live filters with core's HTMX;
- cards and a stat card that counts up;
- charts in its colors, and dark mode;
- view transitions, which respect reduced motion;
- single-directory components and OOP hooks.

Drupal finds it in this repository (it scans module folders for themes too).
The front end recipe (so the demo too) installs it and imports its blocks.

## Development

The repository is a [DDEV](https://ddev.com) project with
[ddev-drupal-contrib](https://github.com/ddev/ddev-drupal-contrib):

```bash
ddev start
ddev poser              # Drupal core and the dev dependencies
ddev symlink-project    # the module into web/modules/custom
ddev phpcs              # Drupal and DrupalPractice coding standards
ddev phpstan            # level 8, no baseline
ddev cspell             # spelling, with core's dictionaries
ddev stylelint          # the theme's CSS, with core's config
ddev eslint             # the theme's JavaScript, with core's config
ddev phpunit            # Unit, Kernel and Functional tests
ddev phpunit --testsuite functional-javascript   # in Chrome (Selenium)
```

The JavaScript tests run in the Selenium container of
[ddev-selenium-standalone-chrome](https://github.com/ddev/ddev-selenium-standalone-chrome).
Their accessibility check uses axe-core from core's JavaScript tools: run
`yarn install` in `web/core` once (`ddev cspell` and the linters do that too).

The Kernel tests also run on PostgreSQL and SQLite in CI (set `SIMPLETEST_DB`).
Coverage of `src/` and the theme's `src/` by the Unit and Kernel tests is kept
above 90 %:

```bash
ddev exec php -d pcov.enabled=1 -d pcov.directory=. vendor/bin/phpunit \
  --testsuite unit,kernel --coverage-text
```

The recipe tests apply each recipe twice (it must be idempotent), check the
dashboard's numbers on costs dated relative to today, check the role, and check
that the Dutch recipe leaves no English label behind.

Visual regression tests take screenshots of the demo site (each page, on a
laptop in light and dark, and on a phone) and compare them with the approved
ones in [`tests/visual/screenshots`](tests/visual/screenshots). They run in
Playwright's Linux image, in Docker, so they match CI's:

```bash
ddev drush site:install minimal --yes
ddev drush recipe /var/www/html/recipes/stable_manager_demo
tests/visual/run.sh                       # compare
tests/visual/run.sh --update-snapshots    # approve a change
```

The pages name their month, so they don't change with today's date; the
dashboard's numbers and charts (this month, this year) are masked. In CI, the
screenshots that differ are kept as the `visual-differences` artifact.

### Releasing

Work happens on `develop`. A release is `develop` merged into `main` and
tagged `vX.Y.Z` (an annotated tag, like the earlier ones). Sites install the
module from the tags of this repository, so **nothing reaches a site before it
is committed, pushed and tagged**; and a site's `web/modules/contrib/shared_costs`
is only a copy that Composer made (it's in the site's `.gitignore`): change the
module here, never there, because the next `composer update` throws a change
there away.

Before you start something here, and again before you say "done", run
`git status` and `git log origin/develop..HEAD`: work that sits uncommitted in
this checkout is easy to forget, and the sites don't have it.

To release:

```bash
git status                          # nothing left over
ddev phpcs && ddev phpstan && ddev cspell && ddev stylelint && ddev eslint
ddev phpunit                        # CI runs the same, and more
git switch main && git merge --ff-only develop
git tag -a vX.Y.Z -m "Shared Costs X.Y.Z"
git push origin main develop vX.Y.Z
git switch develop
```

The number: a patch for fixes that need nothing from a site (`2.1.1`), a minor
for a new feature or recipe change (`2.2.0`), a major for a rename or anything
that breaks a site (`3.0.0`: see [Upgrading from 1.0](#upgrading-from-10)).
When a release changes what a recipe installs or configures, write an
_Upgrading from X.Y_ section for it, like the ones above: the modules to
`composer require`, and how an existing site gets the changed configuration
(see [Updating a site](#updating-a-site)).

Then, on each site that uses it, `composer update drupal/shared_costs` and do
what the upgrade section says. The `composer.json` of a site requires a range
(`^2.1`), so a minor release is picked up without editing it.

## Known issues

- **Default content and default values.** Core's content importer creates the
  entity (which fills in the field's default value) and then sets the imported
  items by position, so default sharers beyond the imported ones remain. This
  affects every multi-value field with a default value; that's why the demo
  recipe doesn't set one. Set it yourself after importing. Reported as
  [#3635887](https://www.drupal.org/project/drupal/issues/3635887).
- **Drupal 12.** On 12.0.0-beta2 (PHP 8.5) the module and its tests pass.
  The recipes need contrib modules that don't declare Drupal 12 support yet:
  Prepopulate, Field Group and Views Data Export (CI tries them on Drupal 12
  anyway), and
  Charts 5.2.4 renders its charts empty there (its preprocess function isn't
  found on Drupal 12; it needs core's `initial preprocess`, reported as
  [#3635886](https://www.drupal.org/project/charts/issues/3635886)). The front
  end recipe, and so the demo, needs Drupal 11 for now: it uses contrib Gin,
  which extends Claro, and the Toolbar. Drupal 12 has Gin in core instead
  (Default Admin), and no Claro or Toolbar. CI's Drupal 12 job leaves contrib
  Gin out, so the front end and demo tests fail there (the job may fail).
- **Charts and config schema.** Charts 5.2.4 has no schema for its views
  style's `path` and `library_type_options` options; the recipe's views leave
  them out, so they validate. Saving a chart view in the Views UI adds them
  again, which only matters for strict schema checks (tests). Reported as
  [#3635885](https://www.drupal.org/project/charts/issues/3635885).

## Maintainers

- [Robbe Saelens](https://robbesaelens.github.io)
