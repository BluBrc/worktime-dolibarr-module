# Dolibarr module "Time Tracking" (worktime)

Version 1.3.0 · for **Dolibarr 23.0 (tested)** · PHP 8.1+ · module number 500210

With this module employees record their working time directly inside Dolibarr — no separate tool needed. The UI is fully translated into German and English and follows each user's Dolibarr profile language.

## Features

- **Time clock**: clock in, clock out, start/end break at the push of a button (AJAX, no page reload). Every entry is automatically assigned to the logged-in user. Duplicate or illogical bookings are rejected, e.g. two "clock in" entries in a row or "clock out" during an active break.
- **Work-time account**: target, actual and balance are calculated per day and accumulated into a running overtime/minus-hours account.
- **Weekly and monthly reports**: daily overview with entries, breaks, target/actual, public holidays and absences. Missing clock-outs are flagged. Administrators can add and delete entries.
- **Vacation**: employees file requests that an administrator or their direct supervisor approves or rejects. Overlaps and requests beyond the quota are prevented. Remaining, taken, requested and carried-over days are displayed.
- **Sickness**: sick reports are approved immediately. Above a configurable duration (default: 3 days) a medical certificate is required; its arrival can be ticked off.
- **Administration**: default weekly hours and vacation days, per-user values (including carry-over and the start date of the time account), public holidays per region plus custom holidays, carry-over expiry deadline, and certificate requirement from X days.
- **Timezones**: clock times are stored as UTC and displayed in each user's timezone (see "Notes").
- UI in German and English, following each user's Dolibarr language.

## Installation

### Option A – Upload via the Dolibarr interface
1. Log in as administrator.
2. Open **Home → Setup → Modules/Applications** and switch to the **"Deploy external module"** tab.
3. Upload the file `worktime-1.3.0.zip`.
   (This requires `$dolibarr_main_document_root_alt` in `conf/conf.php` to point to `htdocs/custom`, which the default installation already does.)
4. Then activate the module (see below).

### Option B – Manual unpacking
1. Unpack the ZIP so the `worktime` folder sits at: `<dolibarr>/htdocs/custom/worktime/`
   ```bash
   cd /path/to/dolibarr/htdocs/custom
   unzip worktime-1.3.0.zip
   chown -R www-data:www-data worktime   # adjust to your web server user
   ```
2. In `conf/conf.php` check that the custom directory is active:
   ```php
   $dolibarr_main_url_root_alt = '/custom';
   $dolibarr_main_document_root_alt = '/path/to/dolibarr/htdocs/custom';
   ```

### Activating the module
**Home → Setup → Modules/Applications**, in the **Human Resources (HR)** section enable **"Time tracking"**. This creates the database tables automatically:
`llx_worktime_entries`, `llx_worktime_absences`, `llx_worktime_settings`.

A top menu **"Working time"** then appears with Time clock, Weekly report, Monthly report, Absences and Settings.

## Assigning permissions

Under **Users & Groups → User → "Permissions" tab** (or for a group):

| Permission | Meaning |
|---|---|
| View own working times (read) | View time clock and own reports |
| Clock in/out/break and request absences (write) | Book in/out/break, report vacation and sickness |
| Time tracking administration (admin) | View all employees, correct entries, approve requests, settings |

New users get *read* and *write* by default. *admin* should only be given to HR staff.

**Approval by supervisors:** if the Dolibarr field **"Supervisor"** on a user's card is set, that person can approve the employee's vacation requests even without admin rights.

## Settings

**Working time → Settings** (or module list → gear icon):

- **General**: default weekly hours (e.g. 40), default vacation days (e.g. 30), holiday rule, vacation carry-over deadline (format `MM-DD`, e.g. `03-31`), certificate requirement from X sick days.
- **Users**: differing weekly hours and vacation days, carry-over from the previous year and the time-account start date per user.
- **Holidays**: custom holidays, one entry per line. `YYYY-MM-DD` applies once, `MM-DD` every year (e.g. `12-24` for Christmas Eve). Below that is a preview of the current year's holidays.

## Calculation rules

- **Daily target** = weekly hours ÷ 5 (Monday to Friday). Saturdays, Sundays and holidays have a target of 0.
- **Actual** = sum of all times from clock-in to clock-out minus breaks. For an open day the calculation runs up to the current moment.
- Approved **vacation** and **sickness** count as fulfilled target (balance 0). Vacation days are only counted on workdays — holidays and weekends are not.
- The **work-time account** sums the daily balances from the account's start date (default: the user's first entry).
- **Remaining vacation** = annual entitlement + carry-over − days taken. Unused carry-over expires after the configured deadline.

## Notes

- **Timezones**: clock times are stored as UTC in the database and displayed in the timezone of the logged-in user. The timezone comes from the user's browser (Dolibarr mechanism, `$_SESSION['dol_tz_string']`); the PHP setting `date.timezone` is not relevant. When an administrator back-posts an entry for someone, the entered time is interpreted in the administrator's own timezone. Entries saved before version 1.2.0 are read as UTC and remain valid.
- **Uninstall**: deactivating the module keeps the data. Data is only removed when the tables are dropped manually.
- **Compatibility**: Dolibarr 23.0 (tested with Dolibarr 23.0.0, PHP 8.2 and MariaDB 10.11). Minimum requirements per module: Dolibarr 23.0 and PHP 8.1. Older Dolibarr versions (14–22) are no longer supported; Dolibarr refuses activation there.

## Changes in version 1.1.0 (Dolibarr 23 adaptation)

- Raised minimum versions to Dolibarr 23.0 and PHP 8.1, `module_position` as integer.
- Menus: conditions via `isModEnabled('worktime')` and `$user->hasRight(...)`, no `prefix` on the main menu anymore.
- Configuration is read via `getDolGlobalString()` / `getDolGlobalInt()` instead of `$conf->global`.
- Rights checks via `$user->hasRight()` instead of `$user->rights->...`, module check via `isModEnabled()`.
- Integer parameters are read via `GETPOSTINT()`.
- The database column `llx_user.statut` remains unchanged in the SQL queries because that is still its name in Dolibarr 23 (only the PHP attribute is called `$user->status`).

## Changes in version 1.2.0 (timezones)

- Fixed: clock in/out/break times were stored and displayed in the PHP server timezone instead of the user's timezone (on servers with `date.timezone=UTC` all times were off by the offset).
- `entry_time` is stored explicitly as UTC; display and day boundaries (today, week/month boundaries) use the logged-in user's timezone via Dolibarr's `tzuserrel` mechanism.

## Changes in version 1.3.0 (i18n)

- All remaining hardcoded German UI strings (holiday names, holiday-rule labels, module descriptor texts) were moved into the language files; German and English are now fully selectable per user — the module follows each user's Dolibarr profile language.

## Directory structure

```
worktime/
├── core/modules/modWorktime.class.php   Module definition (rights, menus, constants)
├── sql/                                 Tables and indexes
├── class/                               Business logic (times, absences)
├── lib/worktime.lib.php                 Holidays, formatting, rights helpers
├── ajax/timeclock.php                   JSON endpoint of the time clock
├── index.php                            Time clock
├── report_weekly.php / report_monthly.php
├── absence.php                          Vacation & sickness
├── admin/setup.php                      Settings
├── css/, js/, img/, langs/
```
