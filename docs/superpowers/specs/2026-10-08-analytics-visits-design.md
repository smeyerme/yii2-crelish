# Analytics: Correct Visit Counts and a Verifying Cleanup — Design

Date: 2026-10-08
Status: Draft for review
Target release: crelish 0.25.0
First consumer: crelish.forum-holzkarriere

## 1. Purpose

Two defects found in an audit of the analytics pipeline make the numbers wrong
in ways nobody notices:

1. **"Unique" figures are summed across things they must not be summed over.**
   `unique_sessions` / `unique_users` are distinct per row of a daily aggregate
   (one page, or one element + event type + page_uuid, on one day). The read side
   adds them up across pages, elements and event types. A visitor who sees ten of
   a company's jobs in a list counts ten times. The company KPI shown to customers
   (web and PDF report) is such a sum.
2. **The cleanup only checks that some aggregate row exists before deleting raw
   data.** A day whose aggregates are present but wrong passes. The page
   aggregation bug fixed in 0.24.2 (2,511 views stored as 49) ran for months
   past this check; once raw data is deleted the loss is permanent.

Customer-facing company reports have priority; the admin statistics follow the
same rules.

### Decisions taken during brainstorming

| Topic | Decision |
|---|---|
| Whose numbers matter most | Company reports (customer-facing); admin view follows the same rules |
| Meaning of "unique" | **Visits**: a visitor counts once per day, however many pages or elements they saw that day; summed over the days of a period. Distinct people across days are not measurable (most visitors carry no cookie; raw data is kept 30 days) |
| How visits are stored | New daily table filled by the nightly job (approach 1), not computed on demand from raw data (only 30 days) and not per-session lists |
| Cleanup on mismatch | Repair (recompute the day), verify again, delete only if it now passes; otherwise keep the raw data and report |
| Late-detected bots | Stored numbers never shrink: stored ≥ raw is accepted; only stored < raw (undercount) is an error |
| Scope | Company reports, admin page/element statistics, cleanup. Not: partner statistics, short-link statistics, the monthly aggregation's 31-day gap |

## 2. Data model

New table `analytics_visits_daily`:

| Column | Type | Meaning |
|---|---|---|
| `id` | PK | |
| `date` | DATE NOT NULL | The day |
| `source` | VARCHAR(16) NOT NULL | `pages` (from `analytics_page_views`) or `elements` (from `analytics_element_views`) |
| `owner_uuid` | VARCHAR(36) NOT NULL DEFAULT '' | `''` = whole site; otherwise an owner: the `page_uuid` an element view carries, or the company owning the element (see below) |
| `event_type` | VARCHAR(32) NOT NULL DEFAULT '' | `''` = any event; otherwise `list`, `detail`, `click`, `download`, … |
| `unique_sessions` | INT NOT NULL DEFAULT 0 | Distinct sessions that day |
| `unique_users` | INT NOT NULL DEFAULT 0 | Distinct logged-in users that day |
| `created_at`, `updated_at` | TIMESTAMP | As in the other aggregate tables |

Unique key `(date, source, owner_uuid, event_type)`; index on
`(owner_uuid, date)` for company queries.

Rows written per day:

| source | owner_uuid | event_type | Used for |
|---|---|---|---|
| pages | '' | '' | Admin page totals and page trend |
| elements | '' | '' and each event type | Admin element totals and per-event-type totals |
| elements | each owner | '' and each event type | Company reports |

**Owners** follow the rule the company report already uses for its view counts
(`page_uuid` = company OR element owned by the company): a session counts for an
owner when it saw an element whose `page_uuid` is the owner, or an element the
owner owns. Ownership comes from the project's
`@app/config/analytics-element-types.php`: every listed table with a `company`
column maps its rows' `uuid` to `company`. A session that matches an owner both
ways counts once. At forum-holzkarriere jobs carry their company in `page_uuid`
and in `job.company`; at forum-holzbranche `page_uuid` is never the company (0
of ~25,000 element views in 7 days), so without the ownership half every company
there would show no visits. Comparisons between `uuid`/`company` and the raw
columns convert both sides to `utf8mb4_unicode_ci`, because project tables mix
`utf8mb3` and `utf8mb4` and general/unicode collations.

Expected size at forum-holzkarriere: a few hundred rows per day.

Bot filtering matches the existing aggregates so that visits and views line up:
page visits filter `analytics_page_views.is_bot = 0`; element visits join
`analytics_sessions` with `is_bot = 0`. Element rows without a `page_uuid` only
contribute to the site rows.

The migration `m261008_120000_create_analytics_visits_daily` checks whether the
table exists before creating it (older crelish migrations do not, which makes
them unsafe to rerun).

## 3. Aggregation (write side)

`actionDaily($date)` computes the visit rows for the day after the page and
element aggregates.

- **Write semantics, normal mode** (the nightly run for yesterday, and
  `backfill` started explicitly): for the day's visit rows of a source, DELETE
  then INSERT, in one transaction. No stale rows survive; a rerun gives the same
  result.
- **Write semantics, repair mode** (used by the cleanup, §5): no DELETE; every
  aggregate row, in the three tables `daily` writes (`analytics_page_daily`,
  `analytics_element_daily`, `analytics_visits_daily`), is upserted with
  `GREATEST(stored, recomputed)` for counts. Repair only ever raises numbers.
- **Selecting parts:** new option `--only=pages,elements,visits` (comma list)
  for `daily`, `monthly` and `backfill`. Default: all. `--pagesOnly=1`, released
  in 0.24.2, stays as an alias for `--only=pages`. `monthly` ignores `visits`
  (there are no monthly visit rows; monthly visits are the sum of daily ones).
- **Missing table** (migration not run yet): `daily` skips visits with a
  warning on stderr and continues; it does not fail the nightly job.

## 4. Read side

| Where | Today | New |
|---|---|---|
| Company report: visits KPI (web, PDF) | SUM of per-element, per-event-type `unique_sessions` | SUM over the days of the period of the company's row (`elements`, owner = company, event `''`) |
| Company report: trend chart | Same overcount per day | That row per day |
| Admin overview: sessions KPI and its % change against the previous period | SUM of `analytics_page_daily.unique_sessions` over all pages | Site row `pages`, summed over days |
| Admin overview: trend chart, daily | SUM over all pages per day | Site row `pages` per day |
| Admin overview: trend chart, monthly | SUM of `analytics_page_monthly.unique_sessions` over all pages | Site row `pages`, summed per month |
| Admin element detail: sessions and users KPIs | SUM across event types and pages of one element | The element's `detail` rows only, labelled "Besuche (Detailansicht)"; there are no per-element visit rows to combine event types |
| Admin page detail, top pages table | Per page, summed over days | Unchanged; per page and day this already counts visits |

Element-type and event-type distributions (admin) and event-type and content
stats (company) compute summed uniques but never display them; they are left
as they are.

The labels say what the figure is: "Besuche" / "Visits" (counted per day),
replacing "Unique Sessions" where the figure changes.

View counts (`total_views`) and the company filter for them (`page_uuid` =
company OR element owned by the company) are unchanged; the company's visit
rows follow the same rule (§2).

**Periods without visit data:** visit rows exist from the day they were first
computed (at rollout: backfilled 30 days, i.e. from 2026-09-08 at
forum-holzkarriere). If a period starts earlier, the visits figure covers only
the days with data and the UI says so ("Besuche erfasst ab 08.09.2026"). The old
summed value is never shown as a fallback. If the table does not exist, the UI
shows "Besuche noch nicht erfasst".

## 5. Cleanup

1. **Whole days.** The cutoff becomes midnight: raw rows of day D are deleted
   only when all of D is older than `retentionDays`. Today's cutoff ("now minus
   30 days" to the second) deletes the early hours of one day each night, which
   leaves a partial day that cannot be verified or repaired.
2. **Per-day verification** replaces `findUnaggregatedDays()` / `findGapDays()`.
   For each day in the deletion range:
   - pages: `SUM(analytics_page_daily.total_views)` vs raw `COUNT(*)` with `is_bot = 0`
   - elements: `SUM(analytics_element_daily.total_views)` vs raw `COUNT(*)` joined to sessions with `is_bot = 0`
   - visits (if the table exists): site rows `pages` and `elements` (event `''`) vs raw `COUNT(DISTINCT session_id)`, filtered the same way

   A day passes when every stored value ≥ its raw value (late-detected bots make
   raw smaller; that is accepted). A day with no reportable raw traffic passes.
3. **Repair.** A day that fails is recomputed with `daily` in repair mode (§3),
   then verified again.
4. **Delete or keep.** Days that pass (initially or after repair) are deleted.
   Days that still fail keep their raw data; they are listed on stderr (the cron
   mail) and the command exits non-zero. Each day is decided independently.
5. **Order within the run:** verify and repair all days first, then delete the
   passing days. Orphaned element views and sessions are deleted as today: no
   aggregate counts them (all element aggregation joins sessions), so removing
   them never affects a verification.
6. `--dryRun` prints each day's result (pass / repaired / kept) without changing
   anything. `--skipAggregationCheck=1` stays the explicit override that deletes
   without verifying.

## 6. Error handling

- `daily`: each part (pages, elements, visits) runs in its own try/catch and
  transaction; a failure in one no longer skips the others (today an element
  error returns before the page step). Any failed part makes the command exit
  non-zero so the cron mail reports it.
- Cleanup: a failed verification query for a day counts as a failed day (kept,
  reported); it never counts as passed.

## 7. Testing

- **Unit tests** (crelish's SQLite harness, `tests/`): the deletion-range
  calculation (whole days, retention boundary), the per-day decision table
  (pass / repair / keep, including stored > raw and no-traffic days), the
  GREATEST merge in repair mode, the `--only` / `--pagesOnly` option parsing.
  The aggregation SQL itself is MySQL-specific (ON DUPLICATE KEY, YEAR()) and
  cannot run there.
- **Integration check against a MySQL copy** (forum-holzkarriere local database,
  raw data back to 2025-04):
  - visit rows = raw distinct sessions per day, owner and event type;
  - company KPI and trend through the new read path for a sample of companies;
  - cleanup dry run over the old data, then a real run; rerun gives the same
    result; repair never lowers any aggregate (checksum per table before/after);
  - the full nightly sequence (bot detection → daily → cleanup) once, totals
    compared before and after.

## 8. Rollout

1. Release crelish 0.25.0; check from the production host that Packagist serves
   it before deploying.
2. forum-holzkarriere: deploy; `yii crelish-migrate/up`;
   `yii crelish/analytics-aggregation/backfill 30 --only=visits`; verify visit
   rows against raw for every day; a person checks one company report in the
   admin.
3. Other portals on their next deploy: migration, `backfill 30 --only=visits`,
   and the 0.24 `/crelish-api` frontend check.

## 9. Known limitations (not in this release)

- Partner statistics (`PartnerAnalyticsService`, `actionPartnerRankings`) still
  sum `unique_sessions` across rows.
- Short-link statistics keep their figure, already labelled "per day, summed".
- The monthly aggregation recomputes from raw data and loses the first hours of
  a 31-day month (cleanup runs the night before).
- Cookieless visitors get a new session per request, so a visit is closer to a
  page request than to a browsing session for them; bot detection keeps ~64% of
  sessions as "medium" confidence and counts them.

## 10. Follow-up: browser confirmation for server-side tracking (separate design)

Page and element views are recorded server-side while rendering
(`CrelishFrontendController::trackPageView`, `chelper.trackElementView`). That
is deliberate: tracking blockers and browsers that block trackers by default
cannot suppress it, and it needs no cookies. Its weaknesses: every request that
renders a page is counted, including bots that never run JavaScript, and human
views served from the LiteSpeed page cache (no PHP runs) are missed.

Switching to browser-only tracking (as Plausible, Umami and Google Analytics
do) would lose blocker users again: EasyPrivacy and similar lists block known
tracker domains and also known script names and paths on first-party domains
(`/collect`, `/analytics/`, `/track`, ...). So the follow-up keeps server-side
recording and adds the browser only as a confirmation:

- The server keeps recording every view, as now; blocker and no-JS visitors stay counted.
- A small first-party script under a neutral name and path confirms the view it
  belongs to (one-time id, `sendBeacon`, no cookie). A confirmed view is a
  strong human signal for bot detection and should shrink the "medium" band;
  bots that do not run JavaScript never confirm.
- An unconfirmed view is not treated as a bot by itself (it may be a blocker
  user); the existing heuristics decide, as today.
- A confirmation for a page served from cache, which the server never
  recorded, creates the view, closing the cache gap.
- Visitor identity becomes a daily-salted hash of IP, user agent and site
  instead of a new session per cookieless request (same privacy model: first
  party, no cookies).

The visits table and the verifying cleanup of this release stay as they are;
only their input improves.
