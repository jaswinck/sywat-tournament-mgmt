# SYWAT Archery Tournament Management Plugin

An enterprise-grade, multi-tenant, theme-neutral WordPress plugin built for the Texas Field Archery Association (TFAA) and SYWAT Archery to manage indoor archery tournaments, shooter intake, line capacities, score tracking, season standings leaderboards, shop directory management, member & board administration, and financial accounting across any WordPress theme (Astra App mode and Divi Theme mode).

---

## 🛠️ Key System Features & Architecture

- **Theme-Neutral Integration & Dual Layout Modes**: Runs cleanly under Astra (App mode) and Divi (Theme mode with `--tfaa-*` child theme tokens) via `includes/core/class-sywat-frontend.php`, with conditional asset loading, optimizer opt-outs (WP Rocket / Cloudflare / LiteSpeed), page-builder placeholders, and `Cache-Control: no-cache` headers on operational screens.
- **Multi-Tenant Shop & Indoor Range Scoping**: Isolate shooting lines, rosters, and financial reports by range host/location.
- **Dynamic Shooting Line & Distance Capacity Engine**: Enforces independent 20yd and Cub (10yd) physical lane limits with automatic waitlist overflow tracking.
- **Phone & Group Registration Intake**: Quick caller intake form supporting multi-archer groups, per-line $75 family capping rules, FareHarbor CSV bulk imports, and direct line roster management links.
- **Waitlist-Aware Roster Sub-System**: Unified roster management interface (`includes/roster/`) with deep-linked line filtering, real-time waitlist status badging (`🕒 Waitlist`), line purges, profile editing, and apostrophe-safe registration removal.
- **Member & Board Directory Sub-System**: Unified member management interface (`includes/members/`) featuring multi-role capabilities, shop assignments, password policy validation, and automated TFAA Board role synchronization.
- **Live Scoring & Leaderboard Engine**: Multi-category sorting (Division, Gender, Style), Guest style assignments for unturned cards, season standings leaderboards, and instant CSV export capability.
- **Financial & Host Fee Accounting**: Automatic 50/50 host fee splits, family cap adjustments, embedded Base64 Zelle QR code generation, and printable invoice renderers.

---

## 📂 Sub-System Architecture & Module Reference

### 1. Core Plugin Loader & Integration Layer (`/` & `includes/core/`)
- **`sywat-archery-manager.php`** (v6.8.1) — Master WordPress plugin entry point. Initializes constants (`SYWAT_PLUGIN_DIR`, `SYWAT_PLUGIN_URL`, `SYWAT_PLUGIN_VERSION = 6.8.1`), registers activation hooks, and bootstraps core integration and modular sub-systems.
- **`includes/core/class-sywat-permissions.php`** — Central roles, capabilities, and board position synchronization engine (`sywat_can_manage_tournaments()`, `sywat_can_edit_scores()`, `sywat_can_access_desk()`, `sywat_can_create_members()`, `sywat_can_manage_board()`).
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
  - `sywat-roster.js` (v6.8.1): Delegated roster modal controls (`window.SYWAT.roster`), confirmation dialogs (`data-sywat-confirm`), line-scoped family group dropdown updates, division/style restriction enforcement, and class-driven live search filtering.
  - `sywat-members.js`: Client JavaScript for the Member sub-module handling phone number formatting (`XXX-XXX-XXXX`), real-time password policy validation badges, role-based shop dropdown toggles, live table search filtering, and auto-dismissing system notices.
  - `sywat-checkin.js`: Dynamic check-in search, family group prompts, and modal toggles.
  - `sywat-registration.js`: Multi-archer dynamic row insertion, distance auto-switch, and group balance calculation.
  - `sywat-scores.js`: Batch score entry, offline draft caching, and background AJAX auto-save.
  - `sywat-tournaments.js`: Tournament line template repeaters, season modal handlers, and async TFAA sync progress tracking.
  - `sywat-shops.js`: Shop location editing tabs and CSV/Excel import file preview handlers.

### 3. Authentication & Access Control (`includes/auth/`)
- **`sywat-auth-actions.php`** — Authentication form handlers, login/logout routing, and session security checks.
- **`sywat-auth-views.php`** — Role-restricted administrative wrappers, top navigation header (`.sywat-auth-bar`), and access denied view notices.

### 4. Archer Check-In Desk Sub-System (`includes/checkin/`)
- **`class-sywat-checkin.php`** — Sub-module controller for live desk intake (`[sywat_desk]`).
- **`checkin-actions.php`** — Check-in toggles, balance due updates, walk-in shooter creation, and roster modifications.
- **`checkin-data.php`** — Fast DB queries retrieving active registrations, payment balances, and check-in statuses.
- **`checkin-modals.php`** — Check-in overlay modals for individual and family shooter intake.
- **`checkin-views.php`** — Main Check-In Desk table view featuring real-time search, payment badges, family grouping alerts, and fast check-in toggle action buttons.

### 5. Archer Registration Engine (`includes/registration/`)
- **`class-sywat-registration.php`** — Sub-module loader for intake forms and line usage monitoring (`[sywat_register]`).
- **`registration-actions.php`** — Phone intake processing, multi-archer group registration, $75 line-scoped family cap logic, and line capacity overflow validation.
- **`registration-import.php`** — FareHarbor CSV/text import file parsing and shooter auto-creation.
- **`registration-views.php`** — Main registration layout container.
- **`registration-modals.php`** — Renders Phone Intake Form, FareHarbor Bulk Upload Form, and Line Slot Usage Table with deep links into `class-sywat-roster.php`.
- **`registration-scripts.php`** — Localized script loader for intake calculation and line override listeners.

### 6. Archer Roster Sub-System (`includes/roster/`)
- **`class-sywat-roster.php`** — Master sub-module loader shell for archer roster management, division/style restriction enforcement, line-scoped family group assignments, and line purges.
- **`roster-views.php`** (v6.8.1) — Registered Archer Roster management modal (`#sywat_roster_modal`, rendered via `modal_action=manage`). Features `sywat_page_url()` form routing, line dropdown filtering, live name searching, waitlist status pills (`🕒 Waitlist`), line purges (`purge_line`), native `<button type="button">` close triggers, and delegated `data-sywat-confirm` archer removal forms.
- **`roster-modals.php`** (v6.8.1) — Nested modal overlay (`#sywat_edit_archer_modal`) for updating archer profiles, line assignments, divisions, styles, payment statuses, and family group assignments using `sywat_page_url()` and delegated `data-sywat-action` attributes.
- **`roster-actions.php`** (v6.8.1) — POST request action handlers for saving archer edits (`save_edit`), purging full line rosters (`purge_line`), and deleting archer registrations (`delete_shooter`) with `wp_unslash()` slug normalization, multi-slug `wp_options` cleanup, and orphaned suffix fallback lookup.
- **`roster-scripts.php`** — Enqueues `assets/js/sywat-roster.js` and localizes line-scoped family group maps (`window.sywatRosterData`) for dynamic dropdowns in the edit modal.

### 7. Member & Board Directory Sub-System (`includes/members/`)
- **`class-sywat-members.php`** — Master sub-module loader shell binding shortcode `[sywat_members]`, enforcing `sywat_can_create_members()`, and enqueuing `assets/js/sywat-members.js`.
- **`members-data.php`** — Pure database queries (`WP_User_Query`, `$wpdb`), user meta getters, and member aggregations.
- **`members-helpers.php`** — Scalar/array payload formatters, TFAA role slug definitions, and official Board of Directors position list builders.
- **`members-actions.php`** — Nonce-verified (`sywat_desk_action`) POST request processor handling account creation, profile edits, password complexity rules, multi-role assignment, host shop assignment, board position synchronization, and self-deletion guards.
- **`members-views.php`** — Zero-inline-style UI renderer for Member Directory and Board Management tabs.
- **`members-modals.php`** — Isolated modal overlays for Add Member, Edit Member, Assign Board Member, and Edit Board Positions.

### 8. Results & Leaderboards Sub-System (`includes/results/`)
- **`class-sywat-results.php`** — Sub-module loader shell and shortcode binding (`[sywat_results]`).
- **`results-helpers.php`** — Division/gender sort weights, 11-tier style priority sorting, complete roster score retrieval with default `0` mapping, and season standings leaderboard aggregation excluding zero scores and Guest entries.
- **`results-standings.php`** — Public Season Standings UI (`?view=standings`) with multi-variable filters (Division, Gender, Style, Search) and total participation stat banners.
- **`results-tournaments.php`** — Public Tournament Results UI (`?view=tournaments` / `?view=tourn_results`) with automatic Guest style assignment for unturned cards and inline staff edit shortcuts.

### 9. Score Entry Sub-System (`includes/scores/`)
- **`class-sywat-scores.php`** — Sub-module loader shell and shortcode binding (`[sywat_scores]`). Enforces access using `sywat_can_edit_scores()`.
- **`scores-actions.php`** — Early `wp_ajax_*` single score auto-save and batch save/lock/reset action logic.
- **`scores-export.php`** — CSV score report export generator (`template_redirect`).
- **`scores-views.php`** — Score entry filter toolbar, visual Line Score Tracker progress cards, and Archer Scores table UI.

### 10. Archery Shops & Directory Sub-System (`includes/shops/`)
- **`class-sywat-shops.php`** — Sub-module loader for shop directory management.
- **`shops-helpers.php`** — Phone number auto-formatting, seed shop initialization, shop getters, and WP Admin user profile integration.
- **`shops-actions.php`** — Shop CRUD processing, contact array parsing, dynamic line templates, and 2-step dry-run import preview transient processor for Excel `.xlsx`/`.xls` and CSV files.
- **`shops-views.php`** — Admin shortcode controller (`[sywat_manage_shops]`).
- **`shops-public-views.php`** — Public directory shortcode controller (`[sywat_public_shops]`).
- **`views/`** — Sub-folder containing UI template partials (`view-shop-directory.php`, `view-shop-edit.php`, `view-shop-staff.php`, `view-shop-public.php`).

### 11. Tournaments Sub-System (`includes/tournaments/`)
- **`class-sywat-tournament-config.php`** — Sub-module loader shell and shortcode bindings (`[sywat_manage]`, `[sywat_admin]`, `[sywat_manage_tournaments]`, `[sywat_volunteer_hub]`).
- **`tournament-data.php`** — Pure read-only data bundle aggregation (`sywat_get_master_portal_data()`).
- **`tournament-helpers.php`** — Line capacity math, date range formatting, line matchers, and TFAA search queries.
- **`tournament-seasons.php`** — Season Management CRUD UI (`tab=seasons`), live season search, and modal overlay (`#sywat_add_season_modal`).
- **`tournament-modals.php`** — Add/Edit Tournament, Bulk Import Schedule, Delegated Modal Router (`manage` / `print` / `invoice`), and Async TFAA Sync Progress modals.
- **`tournament-actions.php`** — Early `init` JSON sync interceptor and POST request action handlers for dynamic shooting line templates, tournament setup, TFAA standings/URL syncing, and season CRUD.
- **`tournament-views.php`** — Master Admin Portal UI protected by `sywat_can_manage_tournaments()` with standardized navigation tabs, season/district filters, and configured events table.
- **`volunteer-hub.php`** — Staff Volunteer Hub portal view (`[sywat_volunteer_hub]`) featuring `.sywat-tracker-grid` line cards and real-time roster stats.

### 12. Standalone Supporting Modules (`includes/`)
- **`class-sywat-accounting.php`** — Financial accounting engine, 50/50 host/TFAA fee split math, family cap calculations, embedded Base64 Zelle QR code rendering, and printable invoice/roster renderers with dynamic shop metadata.
- **`common/sywat-tfaa-sync.php`** — TFAA membership verification, web scraping standings/URL cache engine, standalone sync button (`[sywat_tfaa_sync_btn]`), and daily WP-Cron background sync.

---

## 📜 Version 6.8.1 Changelog Summary

1. **Archer Roster Removal Fix & Apostrophe-Safe `wp_options` Cleanup (`includes/roster/roster-actions.php`)**:
   - Fixed `sywat_handle_delete_shooter()` and `sywat_handle_save_edit()` by applying `wp_unslash()` to `$_POST['view_tournament']` and `$_POST['registration_id']`, preventing apostrophe tournament names (e.g., `Buck & Doe's-1`) from mismatching sanitized `wp_options` keys (`sywat_t_{slug}_{id}`).
   - Added multi-slug option deletion (matching both the stored `$reg['tournament']` record slug and the POSTed tournament slug) plus an indexed `$wpdb->options` suffix fallback so orphaned registration records are always removed.
   - Fixed `sywat_handle_save_edit()` line label resolution via `sywat_roster_resolve_line_label()` so edited archers preserve their true shooting line name instead of resetting to `'Line 1'`.

2. **Theme-Neutral & Optimizer-Safe Roster Modal Views (`includes/roster/roster-views.php`, `includes/roster/roster-modals.php`, `assets/js/sywat-roster.js`)**:
   - Replaced hard-coded `?tab=manage` paths and `<a>` close links with `sywat_page_url( 'manage_tournaments' )` and native `<button type="button">` modal controls (`aria-label` included) per the Modal Markup Contract.
   - Added explicit form `action` URLs and hidden `modal_action=manage` / `filter_line_idx` inputs on roster Remove and Purge forms so the modal remains open on the active filter after removing an archer in both Astra App mode and Divi Theme mode.
   - Migrated all inline `onclick`/`onchange`/`onsubmit` handlers and `.style.display` toggles in `sywat-roster.js` (v6.8.1) to namespaced `window.SYWAT.roster` functions, delegated `data-sywat-action` / `data-sywat-confirm` listeners, and `.sywat-hidden` class toggles.
   - Bumped `SYWAT_PLUGIN_VERSION` to `6.8.1` in `sywat-archery-manager.php`.

---

## 📜 Historical Changelogs

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
