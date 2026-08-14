# Blockendar — Claude Instructions

## Commits

When creating git commits:

- **Do NOT add `Co-Authored-By: Claude` or any Claude co-author line** to commit messages
- Always run the relevant checks before committing:
  - **PHP lint:** `npm run lint:php`
  - **PHP unit tests:** `npm run test:php`
  - **JS lint:** `npm run lint:js`
  - **CSS lint:** `npm run lint:css`
  - **JS tests:** `npm run test:js`
  - **JS build:** `npm run build`
- Fix any errors before proposing a commit.

All six are expected to pass cleanly, with no warnings.

Two further suites need a running `wp-env` (`npm run env:start`), so run them when
touching the code they cover rather than on every commit:

  - **Integration tests:** `npm run test:integration` — REST permission callbacks,
    meta sanitizers, and the EventIndex query/cache layer, against real WordPress
  - **E2E tests:** `npm run test:e2e` — the calendar-view block in a browser

## Testing notes

- `npm run test:php` runs Brain Monkey unit tests with **no WordPress loaded**, so
  they pass on any core version and cannot catch a WordPress regression. Anything
  touching capabilities, REST, or the database belongs in `tests/Integration/`.
- PHPUnit is pinned to **9.x on purpose**. The WordPress core test suite calls
  `PHPUnit\Util\Test::parseTestMethodAnnotations()`, which was removed in PHPUnit 10,
  so upgrading past 9.x breaks the integration suite.

## Version bumps

When bumping the plugin version, update **all** of the following in one commit:

1. `blockendar.php` — plugin header `Version:` and `BLOCKENDAR_VERSION` constant
2. `package.json` — `"version"` field
3. Every `src/blocks/*/block.json` — `"version"` field
4. `README.md` — add a new `### X.Y.Z` section under `## Changelog`
5. `readme.txt` — bump `Stable tag:`, add a new `= X.Y.Z =` section under `== Changelog ==`, and add an `== Upgrade Notice ==` entry

The changelog lives in `README.md` and `readme.txt` only — do not create a separate `CHANGELOG.md`.
