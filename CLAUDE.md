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
- **Tests run against `build/`, not `src/`.** `BlockRegistrar` registers blocks from
  `build/blocks/`, so editing a `src/blocks/*/render.php` has no effect on any test
  until `npm run build` copies it across. This matters most when checking that a test
  genuinely fails without its fix: mutate a `render.php`, skip the build, and the test
  still passes — which reads as "the test is worthless" when it is actually fine.
  Rebuild before drawing that conclusion. PHP under `includes/` is loaded directly and
  needs no build step, which is why a mixed batch can have some mutations take effect
  and others silently not.
- **Assume a new test proves nothing until it has failed once.** Run it against the
  unfixed code before keeping it. Two real examples from the 2026-09-23 audit pass:
  a fixture that set `post_password` via `wp_update_post()` triggered `save_post`,
  which deleted the index row under test, so the assertion passed because the row was
  gone rather than because the query excluded it; and `WP_UnitTestCase`'s post factory
  invents a `post_excerpt`, so an ICS test that does not blank it exercises the
  manual-excerpt branch and never reaches the content-trimming code it was written for.
- Tests that assert on scheduled events need to clear the cron array in `set_up()`.
  Saving an event queues a deferred index build under the `cron` generation strategy,
  so leftovers from earlier tests can satisfy a precondition on their own. A test that
  passes alone and fails in the full suite is usually this.

- **Both suites fail on PHP errors that used to scroll past.** `npm run test:e2e` records
  the dev site's `debug.log` length before any test runs (the `debug-log-mark` project)
  and fails after the last one if a PHP notice, warning, deprecation, fatal or
  `WordPress database error` was appended (`debug-log-check`). That covers every request
  the suite makes, every `wpCli()` fixture command, and WP-Cron firing from a page load —
  all of them boot WordPress with `WP_DEBUG` on. Ctrl-C ends a run before the check; it
  exits non-zero anyway. `npm run test:integration` goes through
  `bin/test-integration.js`, which scans PHPUnit's stderr for the same shape and fails on
  a hit even when PHPUnit said OK — that is where errors raised outside a test (plugin
  load, `Schema::create_tables()`) and database errors end up. A line that is genuinely
  not ours belongs in `ALLOWLIST` in `tests/debug-log.js`, with a reason.
- **The tests site runs `WP_DEBUG` on and logs to stderr.** wp-env's default for the tests
  environment is off, which masks `E_NOTICE` and `E_DEPRECATED` before PHPUnit's handler
  ever sees them — the suite had never reported a raw PHP notice or an 8.x deprecation.
  `.wp-env.json` `env.tests.config` turns it on and sets `WP_DEBUG_LOG` false so
  `error_log` stays at the CLI default `/dev/stderr`, where the wrapper reads it.
  `phpunit-integration.xml` converts deprecations to exceptions and treats output during
  a test as risky, so a database error inside a test fails that test by name.
  `ErrorConversionTest` guards all of this.
- **wp-env's `ℹ`/`✔` status lines go to stderr.** `execFileSync` returns stdout, so they
  never appear in `wpCli()` output; they do appear in the wrapper's stderr buffer, which
  is why the error pattern is anchored on `PHP <level>:` and never on a bare `Warning`.
- **Benchmark scripts live in `bin/bench/`** and run with
  `npx wp-env run cli -- wp eval-file wp-content/plugins/blockendar/bin/bench/<name>.php`.
  The plugin directory is already mounted into every container, so nothing needs copying
  anywhere. Each script's first statement is the `WP_CLI` guard (a Jest test enforces it);
  the directory is served over HTTP like the rest of the plugin, so `.htaccess` denies it
  as well. Scripts are tracked and linted.

## Version bumps

When bumping the plugin version, update **all** of the following in one commit:

1. `blockendar.php` — plugin header `Version:` and `BLOCKENDAR_VERSION` constant
2. `package.json` — `"version"` field
3. Every `src/blocks/*/block.json` — `"version"` field
4. `README.md` — add a new `### X.Y.Z` section under `## Changelog`
5. `readme.txt` — bump `Stable tag:`, add a new `= X.Y.Z =` section under `== Changelog ==`, and add an `== Upgrade Notice ==` entry
6. `demo-plugin/blockendar-demo.php` — plugin header `Version:` and `BLOCKENDAR_DEMO_VERSION` constant
7. `demo-plugin/package.json` — `"version"` field
8. `demo-plugin/readme.txt` — bump `Stable tag:` and add a `= X.Y.Z =` changelog section

The companion demo plugin ships as its own zip from the same tag, so its version
tracks the main plugin's. `BLOCKENDAR_DEMO_MIN_BLOCKENDAR` is separate: it is the
oldest Blockendar whose APIs the seeder uses, and only moves when that changes.

The changelog lives in `README.md` and `readme.txt` only — do not create a separate `CHANGELOG.md`.
