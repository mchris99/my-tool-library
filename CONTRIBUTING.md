# Contributing to My Tool Library

Thanks for your interest in improving My Tool Library.

## Before you start

For anything beyond a small fix, please open an issue first to discuss the change — especially for anything touching the database schema, member data handling, or the public-facing (no-JavaScript) pages, since those have deliberate constraints documented in `readme.txt`.

## Development setup

1. Clone the repo into a local WordPress install's `wp-content/plugins/` directory (e.g. via [LocalWP](https://localwp.com/)).
2. Activate the plugin and run **My Tool Library > Setup > Run Database Setup**.
3. Optionally load `my-tool-library/documentation/dummy-data.sql` for test data.

## Guidelines

- Public-facing pages must keep working with JavaScript fully disabled.
- Member passwords must always go through WordPress core (`wp_insert_user()`); never store credentials in the plugin's own tables.
- Every PHP file should start with the `ABSPATH` guard used throughout the codebase.
- Follow the existing code style in the file you're editing.
- Update `readme.txt`'s Changelog section for user-facing changes.

## Submitting a change

1. Fork the repo and create a branch for your change.
2. Keep pull requests focused on a single change.
3. Describe what changed and why in the PR description.
