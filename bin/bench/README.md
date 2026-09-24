# Benchmark scripts

Throwaway measurements that back a decision in `plans/` — index shapes, cache keys,
query plans. They live here so they can be re-run when the question comes up again,
and so nothing gets copied into the plugin root to reach a container.

## Running one

```sh
npx wp-env run cli -- wp eval-file wp-content/plugins/blockendar/bin/bench/<name>.php
```

The repository is mounted at `wp-content/plugins/blockendar` in every wp-env container,
so that path already exists — no mapping, no copying, no restart.

## Writing one

Start from `example.php`. The first statement in every script must be

```php
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}
```

This directory is inside the plugin, which wp-env serves over HTTP like everything else
under `wp-content/plugins/`. The guard makes a direct request exit before anything runs;
`.htaccess` denies the request outright where Apache honours it. A Jest test
(`tests/bench-guard.test.js`) fails if a script is missing the guard.

Prefix every global with `blockendar_bench_`, and `WP_CLI::log()` results rather than
echoing them. Scripts are linted by `npm run lint:php` with the database sniffs relaxed
for this directory, since running raw SQL is usually the point.

## What not to do

Do not seed or drop tables on a site you care about — several of these rewrite the index.
Use the dev site, or a throwaway container, and say which in the script's header.
