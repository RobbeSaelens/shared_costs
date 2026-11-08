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
