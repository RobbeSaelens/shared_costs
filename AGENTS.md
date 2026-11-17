# Working in this repository

Shared Costs is a Drupal module with the Stable Manager recipes and theme. The
README has the details; this file is what an agent must not skip.

## Run every CI check before you commit

CI must stay green, so before every commit run **everything the pipeline
runs**, after your last edit (documentation, recipes and comments count, not
only PHP):

```bash
ddev phpcs && ddev phpstan && ddev cspell && ddev stylelint && ddev eslint
ddev phpunit --testsuite unit,kernel,functional
```

- `ddev phpcs` checks `README.md` and other text files too: 80 characters a
  line at most. `ddev cspell` spell-checks them; add words that are fine to
  `.cspell-project-words.txt`.
- A check that passed before your last edit proves nothing: run it again.
- When a change touches the theme, a form or a recipe, also run the Chrome
  tests (`ddev phpunit --testsuite functional-javascript`) and the visual tests
  (`tests/visual/run.sh`). See "Before you commit" in the README.
- If a check fails, fix the cause. Don't commit, and don't loosen the check.

## Where changes go

- Work happens on `develop`; see "Releasing" in the README.
- A site's `web/modules/contrib/shared_costs` is a copy that Composer made.
  Change the module in this repository, never in that copy: the next
  `composer update` throws the change away.

## Frontend changes

For every change to how something looks, moves or fits on a phone (the theme,
its components, CSS or JavaScript), use these Claude Code skills, and say in
the commit which ones you applied:

- `impeccable-layout` (Impeccable's layout reference): reading order,
  grouping, rhythm, density, adaptation and extremes. Name the spatial thesis
  before you edit. Verify with rendered evidence: the screenshots of
  `tests/visual/run.sh` (desktop, dark and phone), not just the CSS.
- `emil-design-eng`, `review-animations` and `remove-animations` (Emil
  Kowalski's): whether something should move at all, which curve, how long.
- `mobile-native` (Emil Kowalski's): hover, taps, fields and safe areas on a
  phone. What only a real phone shows, say so; don't call it fixed from an
  emulator.

They are installed in `~/.claude/skills`, from
[emilkowalski/skills](https://github.com/emilkowalski/skills) and Impeccable's
[layout reference](https://github.com/pbakaus/impeccable/blob/main/skill/reference/layout.md)
(its `impeccable detect` CLI isn't: do that scan by hand, for one-off spacing,
overflow, `z-index` and DOM order against visual order).

The rules they gave this theme, which hold until a better reason comes:

- **Tokens, not one-off values**, in `css/base/tokens.css`: colors, type,
  spacing (`--space-*`), motion (`--duration-*`, and Emil's strong curves
  `--ease-out` and `--ease-in-out`) and stacking (`--z-*`).
- **Motion is quick and earns its place.** What answers a person takes under
  300ms, starts fast (`--ease-out`), and moves only `transform`, `translate`,
  `scale` or `opacity`. Only toasts and dialogs take longer
  (`--duration-slow`). Nothing moves for what happens tens of times a day or
  as you type: a page swap is a short cross-fade, filtering is instant, and
  numbers and charts stay still (they are read, not watched). No hover effect
  on what can't be clicked. Reduced motion turns all of it off
  (`css/theme/animations.css`).
- **Phones**: every `:hover` sits in `@media (hover: hover) and (pointer:
  fine)`, every control has an `:active` state, controls take
  `touch-action: manipulation` and `user-select: none` (content stays
  selectable), and fields are 16px on touch screens (smaller makes iOS zoom
  in).
- **Layout**: proximity before containers, `gap` for siblings, columns that
  line up across repeated tables, and structure that adapts (reflows,
  collapses, hides what the phone doesn't need) rather than shrinks.

**Drupal first, always.** CSS and JavaScript go in the theme's libraries
(`stable_manager_theme.libraries.yml`) or a single-directory component
(`components/`); behaviors use `Drupal.behaviors` and `once()`; markup changes
go through templates and OOP hooks; strings are translatable; no build step,
no framework, and core's stylelint, ESLint and Prettier configs as they are.
When a skill's example needs React, Motion or Tailwind, keep its reason and do
it the Drupal way. When a skill and core's accessibility or markup disagree,
core wins.

Not done yet, because it needs a real phone to check: `viewport-fit=cover`
(the `env(safe-area-inset-*)` paddings in `css/layout/app.css` are 0 until
then), and updating `theme-color` when the theme toggle overrides the system.
