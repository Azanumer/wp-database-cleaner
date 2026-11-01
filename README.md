# Database Cleaner

Scheduled database housekeeping for WordPress — the rows that pile up silently
and slow down `wp_options` autoload and backups.

## What it cleans (weekly, via WP-Cron)

- **Expired transients** — value rows and their timeout rows.
- **Orphaned postmeta** — meta rows whose post no longer exists.
- **Spam comments** and **trashed comments**.
- **Old revisions** — older than N days (default 30; set 0 to keep all).
- **Auto-drafts** — abandoned editor drafts.

## Safety

- Tools → Database Cleaner shows a **dry-run report** (exact row counts) before
  anything is deleted.
- The weekly run only touches the categories you enabled in Settings on the
  same page.
- The touched tables are `OPTIMIZE`d after each run.
- Last-run results are stored in the `wdc_last_run` option.

## Installation

1. Upload the plugin folder to `wp-content/plugins/`.
2. Activate it — the weekly schedule is created automatically.
3. Review the dry-run report under Tools → Database Cleaner.

## License

MIT — see `LICENSE`.
