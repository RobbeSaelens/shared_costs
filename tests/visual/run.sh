#!/usr/bin/env bash
#
# Runs the visual tests on the DDEV site, in Playwright's Linux image: the
# same browser and fonts as in CI. Arguments go to Playwright, for example:
#   tests/visual/run.sh --update-snapshots    # approve the new screenshots
#
# Needs the demo recipe applied (see the README). Logs in as "visual-tests",
# a stable manager without a password that it creates if needed.
set -euo pipefail
cd "$(dirname "$0")"

version=$(sed -n 's/.*"@playwright\/test": "\(.*\)".*/\1/p' package.json)
site=$(ddev exec printenv DDEV_HOSTNAME | tr -d '\r')

if ! ddev drush user:information visual-tests >/dev/null 2>&1; then
  ddev drush user:create visual-tests >/dev/null
fi
ddev drush user:role:add stable_manager visual-tests >/dev/null
LOGIN_URL=$(ddev drush user:login --name=visual-tests --uri="http://$site" --no-browser /stable)
export LOGIN_URL
export BASE_URL="http://$site"

# amd64 everywhere: CI runs on it, and fonts render a little differently on
# arm64.
docker run --rm --platform linux/amd64 --ipc=host \
  --add-host "$site:host-gateway" \
  -e LOGIN_URL -e BASE_URL -e CI \
  -v "$PWD":/work -w /work \
  "mcr.microsoft.com/playwright:v$version-noble" \
  sh -c 'npm ci --no-audit --no-fund --silent && npx playwright test "$@"' -- "$@"
