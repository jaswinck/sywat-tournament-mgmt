# SYWAT Archery Tournament Management Plugin

An enterprise-grade, multi-tenant WordPress plugin built for the Texas Field Archery Association (TFAA) and SYWAT Archery to manage indoor archery tournaments, shooter intake, line capacities, score tracking, season standings leaderboards, shop directory management, and financial accounting.

---

## 🛠️ Key System Features & Architecture

- **Multi-Tenant Shop & Indoor Range Scoping**: Isolate shooting lines, rosters, and financial reports by range host/location.
- **Dynamic Shooting Line & Distance Capacity Engine**: Enforces independent 20yd and Cub (10yd) physical lane limits with automatic waitlist overflow tracking.
- **Phone & Group Registration Intake**: Quick caller intake form supporting multi-archer groups, per-line $75 family capping rules, FareHarbor CSV bulk imports, and direct line roster management links.
- **Waitlist-Aware Roster Sub-System**: Unified roster management interface (`includes/roster/`) with deep-linked line filtering, real-time waitlist status badging (`🕒 Waitlist`), line purges, and profile editing.
- **Live Scoring & Leaderboard Engine**: Multi-category sorting (Division, Gender, Style), Guest style assignments for unturned cards, season standings leaderboards, and instant CSV export capability.
- **Financial & Host Fee Accounting**: Automatic 50/50 host fee splits, family cap adjustments, embedded Base64 Zelle QR code generation, and printable invoice renderers.

---

## 📂 Sub-System Architecture & Module Reference

### 1. Core Plugin Loader (`/`)
- **`sywat-archery-manager.php`** — Master WordPress plugin entry point. Initializes custom database tables, enqueues global CSS/JS assets, sets up admin navigation menus, and bootstraps modular controllers.

### 2. Assets & Styling (`assets/`)
- **`assets/css/sywat-tables.css`** — Table layout engine featuring horizontal overflow wrappers, isolated block width locks (`.sywat-modal-roster-actions`), compact cell padding, and centered vertical stacking containers (`.sywat-table-actions-vertical`) for flush button alignment under table action headers.
- **`assets/css/sywat-modals.css`** — High z-index modal backdrops, dialog container dimensions (`.sywat-modal-lg`, `.sywat-modal-xl`), scrollable bodies, and fixed header/footer structures.
- **`assets/css/sywat-buttons.css`** — Utility classes for standard action buttons, `.sywat-btn-full` block stretch rules, icon badges, and status indicators (`.sywat-badge-open`, `.sywat-badge-full`, `.sywat-waitlist-pill`).
- **`assets/css/sywat-core.css`** — Global typography, CSS flex grid layouts (`.sywat-form-grid-2`, `.sywat-form-grid-4`), alert banners, and color variables.
- **`assets/js/`** — Dedicated client script controllers:
  - `sywat-checkin.js`: Dynamic check-in search, family group inline prompts, and modal toggles.
  - `sywat-registration.js`: Multi-archer dynamic row insertion (`addArcherRow`), distance auto-switch, and group balance calculation.
  - `sywat-roster.js`: Dynamic line-scoped family group selection for archer editing.
  - `sywat-tournaments.js`: Tournament line template repeaters and season modal handlers.
  - `sywat-shops.js`: Shop location editing tabs and CSV/Excel import file preview handlers.

### 3. Authentication & Access Control (`includes/auth/`)
- **`sywat-auth-actions.php`** — User role capability checks (`sywat_can_manage_tournaments()`, `sywat_can_edit_scores()`, `sywat_can_checkin()`).
- **`sywat-auth-views.php`** — Role-restricted administrative wrappers and access denied view notices.

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
- **`registration-modals.php`** — Renders Phone Intake Form, FareHarbor Bulk Upload Form, and Line Slot Usage Table. Includes direct `Manage Roster` navigation links to `class-sywat-roster.php` with line filtering.
- **`registration-scripts.php`** — Localized script loader for intake calculation and line override listeners.

### 6. Archer Roster Sub-System (`includes/roster/`)
- **`class-sywat-roster.php`** — Master sub-module loader shell for archer roster management, division/style restriction enforcement, line-scoped family group assignments, and line purges.
- **`roster-views.php`** — Registered Archer Roster management table (`[sywat_manage_tournaments]` / `modal_action=manage`). Features line dropdown filtering, live name searching, waitlist status detection (`$reg['status'] === 'Waitlist'`) displaying gold `🕒 Waitlist` pills, line purges (`purge_line`), and inline archer edit/removal actions.
- **`roster-modals.php`** — Pop-up modal overlay (`#sywat_edit_archer_modal`) for updating archer profiles, line assignments, divisions, styles, payment statuses, and family group assignments.
- **`roster-actions.php`** — POST request action handlers for saving archer edits (`save_edit`), purging full line rosters (`purge_line`), and deleting archer registrations (`delete_shooter`).
- **`roster-scripts.php`** — Client logic localizing line-scoped family group arrays for dynamic select dropdowns in the edit modal.

### 7. Results & Leaderboards Sub-System (`includes/results/`)
- **`class-sywat-results.php`** — Sub-module loader shell and shortcode binding (`[sywat_results]`).
- **`results-helpers.php`** — Division/gender sort weights, 11-tier style priority sorting, complete roster score retrieval with default `0` mapping, and season standings leaderboard aggregation excluding zero scores and Guest entries.
- **`results-standings.php`** — Public Season Standings UI (`?view=standings`) with multi-variable filters (Division, Gender, Style, Search) and total participation stat banners.
- **`results-tournaments.php`** — Public Tournament Results UI (`?view=tournaments` / `?view=tourn_results`) with automatic Guest style assignment for unturned cards and inline staff edit shortcuts.

### 8. Score Entry Sub-System (`includes/scores/`)
- **`class-sywat-scores.php`** — Sub-module loader shell and shortcode binding (`[sywat_scores]`). Enforces access using `sywat_can_edit_scores()`.
- **`scores-actions.php`** — AJAX single score auto-save and batch save/lock/reset action logic.
- **`scores-export.php`** — CSV score report export generator (`template_redirect`).
- **`scores-scripts.php`** — Client JavaScript for offline draft storage, background auto-save, and alert auto-dismissal.
- **`scores-views.php`** — Score entry filter toolbar, visual Line Score Tracker progress cards, and Archer Scores table UI.

### 9. Archery Shops & Directory Sub-System (`includes/shops/`)
- **`class-sywat-shops.php`** — Sub-module loader for shop directory management.
- **`shops-helpers.php`** — Phone number auto-formatting, seed shop initialization, shop getters, and WP Admin user profile integration.
- **`shops-actions.php`** — Shop CRUD processing, contact array parsing, dynamic line templates, and 2-step dry-run import preview transient processor for Excel `.xlsx`/`.xls` and CSV files.
- **`shops-views.php`** — Admin shortcode controller (`[sywat_manage_shops]`).
- **`shops-public-views.php`** — Public directory shortcode controller (`[sywat_public_shops]`).
- **`views/`** — Sub-folder containing UI template partials (`view-shop-directory.php`, `view-shop-edit.php`, `view-shop-staff.php`, `view-shop-public.php`).

### 10. Tournaments Sub-System (`includes/tournaments/`)
- **`class-sywat-tournament-config.php`** — Sub-module loader shell and shortcode bindings (`[sywat_manage]`, `[sywat_admin]`, `[sywat_manage_tournaments]`, `[sywat_volunteer_hub]`).
- **`tournament-helpers.php`** — Line capacity math, date range formatting, line matchers, and TFAA search queries.
- **`tournament-seasons.php`** — Season Management CRUD UI (`tab=seasons`), live season search, and modal overlay (`#sywat_add_season_modal`).
- **`tournament-actions.php`** — POST request action handlers for dynamic shooting line templates, tournament setup, TFAA standings cache syncing, and season CRUD.
- **`tournament-views.php`** — Master Admin Portal UI protected by `sywat_can_manage_tournaments()` with standardized navigation tabs (*Tournament Management*, *Season Management*), side-by-side shop filters, and line repeaters.
- **`volunteer-hub.php`** — Staff Volunteer Hub portal view (`[sywat_volunteer_hub]`) featuring `.sywat-tracker-grid` line cards and real-time roster stats.

### 11. Standalone Supporting Modules (`includes/`)
- **`class-sywat-accounting.php`** — Financial accounting engine, 50/50 host/TFAA fee split math, family cap calculations, embedded Base64 Zelle QR code rendering, and printable invoice/roster renderers with dynamic shop metadata.
- **`class-sywat-tfaa-sync.php`** — TFAA membership verification, web scraping standings cache engine, standalone sync button (`[sywat_tfaa_sync_btn]`), and daily WP-Cron background sync.

---

## 📜 Version 6.3.1 Changelog Summary

1. **Dedicated Roster Sub-System (`includes/roster/`) Architecture**:
   - Fully modularized roster sub-system across `class-sywat-roster.php`, `roster-views.php`, `roster-modals.php`, `roster-actions.php`, and `roster-scripts.php`.
   - Added full waitlist awareness in `roster-views.php` with explicit `$reg['status'] === 'Waitlist'` detection rendering gold `🕒 Waitlist` pills.
   - Synchronized line filtering parameters (`$filter_line_idx`), enabling deep-linking from registration line slot cards directly into pre-filtered roster management views.

2. **Registration Engine Modernization (`includes/registration/`)**:
   - Replaced legacy view-only modal template (`sywat_render_line_roster_modal`) in `registration-modals.php` with direct navigation links (`<a>`) pointing to `class-sywat-roster.php`, eliminating redundant code and unifying roster management.
   - Updated `sywat_count_registered()` capacity calculations to count active `Pre-Reg` and `Walk-In` shooters while excluding waitlisted archers, ensuring waitlisted shooters do not block physical 20yd/Cub line slots.

3. **UI Button & Table Alignment (`assets/css/sywat-tables.css`)**:
   - Refined `.sywat-table-actions-vertical` styling with centered flex alignment (`align-items: center`) and max-width containers (`max-width: 140px`), ensuring stacked table action buttons align cleanly to the horizontal center of the cell directly underneath column headers.
   - Standardized `.sywat-btn-full` block button styling for uniform stacked layout across admin tables.
