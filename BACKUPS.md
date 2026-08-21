# Database backups

Nightly dumps of the MySQL `agent` database, via
[spatie/laravel-backup](https://spatie.be/docs/laravel-backup).

This exists because on **2026-08-21** the entire local `agent` database was destroyed by
`migrate:fresh` and none of the four standard recovery routes was available: no dump, no
binary logs (`log_bin = OFF`), no physical remnant, no backup job. Everything below is
aimed at making that unrecoverable state impossible to reach again.

## Run a backup by hand

```
php artisan backup:run --only-db
```

That is the command to reach for before anything risky — a migration, a rollback, a
schema change, or any test run you are not certain about.

Other useful commands:

| Command | What it does |
| --- | --- |
| `php artisan backup:list` | What is on disk, how old, and whether it is healthy |
| `php artisan backup:clean` | Apply the retention policy now |
| `php artisan backup:monitor` | Exit non-zero if backups are stale or oversized |

## Where backups go

`backups/` in the project root — **outside** `storage/`, and gitignored.

Outside `storage/` on purpose: a backup living inside the tree it protects is one
`storage:clear`, one bad deploy or one wiped volume away from being worthless.
Gitignored on purpose: these dumps contain real customer data and must never reach the
repository.

Each backup is a zip containing one file, `db-dumps/mysql-agent-<timestamp>.sql`.

## Schedule

In `routes/console.php`:

- `backup:clean` — daily at 01:00
- `backup:run --only-db` — daily at 01:30

Cleanup runs first so the disk is pruned before the new dump lands, keeping the peak
footprint one backup lower.

### ⚠️ The schedule does not run itself locally

Laravel's scheduler only fires where something invokes `schedule:run` every minute. On
this OSPanel dev machine **nothing does** — the same caveat already noted for
`queue:watchdog`. Until the task below exists, backups happen **only when you run the
command by hand.**

To close the gap, in an **elevated** PowerShell:

```powershell
$action  = New-ScheduledTaskAction -Execute 'php.exe' `
    -Argument 'artisan schedule:run' -WorkingDirectory 'C:\OSPanel\home\agent'
$trigger = New-ScheduledTaskTrigger -Once -At (Get-Date) `
    -RepetitionInterval (New-TimeSpan -Minutes 1)
Register-ScheduledTask -TaskName 'velora-schedule' -Action $action -Trigger $trigger
```

Check `php artisan backup:list` a day later to confirm it is actually firing. Verify
this rather than assume it — an unverified backup is a guess, and a guess is what
caused the original data loss.

## Retention

Package defaults, which are sensible for a dump this size:

- all backups for 7 days
- one per day for 16 days
- one per week for 8 weeks
- one per month for 4 months
- one per year for 2 years
- oldest deleted past 5000 MB

## Restoring

There is no `backup:restore` command — restoring is manual and deliberate.

```powershell
# 1. Unzip the backup you want
Expand-Archive 'backups\Velora Agents\<timestamp>.zip' -DestinationPath restore-tmp

# 2. Restore into MySQL. THIS OVERWRITES THE CURRENT DATABASE.
& 'C:\OSPanel\modules\MySQL-8.0\bin\mysql.exe' -u root agent `
    -e "source restore-tmp/db-dumps/mysql-agent-<timestamp>.sql"

# 3. Clean up — the extracted dump is unencrypted customer data
Remove-Item restore-tmp -Recurse -Force
```

Restore into a scratch database first if you are checking a backup rather than
recovering from a real loss.

## Machine-specific notes

Two things about this setup are workarounds for this OSPanel install, both recorded in
the config beside the code they affect:

- **`dump_binary_path` points at MySQL 8.0, not 8.4.**
  `C:\OSPanel\modules\MySQL-8.4\bin\mysqldump.exe` is corrupt — it crashes with an
  access violation (`0xC0000005`) even on `--version`, while `mysql.exe` in the same
  directory runs fine. The 8.0 client dumps an 8.4 server correctly. MariaDB's
  `mysqldump` builds are also present under `C:\OSPanel\modules` and are deliberately
  **not** used: their output does not cleanly restore into MySQL. Override with
  `DB_DUMP_BINARY_PATH` in `.env`; a real deployment with `mysqldump` on `PATH` can set
  it empty.

- **No gzip compression on the dump.** `GzipCompressor` shells out to a `gzip` binary
  that does not exist on Windows and fails the whole run. The zip already compresses.

## Notifications are OFF

This machine has no working mailer, and the default `mail` channel would turn every
nightly run into an unhandled exception — a worse failure mode than silence.

**The consequence is that a failing backup is silent.** `php artisan backup:list` is
the thing to check. When a mailer is configured, re-enable `BackupHasFailedNotification`
and `UnhealthyBackupWasFoundNotification` first — those two are the ones that matter.

## What is NOT backed up

Only the database. The application code is in git, so it is excluded deliberately.

The gap: **uploaded knowledge-source files** under `storage/app/private/knowledge/`,
which are neither in git nor in the dump. As of 2026-08-21 those are 257 test fixtures
totalling 10 KB and worth nothing. If real customer documents ever land there, add
`storage_path('app/private')` to `backup.source.files.include` in `config/backup.php`.
