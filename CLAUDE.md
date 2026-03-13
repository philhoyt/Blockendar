# Blockendar — Claude Instructions

## Commits

When creating git commits:

- **Do NOT add `Co-Authored-By: Claude` or any Claude co-author line** to commit messages
- Always run the relevant checks before committing:
  - **PHP lint:** `npm run lint:php`
  - **JS build:** `npm run build`
- Fix any errors before proposing a commit.

## Version bumps

When bumping the plugin version, update **all** of the following in one commit:

1. `blockendar.php` — plugin header `Version:` and `BLOCKENDAR_VERSION` constant
2. `package.json` — `"version"` field
3. Every `src/blocks/*/block.json` — `"version"` field
4. `README.md` — add a new `### X.Y.Z` section under `## Changelog`
5. `readme.txt` — bump `Stable tag:`, add a new `= X.Y.Z =` section under `== Changelog ==`, and add an `== Upgrade Notice ==` entry

The changelog lives in `README.md` and `readme.txt` only — do not create a separate `CHANGELOG.md`.
