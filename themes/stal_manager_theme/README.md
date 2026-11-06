# Stal Manager theme

The front-end theme of the Stal Manager recipes: an app-like interface for
the stable's costs, in Stal Manager's warm palette (cream, saddle brown,
honey), light or dark.

Generated from core's Starterkit, so it doesn't depend on a base theme.

## What it does

- **App shell.** A sidebar with the branding, the main navigation, "Add an
  expense", the theme toggle and the account links. It collapses to its icons,
  and remembers that. On phones it becomes a top bar, a tab bar at the bottom
  and a round "add" button.
- **HTMX navigation** with core's HTMX. The navigation, the list of months and
  the pager are boosted: their links fetch the page, but only `#main-content`
  (and the navigation and messages, out of band) is swapped in, with a view
  transition. The URL and the history follow, focus moves to the new content
  and screen readers hear its title. Without JavaScript they are ordinary
  links.
- **Live filters.** The expenses' filters update the results as you type or
  pick, keep the filters in the URL and leave the focus where it is. Without
  JavaScript, "Apply" still works. The _Download CSV_ button goes with the
  results, so it always downloads what the filters show.
- **Cards.** The Stal Manager views (`stable_*`) get a layout:
  - On the dashboard, the overview and a horse's statement, the main number
    (this month's costs, the month's total, the horse's total) is a stat card
    that counts up to its value, next to a grid of cards.
  - On the other pages, the actions come first, then the results in a card.
- **Charts** in the theme's colors: a Chart.js plugin restyles the Charts
  module's charts, and redraws them when the theme changes.
- **Printing.** A page prints without the app around it: no sidebar, buttons
  or toasts, in black on white. A horse's statement has a _Print_ button.
- **Dark mode** that follows the system, or the toggle. A small inline script
  sets it before the page paints, so it doesn't flash.
- **Motion.** Cards rise in one by one, pages swap with view transitions and
  messages slide in as toasts that can be closed. Status messages go by
  themselves. All of it is off for people who ask for reduced motion.

## How it is built

- **Single-directory components** in `components/`: `card`, `stat-card`
  (count-up), `icon` (from an inline sprite of line icons drawn for this
  theme) and `theme-toggle`.
- **OOP hooks** in `src/Hook/`, autowired: the page, the navigation (HTMX
  attributes from core's `Htmx` builder, icons, the current page) and the
  views (layouts, the stat card, the live filters).
- **CSS** in layers of files, as Drupal attaches them: design tokens with
  `light-dark()` (`css/base/`), the app shell (`css/layout/`), components and
  motion. Linted with core's stylelint and eslint configs.
- **No build step**, no dependencies beyond core: the theme works with the
  Charts module when it's there.

## Tests

In the module's tests:

- `Kernel/ThemeHooksTest`: the hooks and the rendered markup.
- `Functional/Theme`: the pages as served, without JavaScript.
- `FunctionalJavascript`: in Chrome. It covers:
  - HTMX navigation and history;
  - live filters;
  - the theme toggle and the charts' colors;
  - the sidebar, the count-up and the messages;
  - printing a statement;
  - an axe scan of every page in light and dark (WCAG 2.1 A and AA).
