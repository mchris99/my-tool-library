# Contributing to My Tool Library

Thanks for your interest in improving My Tool Library.

## Before you start

For anything beyond a small fix, please open an issue first to discuss the change — especially for anything touching the database schema, member data handling, or the public-facing (no-JavaScript) pages, since those have deliberate constraints documented in `readme.txt`.

## Development setup

1. Clone the repo into a local WordPress install's `wp-content/plugins/` directory (e.g. via [LocalWP](https://localwp.com/)).
2. Activate the plugin and run **My Tool Library > Setup > Run Database Setup**.
3. Optionally load `my-tool-library/documentation/dummy-data.sql` for test data.

## Guidelines

- Public-facing pages must not use JavaScript. Use plain links, forms and CSS, and do any checking on the server.
- Member passwords must always go through WordPress core (`wp_insert_user()`); never store credentials in the plugin's own tables.
- Every PHP file should start with the `ABSPATH` guard used throughout the codebase.
- Adding a table? Add it to `mtl_export_table_names()` in `admin/setup-page.php` (parents before children) as well as to `admin/schema.sql` and `mtl_maybe_upgrade_schema()`. Export Data and Restore from Backup only cover the tables in that list, so a table left out is silently missing from every backup. New columns need nothing extra: the export reads every column, and the restore loads older backups into the current table shape.
- The `.sql` export and Restore from Backup share a file format. If you change what `mtl_write_sql_export()` writes, update the reader (`mtl_restore_from_sql()` and the `mtl_restore_*` helpers) to match, and check that a backup made before your change still restores.
- Automatic backups (`admin/auto-backups.php`) wrap that same dump in encryption. Never change the encrypted layout in place: bump the version in `MTL_BACKUP_MAGIC` and keep `mtl_backup_decode()` able to read every earlier version, or libraries lose access to the backups they already have. Test on a host without the sodium extension too, where WordPress's polyfill runs instead.
- Before Go Live, customers see only the Coming soon page (`admin/go-live.php`). A new public page added to `mtl_handle_front_pages()` is closed then without any extra work. A new email that links into the public pages should return early while `! mtl_library_is_live()`, as `mtl_send_member_setup_email()` does, or it sends people to a page they can't open.
- Follow the existing code style in the file you're editing.
- Update `readme.txt`'s Changelog section for user-facing changes.

## Submitting a change

1. Fork the repo and create a branch for your change.
2. Keep pull requests focused on a single change.
3. Describe what changed and why in the PR description.
