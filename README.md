# SYWAT Archery Tournament Management Plugin

An enterprise-grade, multi-tenant, theme-neutral WordPress plugin built for the Texas Field Archery Association (TFAA) and SYWAT Archery to manage indoor archery tournaments, shooter intake, line capacities, waitlist queues, score tracking, season standings leaderboards, shop directory management, member & board administration, public season schedules, member online self-registration, and financial accounting across any WordPress theme (Astra App mode and Divi Theme mode).

---

## 🛠️ Key System Features & Architecture

- **Theme-Neutral Integration & Dual Layout Modes**: Runs cleanly under Astra (App mode) and Divi (Theme mode with `--tfaa-*` child theme tokens) via `includes/core/class-sywat-frontend.php`, with conditional asset loading, optimizer opt-outs (WP Rocket / Cloudflare / LiteSpeed), page-builder placeholders, `filemtime()` script cache-busting, and `Cache-Control: no-cache` headers on operational screens.
- **Public Season Schedule & Role-Gated Navigation**: Live chronological schedule board (`[sywat_schedule]`) with List View and Monthly Calendar View, real-time district/shop filtering, automated event statuses, and synchronized **Register Online** (`[sywat_member_register]`), **Opens [Date]** lead-time badges, or **View Results** action buttons across both views. Features a clean, role-gated `Tournaments ▾` dropdown (`Register Online`, `Season Schedule`, `Results`, and staff-gated `Tournament Portal`).
- **Public Online Registration Gatekeeper & Member Self-Registration (`[sywat_member_register]`)**: Shop-level default online registration settings (`public_reg_default_enabled`, `public_reg_default_lead_days`) inherited by new tournaments with per-event overrides (`sywat_get_public_registration_status()`). Logged-in TFAA members can self-register themselves and linked household family members with automatic tournament-date NFAA age division & distance detection (`20yd` vs. `Cub`), live line availability counts, automatic `Waitlist` placement (`$0.00` due) when a line is full, First-Time/Lapsed Shooter promos, and Household Family Caps.
- **Multi-Tenant Shop & Indoor Range Scoping**: Isolate shooting lines, rosters, and financial reports by range host/location.
- **Dynamic Shooting Line & Distance Capacity Engine**: Enforces independent 20yd and Cub (10yd) physical lane limits with automatic waitlist overflow tracking (`all` vs. `remainder` family triage).
- **Dedicated Waitlist Queue & Per-Line $75.00 Family Cap Engine**: Centralized FIFO family-clustered waitlist management (`includes/waitlist/`) with on-demand `#sywat_waitlist_modal` promotion, top-bar multi-select line moves, split-family reconciliation, and strict per-line $75.00 Family Cap recalculation.
- **Household-Wide Balance Verification & Historical Auto-Fill at Check-In**: Enforces unpaid family balances across all household members sharing a line (`⚠️ OWE: FAMILY`) and automatically populates shooter Style, Division, and Gender from 18-month TFAA history and shop registration caches.
- **Sticky Operational Context Across Portals**: Synchronizes the user's active tournament selection (`sywat_desk_selected_tournament` user meta + `?selected_tournament=` navigation tab carryover) across **Register Shooters**, **Check In Archers**, and **Enter Scores**.
- **Phone & Group Registration Intake**: Quick caller intake form supporting multi-archer groups, per-line $75 family capping rules, FareHarbor CSV bulk imports, specific per-tournament online registration links, and direct line roster/waitlist management links.
- **Waitlist-Aware Roster Sub-System**: Unified roster management interface (`includes/roster/`) with deep-linked line filtering, Pre-Reg/Waitlist status switching, real-time waitlist status badging (`🕒 Waitlist`), line purges, profile editing, and apostrophe-safe registration removal.
- **Member & Board Directory Sub-System**: Unified member management interface (`includes/members/`) featuring household sub-profiles, unassigned archer pools, chunked AJAX spreadsheet imports (`Archers.xlsx` / CSV), multi-role capabilities, shop assignments, password policy validation, and automated TFAA Board role synchronization.
- **Live Scoring & Leaderboard Engine**: Multi-category sorting (Division, Gender, Style), Guest style assignments for unturned cards, season standings leaderboards, background AJAX score auto-save, and instant CSV export capability.
- **Financial & Host Fee Accounting**: Configurable Pricing Profiles, automatic 50/50 host fee splits, family cap adjustments, embedded Base64 Zelle QR code generation, and printable invoice renderers.

---

## 📂 Sub-System Architecture & Module Reference

### 1. Core Plugin Loader & Integration Layer (`/` & `includes/core/`)
- **`sywat-archery-manager.php`** (v7.2.0) — Master WordPress plugin entry point. Initializes constants (`SYWAT_PLUGIN_DIR`, `SYWAT_PLUGIN_URL`, `SYWAT_PLUGIN_VERSION = 7.2.0`), registers activation hooks, and bootstraps core integration and modular sub-systems.
- **`includes/core/class-sywat-permissions.php`** (v3.6.0) — Central roles, capabilities, and board position synchronization engine (`sywat_can_manage_tournaments()`, `sywat_can_edit_scores()`, `sywat_can_access_desk()`, `sywat_can_create_members()`, `sywat_can_manage_board()`).
- **`includes/core/class-sywat-frontend.php`** — Central theme-neutral integration layer handling shortcode page detection (`sywat_shortcode_tags()`, including `[sywat_member_register]`), conditional CSS/JS enqueueing (never site-wide or in `wp-admin`), optimizer opt-out attributes, Divi Visual Builder placeholders, `sywat_page_url()` routing (including the `member_register` -> `/register-online/` key), and `Settings > SYWAT Display` Layout mode.

### 2. Assets & Styling (`assets/`)
- **`assets/css/sywat-core.css`** — Central `:root` design tokens (`--sywat-*: var(--tfaa-*, <default>)`), scoped input rules (`:is(.sywat-wrap, .sywat-app, .sywat-scope, .sywat-modal-backdrop)`), card containers (`.sywat-card`), card-docked tab tracks (`.sywat-nav-tabs`), spacing utilities (`.sywat-mt-3`, `.sywat-mb-4`, `.sywat-ml-0`, `.sywat-hidden`), and alert banners.
- **`assets/css/sywat-tables.css`** — Responsive table layout engine (`.sywat-table-responsive`, `.sywat-table`), column width modifiers (`.sywat-col-w-10` through `.sywat-col-w-32`), row hover states, isolated action wrappers (`.sywat-modal-roster-actions`), and centered vertical stacking containers (`.sywat-table-actions-vertical`).
- **`assets/css/sywat-modals.css`** — Tokenized modal stacking variables (`--sywat-z-modal`, `--sywat-z-modal-nested`, `--sywat-z-modal-dropdown`, `--sywat-z-modal-alert`), modal backdrops (`.sywat-modal-backdrop`), dialog dimensions (`.sywat-modal-sm` through `.sywat-modal-xl`), scrollable bodies, and pinned header/footer structures.
- **`assets/css/sywat-buttons.css`** — Themeable action buttons (`.sywat-btn`, `.sywat-btn-primary`, `.sywat-btn-secondary`, `.sywat-btn-success`, `.sywat-btn-danger`), `.sywat-btn-full` block stretch rules, icon badges, and status pills (`.sywat-badge-open`, `.sywat-badge-full`, `.sywat-waitlist-pill`).
- **`assets/css/sywat-toolbar.css`** — Authentication header bar (`.sywat-auth-bar`), integrated card header toolbars (`.sywat-card-header-bar`), table control headers (`.sywat-table-header-bar`), search input groups (`.sywat-search-input-group`), and TFAA sync pills (`.sywat-sync-pill`).
- **`assets/css/sywat-public.css`** — Public leaderboard displays, schedule layouts, division cards (`.sywat-div-gen-card`), search input wrappers, and participation stat banners (`.sywat-counter-banner`).
- **`assets/js/`** — Vanilla JS, jQuery-free, optimizer-safe client controllers (`window.SYWAT.<module>` + delegated `data-sywat-action` listeners):
  - `sywat-modal-manager.js`: Relocates top-level `.sywat-modal-backdrop` elements to `<body>` (escaping Divi transforms/fixed headers), manages dialog ARIA roles, focus trapping, Escape-to-close, scroll lock, and print isolation.
  - `sywat-schedule.js` (v1.4.0): Vanilla JS engine for live filtering, List/Calendar view switching, instant month navigation, jump-to-weekend scrolling, and event details modals with synchronized **Register Online** and **Opens [Date]** controls.
  - `sywat-waitlist.js` (v2.0.0): Namespaced vanilla JS module managing `#sywat_waitlist_modal` lifecycle, line filter navigation, live search filtering, and family/member checkbox synchronization.
  - `sywat-checkin.js` (v6.7.0): Dynamic check-in search, 18-month TFAA + shop history auto-fill for Style/Division/Gender, family group prompts, and modal toggles.
  - `sywat-roster.js` (v6.8.1): Delegated roster modal controls (`window.SYWAT.roster`), confirmation dialogs (`data-sywat-confirm`), line-scoped family group dropdown updates, division/style restriction enforcement, and class-driven live search filtering.
  - `sywat-members.js` (v5.3.0): Client JavaScript for the Member sub-module handling household/board modals, chunked AJAX import/purge progress streams, and live autocomplete filtering.
  - `sywat-tfaa-reconcile.js` (v1.0.0): Namespaced client controller (`window.SYWAT.reconcile`) for the temporary post-import TFAA Gender & Pro reconciliation modal (`#sywat_tfaa_reconcile_modal`).
  - `sywat-registration.js` (v6.4.0): Multi-archer dynamic row insertion, distance auto-switch, and group balance calculation.
  - `sywat-scores.js` (v7.2.0): Batch score entry, offline draft caching, and background AJAX auto-save.
  - `sywat-tournaments.js` (v6.8.2): Tournament line template repeaters, season modal handlers, and async TFAA sync progress tracking.
  - `sywat-shops.js` (v6.8.3): Shop location editing tabs and CSV/Excel import file preview handlers.

### 3. Authentication & Access Control (`includes/auth/`)
- **`sywat-auth-actions.php`** (v1.1.0) — Authentication form handlers, login/logout routing, and session security checks.
- **`sywat-auth-views.php`** (v5.8.0) — Role-restricted administrative wrappers, top navigation header (`.sywat-auth-bar`) with Head of Household profile dropdown, access denied view notices, and the role-gated `Tournaments ▾` menu (`Register Online`, `Season Schedule`, `Results`, and staff-only `Tournament Portal`).

### 4. Archer Check-In Desk Sub-System (`includes/checkin/`)
- **`class-sywat-checkin.php`** (v6.1.1) — Sub-module controller for live desk intake (`[sywat_desk]`) with `filemtime()` cache-busting on `sywat-checkin.js`.
- **`checkin-actions.php`** (v6.9.2) — Check-in toggles, household-wide unpaid family balance verification & clearance, delegated Waitlist modal actions, and roster modifications with per-line Family Cap reconciliation.
- **`checkin-data.php`** (v6.8.4) — Fast DB queries retrieving active registrations, sticky user tournament preferences (`sywat_desk_selected_tournament`), payment balances, and check-in statuses.
- **`checkin-modals.php`** (v6.6.0) — Check-in, Edit Archer, and Walk-In overlay modals enforcing household-wide unpaid family balance banners and staff verification checkboxes across all members of an unpaid family group.
- **`checkin-views.php`** (v6.9.3) — Main Check-In Desk table view featuring sticky tournament tab carryover, clickable Waitlist badges/buttons, real-time search, household-wide `⚠️ OWE: FAMILY` badges, family grouping alerts, and fast check-in action buttons.

### 5. Archer Registration Engine (`includes/registration/`)
- **`class-sywat-registration.php`** (v6.1.0) — Lightweight controller shell for staff intake (`[sywat_register]`) and the public Member Online Registration portal (`[sywat_member_register]`).
- **`registration-actions.php`** (v7.2.0) — Phone intake processing, multi-archer group registration, active-only per-line family cap logic, waitlist triage (`all` vs. `remainder`), and atomic multi-store persistence.
- **`registration-import.php`** (v7.2.0) — FareHarbor CSV/text import file parsing and shooter auto-creation.
- **`registration-views.php`** (v6.9.1) — Main registration layout container with sticky tournament selection (`sywat_desk_selected_tournament`), navigation tab carryover, and on-demand Waitlist modal rendering.
- **`registration-modals.php`** (v7.1.0) — Renders Phone Intake Form, FareHarbor Bulk Upload Form, and Current Line Slot Usage Table with deep links into `class-sywat-roster.php` and clickable Waitlist badges/buttons into `#sywat_waitlist_modal`.
- **`registration-scripts.php`** (v7.1.0) — Localized script loader for intake calculation and line override listeners.
- **`member-registration-data.php`** (v1.0.0) — Pure database/option/user-meta data access layer retrieving open tournaments (`sywat_get_public_registration_status()`), scoped tournament registrations, raw Head of Household & linked dependent records, and active division records.
- **`member-registration-helpers.php`** (v1.1.0) — Pure calculation & formatting layer resolving tournament-date NFAA age divisions, target distances (`20yd` vs. `Cub`), formatted household rosters, per-line slot availability maps, existing registration status maps, and complete view payloads (`sywat_member_reg_build_view_data()`).
- **`member-registration-actions.php`** (v1.0.0) — Nonce-verified POST handler (`sywat_member_self_register`) validating open-tournament status, line locks, and household ownership; blocking duplicates; booking open slots as `Pre-Reg` (balance due at check-in) or automatically placing full-line entries on `Waitlist` (`\$0.00` due); evaluating First-Time/Lapsed Shooter promos and Household Family Caps; and persisting records atomically.
- **`views/view-member-registration.php`** (v1.0.0) — Pure HTML view template for `[sywat_member_register]` rendering guest sign-in prompts, post-submission confirmation/waitlist banners, open tournament selection, live line availability tables, and household self-registration forms.

### 6. Archer Roster Sub-System (`includes/roster/`)
- **`class-sywat-roster.php`** (v5.0.0) — Master sub-module loader shell for archer roster management, division/style restriction enforcement, line-scoped family group assignments, and line purges.
- **`roster-views.php`** (v6.9.1) — Registered Archer Roster management modal (`#sywat_roster_modal`, rendered via `modal_action=manage`). Features `sywat_page_url()` form routing, line dropdown filtering, live name searching, direct header trigger to open `#sywat_waitlist_modal`, line purges (`purge_line`), native `<button type="button">` close triggers, and delegated `data-sywat-confirm` archer removal forms.
- **`roster-modals.php`** (v6.9.0) — Nested modal overlay (`#sywat_edit_archer_modal`) for updating archer profiles, Pre-Reg vs. Waitlist registration status, line assignments, divisions, styles, payment statuses, and line-scoped family group assignments.
- **`roster-actions.php`** (v6.9.0) — POST request action handlers for saving archer edits (`save_edit`), purging full line rosters (`purge_line`), and deleting archer registrations (`delete_shooter`) with per-line $75.00 Family Cap reconciliation, `wp_unslash()` slug normalization, and multi-store `wp_options` cleanup.
- **`roster-scripts.php`** (v5.0.0) — Enqueues `assets/js/sywat-roster.js` and localizes line-scoped family group maps (`window.sywatRosterData`) for dynamic dropdowns in the edit modal.

### 7. Waitlist Queue Management & Family Cap Engine (`includes/waitlist/`)
- **`class-sywat-waitlist.php`** (v2.0.0) — Central controller loader binding waitlist data access, per-line $75.00 Family Cap helpers, manual promote/move/remove action handlers, dedicated `#sywat_waitlist_modal` rendering, and `filemtime()` client script enqueuing.
- **`waitlist-data.php`** (v1.0.0) — Pure read-only data layer computing per-line/distance open capacities and building FIFO-ordered, family-clustered waitlist queues with split-family reconciliation metadata.
- **`waitlist-helpers.php`** (v1.1.0) — Pure business-rule helpers for Youth/Adult rate evaluation, strict per-line $75.00 Family Cap reconciliation (`sywat_waitlist_recalculate_line_family_fees()`), household-wide unpaid balance evaluation (`sywat_get_household_balance_info()`), and atomic multi-store registration persistence.
- **`waitlist-actions.php`** (v2.0.0) — Processes single/group promotions from Waitlist to Pre-Reg, multi-select moves from the top-of-modal Open Line toolbar, waitlist entry removal, legacy `'No Show'` metadata cleanup, capacity guards, and per-line $75.00 Family Cap reconciliation.
- **`waitlist-views.php`** (v2.1.0) — Renders the dedicated Waitlist Management Modal (`#sywat_waitlist_modal`) with uniform-height top filter/move toolbars, FIFO family-grouped queue table, aligned mobile-friendly stacked actions, and reusable clickable waitlist badges/buttons.

### 8. Member & Board Directory Sub-System (`includes/members/`)
- **`class-sywat-members.php`** (v4.1.2) — Master sub-module loader shell binding shortcode `[sywat_members]`, enforcing `sywat_can_create_members()`, and enqueuing `assets/js/sywat-members.js`.
- **`members-data.php`** (v6.1.0) — Centralized database queries (`WP_User_Query`, `\$wpdb`), O(1) in-memory hashtable indexes, unified flat archer datasets (primary Heads, household dependents, and unassigned pool archers), O(1) name/contact lookup (`sywat_find_member_record_by_name()`), and hierarchy-sorted board member retrieval.
- **`members-helpers.php`** (v6.4.0) — Scalar/array payload formatters, System/NFAA ID resolvers, DOB age division calculators, intake shooter profile resolver (`sywat_resolve_archer_intake_profile()`), TFAA role slug definitions, and official Board of Directors position list builders.
- **`members-actions.php`** (v5.1.1) — Nonce-verified (`sywat_desk_action`) POST and AJAX batch processor handling account creation, household dependent linking/unlinking, Head of Household swapping, profile edits, password complexity rules, multi-role assignment, host shop assignment, board position synchronization, and protected test data purges.
- **`members-views.php`** (v8.1.2) — High-density UI renderer for Member Directory, Board Management, Single-Dashboard Household Roster, and Self-Service Member Household Profile Portal.
- **`members-modals.php`** (v6.1.2) — Isolated modal overlays for Add Member, Edit Member, Household Management, Batch Spreadsheet Import, Audit Preview, Assign Board Member, and Edit Board Positions.
- **`import/archers-importer.php`** (v2.4.0) — 2-step audit preview and chunked AJAX batch spreadsheet ingestion engine for `Archers.xlsx` / CSV historical records.
- **`reconcile/tfaa-reconcile-engine.php` & `reconcile/modal-tfaa-reconcile.php`** (v1.1.0) — Temporary post-import POC utility comparing synced TFAA shooter records against the Member Directory to backfill missing gender/pro attributes (`?reconcile_tfaa=1`).

### 9. Results & Leaderboards Sub-System (`includes/results/`)
- **`class-sywat-results.php`** (v3.6.3) — Sub-module loader shell and shortcode binding (`[sywat_results]`).
- **`results-helpers.php`** (v2.7.1) — Division/gender sort weights, 11-tier style priority sorting, complete roster score retrieval with default `0` mapping, and season standings leaderboard aggregation excluding zero scores and Guest entries.
- **`results-standings.php`** (v4.4.0) — Public Season Standings UI (`?view=standings`) with multi-variable filters (Division, Gender, Style, Search) and total participation stat banners.
- **`results-tournaments.php`** (v4.3.0) — Public Tournament Results UI (`?view=tournaments` / `?view=tourn_results`) with automatic Guest style assignment for unturned cards and inline staff edit shortcuts.

### 10. Score Entry Sub-System (`includes/scores/`)
- **`class-sywat-scores.php`** (v6.5.0) — Sub-module loader shell and shortcode binding (`[sywat_scores]`). Enforces access using `sywat_can_edit_scores()`.
- **`scores-actions.php`** (v11.2.0) — Early `template_redirect` JSON single-score AJAX auto-save and batch save/lock/reset action logic.
- **`scores-export.php`** (v6.5.0) — CSV score report export generator (`template_redirect`).
- **`scores-views.php`** (v7.3.2) — Score entry filter toolbar with sticky user tournament preference priority and tab URL carryover, visual Line Score Tracker progress cards, and Archer Scores table UI.

### 11. Archery Shops & Directory Sub-System (`includes/shops/`)
- **`class-sywat-shops.php`** (v3.0.0) — Sub-module loader for shop directory management.
- **`shops-helpers.php`** (v2.8.0) — Phone number auto-formatting, seed shop initialization, shop getters, and WP Admin user profile integration.
- **`shops-actions.php`** (v5.1.0) — Shop CRUD processing, contact array parsing, dynamic line templates, Public Online Registration default toggle & lead-time days persistence, and 2-step dry-run import preview transient processor for Excel `.xlsx`/`.xls` and CSV files.
- **`shops-views.php`** (v5.1.0) — Admin shortcode controller (`[sywat_manage_shops]`).
- **`shops-modals.php`** — Admin shop create/edit modals including default Public Online Registration settings (`public_reg_default_enabled`, `public_reg_default_lead_days`).
- **`shops-public-views.php`** (v1.3.0) — Public directory shortcode controller (`[sywat_public_shops]`).
- **`views/`** — Sub-folder containing UI template partials (`view-shop-directory.php`, `view-shop-edit.php`, `view-shop-public.php` v6.8.3, and `view-shop-staff.php` v5.0.0).

### 12. Tournaments Sub-System (`includes/tournaments/`)
- **`class-sywat-tournament-config.php`** (v2.8.2) — Sub-module loader shell and shortcode bindings (`[sywat_manage]`, `[sywat_admin]`, `[sywat_manage_tournaments]`, `[sywat_volunteer_hub]`).
- **`tournament-data.php`** (v8.1.2) — Pure read-only data bundle aggregation (`sywat_get_master_portal_data()`), live registration loading with automatic slug-to-title reconciliation, and safe chronological re-sequencing.
- **`tournament-helpers.php`** (v7.6.0) — Line capacity math, date range formatting, line matchers, TFAA search queries, and the centralized Public Online Registration gatekeeper (`sywat_get_public_registration_status()`).
- **`tournament-seasons.php`** (v5.1.0) — Season Management CRUD UI (`tab=seasons`), live season search, and modal overlay (`#sywat_add_season_modal`).
- **`tournament-modals.php`** (v7.11.0) — Add/Edit Tournament (with shop-inherited or overridden Public Online Registration toggle & lead-time days), Bulk Import Schedule, Delegated Modal Router (`manage` / `print` / `invoice`), and Async TFAA Sync Progress modals.
- **`tournament-actions.php`** (v7.17.0) — Early `init` JSON sync interceptor and POST request action handlers that persist per-tournament Public Online Registration windows (and inherit shop defaults on bulk import), preserve tournament keys/metadata during capacity edits, and safely migrate registrations, scores, and line locks on explicit tournament renames/re-sequencing.
- **`tournament-views.php`** (v7.20.0) — Master Admin Portal UI protected by `sywat_can_manage_tournaments()` with standardized navigation tabs, season/district filters, live `Public Reg: Open` / `Public Reg: Opens [Date]` / `Public Reg: Off` status pills, clickable Waitlist badges/buttons, `#sywat_waitlist_modal` integration, and configured events table.
- **`volunteer-hub.php`** (v5.1.0) — Staff Volunteer Hub portal view (`[sywat_volunteer_hub]`) featuring `.sywat-tracker-grid` line cards and real-time roster stats.

### 13. Standalone Supporting Modules (`includes/`)
- **`class-sywat-accounting.php`** (v6.3.0) — Financial accounting engine, 50/50 host/TFAA fee split math, family cap calculations, embedded Base64 Zelle QR code rendering, and printable invoice/roster renderers with dynamic shop metadata.
- **`common/sywat-tfaa-sync.php`** (v1.1.1) — TFAA membership verification, web scraping standings/URL cache engine, standalone sync button (`[sywat_tfaa_sync_btn]`), and daily WP-Cron background sync.

### 14. Public Schedule Sub-System (`includes/schedule/`)
- **`class-sywat-schedule.php`** (v1.0.0) — Controller shell and shortcode binding (`[sywat_schedule]`).
- **`schedule-data.php`** (v1.0.0) — Pure read-only data layer retrieving seasons, tournaments, and shop metadata.
- **`schedule-helpers.php`** (v1.3.0) — Pure array formatter calculating event status (`Upcoming`, `In Progress`, `Completed`, `This Weekend`), evaluating Public Online Registration gatekeeper status (`public_reg_status`, `sywat_reg_url`, `public_reg_opens_label`), and stripping capacity counts for public display.
- **`views/view-schedule-public.php`** (v1.3.0) — Pure HTML renderer for List View and Calendar View with inline SVGs, accessible DOM structure, `data-*` hooks for live filtering, and **Register Online** / **Opens [Date]** CTAs.
- **`assets/js/sywat-schedule.js`** (v1.4.0) — Singleton vanilla JS client engine handling real-time filtering, List/Calendar view switching, instant month navigation, jump-to-weekend scrolling, and event details modals with synchronized **Register Online** and **Opens [Date]** buttons.

---

## 📜 Current Release Changelog Summary (`SYWAT_PLUGIN_VERSION` 7.2.0)

### Version 7.2.0
1. **Shop-Level Public Online Registration Defaults & Tournament Window Gatekeeper (`includes/shops/`, `includes/tournaments/`)**:
   - Added default Public Online Registration toggle and lead-time days (`public_reg_default_enabled`, `public_reg_default_lead_days`) to Host Shop create/edit settings (`shops-actions.php`, `shops-modals.php`).
   - Added centralized registration window evaluation (`sywat_get_public_registration_status()` in `tournament-helpers.php`) supporting shop-default inheritance on new/imported tournaments, per-event overrides (`tournament-modals.php`, `tournament-actions.php`), and live status pills (`Public Reg: Open`, `Public Reg: Opens [Date]`, `Public Reg: Off`) in `tournament-views.php`.
2. **Public Schedule & Top Navigation Integration (`includes/core/class-sywat-frontend.php`, `includes/auth/sywat-auth-views.php`, `includes/schedule/`, `assets/js/sywat-schedule.js` v1.4.0)**:
   - Registered `[sywat_member_register]` in `sywat_shortcode_tags()` and added the configurable `member_register` (`/register-online/`) page key under **Settings > SYWAT Display**.
   - Added **Register Online** to the top `Tournaments ▾` menu and synchronized **Register Online** / **Opens [Date]** actions across both List View (`view-schedule-public.php`) and Calendar View modals (`sywat-schedule.js` v1.4.0).
3. **Member Online Self-Registration Engine (`[sywat_member_register]`, `includes/registration/`)**:
   - Added modular sub-files `member-registration-data.php` (v1.0.0), `member-registration-helpers.php` (v1.1.0), `member-registration-actions.php` (v1.0.0), and `views/view-member-registration.php` (v1.0.0) wired through `class-sywat-registration.php` (v6.1.0).
   - Enables signed-in TFAA members to register themselves and linked household family members for open tournaments with tournament-date NFAA age division & distance (`20yd` vs. `Cub`) auto-detection, live line slot counts, automatic `Waitlist` placement (`\$0.00` due) when full, First-Time/Lapsed Shooter promos, and Household Family Caps.

### Version 7.1.0
1. **Public Season Schedule (`includes/schedule/`)**:
   - Extracted a new modular sub-system (`class-sywat-schedule.php`, `schedule-data.php`, `schedule-helpers.php`, `view-schedule-public.php`, `sywat-schedule.js`) providing a live public schedule board with dynamic filters, accessible SVGs, and automated event statuses.
2. **Role-Gated Navigation (`includes/auth/sywat-auth-views.php`)**:
   - Re-architected the main menu, moving 'Results' and 'Season Schedule' into a universal `Tournaments ▾` dropdown, while securely gating the `Tournament Portal` link purely server-side for staff.
3. **Per-Tournament Registration Links (`includes/tournaments/tournament-actions.php`)**:
   - Removed inherited global registration link defaults on bulk schedule imports, ensuring each tournament starts with a clean slate for precise FareHarbor link assignment.

### Version 6.8.1
1. **Archer Roster Removal Fix & Apostrophe-Safe `wp_options` Cleanup (`includes/roster/roster-actions.php`)**:
   - Fixed `sywat_handle_delete_shooter()` and `sywat_handle_save_edit()` by applying `wp_unslash()` to `$_POST['view_tournament']` and `$_POST['registration_id']`, preventing apostrophe tournament names (e.g., `Buck & Doe's-1`) from mismatching sanitized `wp_options` keys (`sywat_t_{slug}_{id}`).
   - Added multi-slug option deletion (matching both the stored `$reg['tournament']` record slug and the POSTed tournament slug) plus an indexed `$wpdb->options` suffix fallback so orphaned registration records are always removed.
   - Fixed `sywat_handle_save_edit()` line label resolution via `sywat_roster_resolve_line_label()` so edited archers preserve their true shooting line name instead of resetting to `'Line 1'`.
2. **Theme-Neutral & Optimizer-Safe Roster Modal Views (`includes/roster/roster-views.php`, `includes/roster/roster-modals.php`, `assets/js/sywat-roster.js`)**:
   - Replaced hard-coded `?tab=manage` paths and `<a>` close links with `sywat_page_url( 'manage_tournaments' )` and native `<button type="button">` modal controls (`aria-label` included) per the Modal Markup Contract.
   - Added explicit form `action` URLs and hidden `modal_action=manage` / `filter_line_idx` inputs on roster Remove and Purge forms so the modal remains open on the active filter after removing an archer in both Astra App mode and Divi Theme mode.
   - Migrated all inline `onclick`/`onchange`/`onsubmit` handlers and `.style.display` toggles in `sywat-roster.js` (v6.8.1) to namespaced `window.SYWAT.roster` functions, delegated `data-sywat-action` / `data-sywat-confirm` listeners, and `.sywat-hidden` class toggles.

### Version 6.8.0
1. **Theme-Neutral Integration Layer (`includes/core/class-sywat-frontend.php`)**:
   - Introduced central shortcode page detection, conditional CSS/JS asset loading (front-end shortcode pages only, excluded from `wp-admin` and visual builders), optimizer opt-out attributes, `Cache-Control: no-cache` headers, `sywat_page_url()` registry, and `Settings > SYWAT Display` Layout mode (App mode vs. Theme mode).
   - Bound all brand and surface colors in `assets/css/sywat-core.css` to `--sywat-*: var(--tfaa-*, <default>)` custom properties for child-theme restyling, and introduced `assets/js/sywat-modal-manager.js` for top-level `<body>` modal relocation and accessibility.

### Version 6.5.0
1. **Member Sub-Module Modular JS Extraction & Zero-Inline-Style Refactoring**:
   - Extracted all embedded JavaScript out of `members-modals.php` into standalone `assets/js/sywat-members.js`.
   - Purged 100% of inline `style="..."` attributes across `members-views.php` and `members-modals.php`, migrating layout styling to CSS class utility definitions.
   - Refactored DOM state transitions to use CSS class toggles (`classList.add('sywat-hidden')`, `classList.toggle('active')`) instead of direct JS style mutations.
