# Shared costs

A Drupal field that splits an amount over other entities, equally or by
percentage, and stores each part in cents that always add up to the total.
Views can then add the parts up per sharer and per month.

It comes with **Stal Manager**: recipes that turn a Drupal site into a
cost tracker for a horse stable (expenses with receipts, inventory, a
dashboard with charts, a monthly overview, a statement per horse and CSV
exports), in English or Dutch, with a JSON:API for apps. Stal Manager started
as a Next.js app; the recipes rebuild it with configuration only, on top of
this one small module. Its front-end theme, in [`themes/stal_manager_theme`](themes/stal_manager_theme),
makes it an app: HTMX navigation, live filters, view transitions and dark
mode. Forms and admin pages use Gin.

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Using the field](#using-the-field)
- [Views](#views)
- [The Stal Manager recipes](#the-stal-manager-recipes)
- [JSON:API (for apps)](#jsonapi-for-apps)
- [The Stal Manager theme](#the-stal-manager-theme)
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
  - [Views Data Export](https://www.drupal.org/project/views_data_export) 1.10,
    for the CSV download;
  - a [private file system](https://www.drupal.org/docs/8/core/modules/file/overview#content-accessing-private-files)
    for receipts: set `$settings['file_private_path']` in `settings.php`.
- For the demo recipe: [Gin](https://www.drupal.org/project/gin) 5,
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

## The Stal Manager recipes

Four recipes, in [`recipes/`](recipes):

| Recipe | What it does |
|---|---|
| `stal_manager` | Horses, inventory items and expenses with their receipts (content types and fields), nine cost categories, the views below, and a _Stable manager_ role. Doesn't touch themes or site settings, so it can go on an existing site. |
| `stal_manager_demo` | `stal_manager` on a new site to try it: the Stal Manager theme, Gin (in Stal Manager's brown) for forms and admin pages with Admin Toolbar's dropdown menus, the dashboard as front page, the site private (visitors get the login form), and two horses with a year of example costs. |
| `stal_manager_nl` | `stal_manager` in Dutch: Dutch as the default language, with Dutch labels, categories and number formats (€ 1.234,56). |
| `stal_manager_api` | `stal_manager` for apps: JSON:API, for stable managers logged in with a normal session. See [JSON:API (for apps)](#jsonapi-for-apps). |

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

Horses are never deleted (the role can't): a horse that leaves the stable is
unpublished, so its share of past costs stays.

To try it on a new site:

```bash
composer require drupal/shared_costs drupal/charts \
  drupal/prepopulate drupal/views_data_export \
  drupal/gin drupal/gin_toolbar drupal/admin_toolbar
drush site:install minimal
# Receipts are private files: in settings.php, set a folder outside the web
# root, such as $settings['file_private_path'] = '../private';
drush recipe modules/contrib/shared_costs/recipes/stal_manager_demo
# Optional: in Dutch.
drush recipe modules/contrib/shared_costs/recipes/stal_manager_nl
drush locale:check && drush locale:update
# Optional: the JSON:API for apps.
drush recipe modules/contrib/shared_costs/recipes/stal_manager_api
```

Recipes can't import interface translations, so `stal_manager_nl` ends with a
`locale:update`: it loads this module's `translations/nl.po` and core's Dutch
translation from localize.drupal.org. The Chart.js library is loaded from a CDN
by default; see the Charts module to serve it yourself.

Not ported from the Next.js app: an expense that counts in full for every
horse (add one expense per horse instead). Working offline is left to the app
itself (a PWA), with this site as its backend: see `stal_manager_api`.

## JSON:API (for apps)

`stal_manager_api` opens Stal Manager to apps, such as its own mobile app (a
PWA that works offline), through core's
[JSON:API](https://www.drupal.org/docs/core-modules-and-themes/core-modules/jsonapi-module).
Apps log in as people do, with Drupal's session cookie rather than OAuth: so
an app reaches Drupal on its own site, for example through a proxy at
`/drupal/*`, without CORS. The recipe:

- applies `stal_manager`, installs JSON:API and lets it write (`read_only:
  false`);
- lets the _Stable manager_ role see the horses, inventory items and expenses,
  add, change and delete expenses and inventory items, and change horses,
  including unpublishing one that leaves the stable (_Administer node published
  status_);
- takes every permission from visitors that aren't logged in, as the demo
  does: JSON:API lists all they may see, and costs are private.

The tests in
[`StalManagerApiRecipeTest`](tests/src/Functional/Recipe/StalManagerApiRecipeTest.php)
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
names. To close `/jsonapi` to visitors altogether, refuse it in front of
Drupal, or in a site module.

Links in the answers point at Drupal's own address: behind a proxy, build the
app's requests from the ids, not from those links.

## The Stal Manager theme

A Starterkit-generated theme, without a base theme. See its
[README](themes/stal_manager_theme/README.md):

- an app shell with a collapsible sidebar, and a tab bar on phones;
- HTMX navigation and live filters with core's HTMX;
- cards and a stat card that counts up;
- charts in its colors, and dark mode;
- view transitions, which respect reduced motion;
- single-directory components and OOP hooks.

Drupal finds it in this repository (it scans module folders for themes too).
The demo recipe installs it and imports its blocks.

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
ddev drush recipe /var/www/html/recipes/stal_manager_demo
tests/visual/run.sh                       # compare
tests/visual/run.sh --update-snapshots    # approve a change
```

The pages name their month, so they don't change with today's date; the
dashboard's numbers and charts (this month, this year) are masked. In CI, the
screenshots that differ are kept as the `visual-differences` artifact.

## Known issues

- **Default content and default values.** Core's content importer creates the
  entity (which fills in the field's default value) and then sets the imported
  items by position, so default sharers beyond the imported ones remain. This
  affects every multi-value field with a default value; that's why the demo
  recipe doesn't set one. Set it yourself after importing. Reported as
  [#3635887](https://www.drupal.org/project/drupal/issues/3635887).
- **Drupal 12.** On 12.0.0-beta2 (PHP 8.5) the module and its tests pass.
  The recipes need contrib modules that don't declare Drupal 12 support yet:
  Prepopulate and Views Data Export (CI tries them on Drupal 12 anyway), and
  Charts 5.2.4 renders its charts empty there (its preprocess function isn't
  found on Drupal 12; it needs core's `initial preprocess`, reported as
  [#3635886](https://www.drupal.org/project/charts/issues/3635886)). The demo recipe
  needs Drupal 11 for now: it uses contrib Gin, which extends Claro, and the
  Toolbar. Drupal 12 has Gin in core instead (Default Admin), and no Claro or
  Toolbar.
- **Charts and config schema.** Charts 5.2.4 has no schema for its views
  style's `path` and `library_type_options` options; the recipe's views leave
  them out, so they validate. Saving a chart view in the Views UI adds them
  again, which only matters for strict schema checks (tests). Reported as
  [#3635885](https://www.drupal.org/project/charts/issues/3635885).

## Maintainers

- [Robbe Saelens](https://robbesaelens.github.io)
