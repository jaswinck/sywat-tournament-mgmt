# SYWAT Archery Tournament Management Plugin

An enterprise-grade, multi-tenant, theme-neutral WordPress plugin built for the Texas Field Archery Association (TFAA) and SYWAT Archery to manage indoor archery tournaments, shooter intake, line capacities, waitlist queues, score tracking, season standings leaderboards, shop directory management, member & board administration, and financial accounting across any WordPress theme (Astra App mode and Divi Theme mode).

---

## 🛠️ Key System Features & Architecture

- **Theme-Neutral Integration & Dual Layout Modes**: Runs cleanly under Astra (App mode) and Divi (Theme mode with `--tfaa-*` child theme tokens) via `includes/core/class-sywat-frontend.php`, with conditional asset loading, optimizer opt-outs (WP Rocket / Cloudflare / LiteSpeed), page-builder placeholders, `filemtime()` script cache-busting, and `Cache-Control: no-cache` headers on operational screens.
- **Multi-Tenant Shop & Indoor Range Scoping**: Isolate shooting lines, rosters, and financial reports by range host/location.
- **Dynamic Shooting Line & Distance Capacity Engine**: Enforces independent 20yd and Cub (10yd) physical lane limits with automatic waitlist overflow tracking (`all` vs. `remainder` family triage).
- **Dedicated Waitlist Queue & Per-Line $75.00 Family Cap Engine**: Centralized FIFO family-clustered waitlist management (`includes/waitlist/`) with on-demand `#sywat_waitlist_modal` promotion, top-bar multi-select line moves, split-family reconciliation, and strict per-line $75.00 Family Cap recalculation.
- **Household-Wide Balance Verification & Historical Auto-Fill at Check-In**: Enforces unpaid family balances across all household members sharing a line (`⚠️ OWE: FAMILY`) and automatically populates shooter Style, Division, and Gender from 18-month TFAA history and shop registration caches.
- **Sticky Operational Context Across Portals**: Synchronizes the user's active tournament selection (`sywat_desk_selected_tournament` user meta + `?selected_tournament=` navigation tab carryover) across **Register Shooters**, **Check In Archers**, and **Enter Scores**.
- **Phone & Group Registration Intake**: Quick caller intake form supporting multi-archer groups, per-line $75 family capping rules, FareHarbor CSV bulk imports, and direct line roster/waitlist management links.
- **Waitlist-Aware Roster Sub-System**: Unified roster management interface (`includes/roster/`) with deep-linked line filtering, Pre-Reg/Waitlist status switching, real-time waitlist status badging (`🕒 Waitlist`), line purges, profile editing, and apostrophe-safe registration removal.
- **Member & Board Directory Sub-System**: Unified member management interface (`includes/members/`) featuring household sub-profiles, unassigned archer pools, chunked AJAX spreadsheet imports (`Archers.xlsx` / CSV), multi-role capabilities, shop assignments, password policy validation, and automated TFAA Board role synchronization.
- **Live Scoring & Leaderboard Engine**: Multi-category sorting (Division, Gender, Style), Guest style assignments for unturned cards, season standings leaderboards, background AJAX score auto-save, and instant CSV export capability.
- **Financial & Host Fee Accounting**: Automatic 50/50 host fee splits, family cap adjustments, embedded Base64 Zelle QR code generation, and printable invoice renderers.

---

## 📂 Sub-System Architecture & Module Reference

### 1. Core Plugin Loader & Integration Layer (`/` & `includes/core/`)
- **`sywat-archery-manager.php`** (v6.8.1) — Master WordPress plugin entry point. Initializes constants (`SYWAT_PLUGIN_DIR`, `SYWAT_PLUGIN_URL`, `SYWAT_PLUGIN_VERSION = 6.8.1`), registers activation hooks, and bootstraps core integration and modular sub-systems (including `includes/waitlist/class-sywat-waitlist.php`).
- **`includes/core/class-sywat-permissions.php`** (v3.6.0) — Central roles, capabilities, and board position synchronization engine (`sywat_can_manage_tournaments()`, `sywat_can_edit_scores()`, `sywat_can_access_desk()`, `sywat_can_create_members()`, `sywat_can_manage_board()`).
- **`includes/core/class-sywat-frontend.php`** — Central theme-neutral integration layer handling shortcode page detection (`sywat_shortcode_tags()`), conditional CSS/JS enqueueing (never site-wide or in `wp-admin`), optimizer opt-out attributes, Divi Visual Builder placeholders, `sywat_page_url()` routing, and `Settings > SYWAT Display` Layout mode.

### 2. Assets & Styling (`assets/`)
- **`assets/css/sywat-core.css`** — Central `:root` design tokens (`--sywat-*: var(--tfaa-*, <default>)`), scoped input rules (`:is(.sywat-wrap, .sywat-app, .sywat-scope, .sywat-modal-backdrop)`), card containers (`.sywat-card`), card-docked tab tracks (`.sywat-nav-tabs`), spacing utilities (`.sywat-mt-3`, `.sywat-mb-4`, `.sywat-ml-0`, `.sywat-hidden`), and alert banners.
- **`assets/css/sywat-tables.css`** — Responsive table layout engine (`.sywat-table-responsive`, `.sywat-table`), column width modifiers (`.sywat-col-w-10` through `.sywat-col-w-32`), row hover states, isolated action wrappers (`.sywat-modal-roster-actions`), and centered vertical stacking containers (`.sywat-table-actions-vertical`).
- **`assets/css/sywat-modals.css`** — Tokenized modal stacking variables (`--sywat-z-modal`, `--sywat-z-modal-nested`, `--sywat-z-modal-dropdown`, `--sywat-z-modal-alert`), modal backdrops (`.sywat-modal-backdrop`), dialog dimensions (`.sywat-modal-sm` through `.sywat-modal-xl`), scrollable bodies, and pinned header/footer structures.
- **`assets/css/sywat-buttons.css`** — Themeable action buttons (`.sywat-btn`, `.sywat-btn-primary`, `.sywat-btn-secondary`, `.sywat-btn-success`, `.sywat-btn-danger`), `.sywat-btn-full` block stretch rules, icon badges, and status pills (`.sywat-badge-open`, `.sywat-badge-full`, `.sywat-waitlist-pill`).
- **`assets/css/sywat-toolbar.css`** — Authentication header bar (`.sywat-auth-bar`), integrated card header toolbars (`.sywat-card-header-bar`), table control headers (`.sywat-table-header-bar`), search input groups (`.sywat-search-input-group`), and TFAA sync pills (`.sywat-sync-pill`).
- **`assets/css/sywat-public.css`** — Public leaderboard displays, division cards (`.sywat-div-gen-card`), search input wrappers, and participation stat banners (`.sywat-counter-banner`).
- **`assets/js/`** — Vanilla JS, jQuery-free, optimizer-safe client controllers (`window.SYWAT.<module>` + delegated `data-sywat-action` listeners):
  - `sywat-modal-manager.js`: Relocates top-level `.sywat-modal-backdrop` elements to `<body>` (escaping Divi transforms/fixed headers), manages dialog ARIA roles, focus trapping, Escape-to-close, scroll lock, and print isolation.
  - `sywat-waitlist.js` (v2.0.0): Namespaced vanilla JS module managing `#sywat_waitlist_modal` lifecycle, line filter navigation, live search filtering, family/member checkbox synchronization, top-bar Move Selected validation, single-prompt confirmations, and double-submit protection.
  - `sywat-checkin.js` (v6.7.0): Dynamic check-in search, 18-month TFAA + shop history auto-fill for Style/Division/Gender (with division restriction enforcement before selection), family group prompts, and modal toggles.
  - `sywat-roster.js` (v6.8.1): Delegated roster modal controls (`window.SYWAT.roster`), confirmation dialogs (`data-sywat-confirm`), line-scoped family group dropdown updates, division/style restriction enforcement, and class-driven live search filtering.
  - `sywat-members.js` (v5.3.0): Client JavaScript for the Member sub-module handling household/board modals, chunked AJAX import/purge progress streams, phone number formatting (`XXX-XXX-XXXX`), real-time password policy validation badges, role-based shop dropdown toggles, live autocomplete/table search filtering, and auto-dismissing system notices.
  - `sywat-tfaa-reconcile.js` (v1.0.0): Namespaced client controller (`window.SYWAT.reconcile`) for the temporary post-import TFAA Gender & Pro reconciliation modal (`#sywat_tfaa_reconcile_modal`).
  - `sywat-registration.js` (v6.4.0): Multi-archer dynamic row insertion, distance auto-switch, and group balance calculation.
  - `sywat-scores.js` (v7.2.0): Batch score entry, offline draft caching, and background AJAX auto-save.
  - `sywat-tournaments.js` (v6.8.2): Tournament line template repeaters, season modal handlers, and async TFAA sync progress tracking.
  - `sywat-shops.js` (v6.8.3): Shop location editing tabs and CSV/Excel import file preview handlers.

### 3. Authentication & Access Control (`includes/auth/`)
- **`sywat-auth-actions.php`** (v1.1.0) — Authentication form handlers, login/logout routing, and session security checks.
- **`sywat-auth-views.php`** (v5.5.0) — Role-restricted administrative wrappers, top navigation header (`.sywat-auth-bar`) with Head of Household profile dropdown, and access denied view notices.

### 4. Archer Check-In Desk Sub-System (`includes/checkin/`)
- **`class-sywat-checkin.php`** (v6.1.1) — Sub-module controller for live desk intake (`[sywat_desk]`) with `filemtime()` cache-busting on `sywat-checkin.js`.
- **`checkin-actions.php`** (v6.9.2) — Check-in toggles, household-wide unpaid family balance verification & clearance, delegated Waitlist modal actions, walk-in shooter creation, and roster modifications with per-line Family Cap reconciliation.
- **`checkin-data.php`** (v6.8.4) — Fast DB queries retrieving active registrations, sticky user tournament preferences (`sywat_desk_selected_tournament`), payment balances, and check-in statuses.
- **`checkin-modals.php`** (v6.6.0) — Check-in, Edit Archer, and Walk-In overlay modals enforcing household-wide unpaid family balance banners and staff verification checkboxes across all members of an unpaid family group.
- **`checkin-views.php`** (v6.9.3) — Main Check-In Desk table view featuring sticky tournament tab carryover, clickable Waitlist badges/buttons, real-time search, household-wide `⚠️ OWE: FAMILY` badges, family grouping alerts, and fast check-in action buttons.

### 5. Archer Registration Engine (`includes/registration/`)
- **`class-sywat-registration.php`** (v5.0.0) — Sub-module loader for intake forms and line usage monitoring (`[sywat_register]`).
- **`registration-actions.php`** (v6.9.0) — Phone intake processing, multi-archer group registration, active-only per-line $75.00 family cap logic, waitlist triage (`all` vs. `remainder`), and atomic multi-store persistence.
- **`registration-import.php`** (v6.8.2) — FareHarbor CSV/text import file parsing and shooter auto-creation.
- **`registration-views.php`** (v6.9.1) — Main registration layout container with sticky tournament selection (`sywat_desk_selected_tournament`), navigation tab carryover, and on-demand Waitlist modal rendering.
- **`registration-modals.php`** (v6.9.0) — Renders Phone Intake Form, FareHarbor Bulk Upload Form, and Current Line Slot Usage Table with deep links into `class-sywat-roster.php` and clickable Waitlist badges/buttons into `#sywat_waitlist_modal`.
- **`registration-scripts.php`** (v6.1.0) — Localized script loader for intake calculation and line override listeners.

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
- **`members-data.php`** (v6.0.0) — Centralized database queries (`WP_User_Query`, `$wpdb`), O(1) in-memory hashtable indexes, unified flat archer datasets (primary Heads, household dependents, and unassigned pool archers), and hierarchy-sorted board member retrieval.
- **`members-helpers.php`** (v6.3.0) — Scalar/array payload formatters, System/NFAA ID resolvers, DOB age division calculators, TFAA role slug definitions, and official Board of Directors position list builders.
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
- **`shops-actions.php`** (v5.0.0) — Shop CRUD processing, contact array parsing, dynamic line templates, and 2-step dry-run import preview transient processor for Excel `.xlsx`/`.xls` and CSV files.
- **`shops-views.php`** (v5.1.0) — Admin shortcode controller (`[sywat_manage_shops]`).
- **`shops-public-views.php`** (v1.3.0) — Public directory shortcode controller (`[sywat_public_shops]`).
- **`views/`** — Sub-folder containing UI template partials (`view-shop-directory.php`, `view-shop-edit.php`, `view-shop-public.php` v6.8.3, and `view-shop-staff.php` v5.0.0).

### 12. Tournaments Sub-System (`includes/tournaments/`)
- **`class-sywat-tournament-config.php`** (v2.8.2) — Sub-module loader shell and shortcode bindings (`[sywat_manage]`, `[sywat_admin]`, `[sywat_manage_tournaments]`, `[sywat_volunteer_hub]`).
- **`tournament-data.php`** (v8.1.2) — Pure read-only data bundle aggregation (`sywat_get_master_portal_data()`), live registration loading with automatic slug-to-title reconciliation, and safe chronological re-sequencing.
- **`tournament-helpers.php`** (v7.5.1) — Line capacity math, date range formatting, line matchers, and TFAA search queries.
- **`tournament-seasons.php`** (v5.1.0) — Season Management CRUD UI (`tab=seasons`), live season search, and modal overlay (`#sywat_add_season_modal`).
- **`tournament-modals.php`** (v7.10.0) — Add/Edit Tournament, Bulk Import Schedule, Delegated Modal Router (`manage` / `print` / `invoice`), and Async TFAA Sync Progress modals.
- **`tournament-actions.php`** (v7.12.0) — Early `init` JSON sync interceptor and POST request action handlers that preserve tournament keys/metadata during capacity edits and safely migrate registrations, scores, and line locks on explicit tournament renames/re-sequencing.
- **`tournament-views.php`** (v7.19.0) — Master Admin Portal UI protected by `sywat_can_manage_tournaments()` with standardized navigation tabs, season/district filters, clickable Waitlist badges/buttons, `#sywat_waitlist_modal` integration, and configured events table.
- **`volunteer-hub.php`** (v5.1.0) — Staff Volunteer Hub portal view (`[sywat_volunteer_hub]`) featuring `.sywat-tracker-grid` line cards and real-time roster stats.

### 13. Standalone Supporting Modules (`includes/`)
- **`class-sywat-accounting.php`** (v6.3.0) — Financial accounting engine, 50/50 host/TFAA fee split math, family cap calculations, embedded Base64 Zelle QR code rendering, and printable invoice/roster renderers with dynamic shop metadata.
- **`common/sywat-tfaa-sync.php`** (v1.1.1) — TFAA membership verification, web scraping standings/URL cache engine, standalone sync button (`[sywat_tfaa_sync_btn]`), and daily WP-Cron background sync.

---

## 📜 Current Release Changelog Summary (`SYWAT_PLUGIN_VERSION` 6.8.1 / Sub-Modules v6.9.x–v11.2.0)

1. **Household-Wide `OWE: FAMILY` Check-In Balance Enforcement (`includes/waitlist/waitlist-helpers.php`, `includes/checkin/checkin-views.php`, `includes/checkin/checkin-modals.php`, `includes/checkin/checkin-actions.php`)**:
   - Added `sywat_get_household_balance_info()` to evaluate combined unpaid balances across all active household members sharing a `family_id` or `booking_id` on the same shooting line—eliminating single-holder blind spots without double-counting invoice totals.
   - Updated `checkin-views.php` (v6.9.3), `checkin-modals.php` (v6.6.0), and `checkin-actions.php` (v6.9.2) so every member of an unpaid family displays **`⚠️ OWE: FAMILY`**, triggers the red **`⚠️ UNPAID FAMILY BALANCE`** alert banner and required staff verification checkbox in the Check-In modal, and clears the household balance across all family members once collected onsite.

2. **Check-In Modal Style Auto-Population & Script Cache-Busting (`assets/js/sywat-checkin.js`, `includes/checkin/class-sywat-checkin.php`)**:
   - Fixed argument order in `matchDeskTfaaSelect()` inside `assets/js/sywat-checkin.js` (v6.7.0) so Style, Division, and Gender automatically populate from 18-month TFAA history and shop registration history when opening the Check-In modal, applying division style restrictions prior to option selection.
   - Updated `sywat_desk_enqueue_scripts()` in `includes/checkin/class-sywat-checkin.php` (v6.1.1) to version `sywat-checkin.js` with `filemtime()` for automatic browser cache invalidation.

3. **Sticky Tournament Selection & Tab Carryover Across Operational Portals (`includes/registration/registration-views.php`, `includes/checkin/checkin-data.php`, `includes/checkin/checkin-views.php`, `includes/scores/scores-views.php`)**:
   - Standardized tournament resolution priority across **Register Shooters** (`registration-views.php` v6.9.1), **Check In Archers** (`checkin-data.php` v6.8.4), and **Enter Scores** (`scores-views.php` v7.3.2) to prioritize explicit request parameters and the user's saved `sywat_desk_selected_tournament` meta before global fallbacks.
   - Appended `?selected_tournament=...` to the **Register Shooters**, **Check In Archers**, and **Enter Scores** navigation tab links across all three portals so switching tabs keeps the user on their active tournament.

4. **Dedicated Waitlist Queue Management & Per-Line $75.00 Family Cap Engine (`includes/waitlist/*`, `assets/js/sywat-waitlist.js`, `includes/tournaments/tournament-views.php`, `includes/registration/*`, `includes/roster/*`)**:
   - Introduced the standalone `includes/waitlist/` sub-system (`class-sywat-waitlist.php` v2.0.0, `waitlist-data.php` v1.0.0, `waitlist-helpers.php` v1.1.0, `waitlist-actions.php` v2.0.0, `waitlist-views.php` v2.1.0) and `assets/js/sywat-waitlist.js` (v2.0.0) powering the on-demand `#sywat_waitlist_modal` across **Manage Tournaments**, **Register Shooters**, **Check In Archers**, and **Registered Roster** modals.
   - Added FIFO family-clustered queue grouping, split-family reconciliation badges, top-bar Open Line multi-select transfers, single/group promotions to `Pre-Reg`, and strict per-line $75.00 Family Cap recalculation on promotions, line moves, edits, and deletions.

5. **Member Directory Household Enhancements, Batch Importer & TFAA Reconciliation POC (`includes/members/*`, `assets/js/sywat-members.js`, `assets/js/sywat-tfaa-reconcile.js`)**:
   - Expanded `includes/members/` (`members-views.php` v8.1.2, `members-modals.php` v6.1.2, `members-actions.php` v5.1.1, `members-data.php` v6.0.0, `members-helpers.php` v6.3.0) with unified flat archer directory views (Heads, Dependents, and Unassigned Pool), Head of Household swapping, standalone account conversion drawers, chunked AJAX `Archers.xlsx` / CSV batch imports (`archers-importer.php` v2.4.0), and a temporary post-import TFAA Gender/Pro reconciliation modal (`includes/members/reconcile/*`).

6. **Tournament Key Preservation & Safe Re-Sequencing (`includes/tournaments/tournament-actions.php`, `includes/tournaments/tournament-data.php`)**:
   - Updated `tournament-actions.php` (v7.12.0) and `tournament-data.php` (v8.1.2) to preserve tournament keys and metadata during line/capacity edits while safely migrating registrations, scores, and line locks whenever a tournament is explicitly renamed or chronologically re-sequenced.

---

## 📜 Historical Changelogs

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

### Version 6.3.1
1. **Dedicated Roster Sub-System (`includes/roster/`) Architecture**:
   - Fully modularized roster sub-system across `class-sywat-roster.php`, `roster-views.php`, `roster-modals.php`, `roster-actions.php`, and `roster-scripts.php`.
   - Added full waitlist awareness in `roster-views.php` with explicit `$reg['status'] === 'Waitlist'` detection rendering gold `🕒 Waitlist` pills.
