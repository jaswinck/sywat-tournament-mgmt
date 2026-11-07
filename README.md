# SYWAT Archery Tournament Management Plugin

An enterprise-grade, multi-tenant WordPress plugin built for the Texas Field Archery Association (TFAA) and SYWAT Archery to manage indoor archery tournaments, shooter intake, line capacities, score tracking, season standings leaderboards, shop directory management, member & board administration, and financial accounting.

---

## 🛠️ Key System Features & Architecture

- **Multi-Tenant Shop & Indoor Range Scoping**: Isolate shooting lines, rosters, and financial reports by range host/location.
- **Dynamic Shooting Line & Distance Capacity Engine**: Enforces independent 20yd and Cub (10yd) physical lane limits with automatic waitlist overflow tracking.
- **Phone & Group Registration Intake**: Quick caller intake form supporting multi-archer groups, per-line $75 family capping rules, FareHarbor CSV bulk imports, and direct line roster management links.
- **Waitlist-Aware Roster Sub-System**: Unified roster management interface (`includes/roster/`) with deep-linked line filtering, real-time waitlist status badging (`🕒 Waitlist`), line purges, and profile editing.
- **Member & Board Directory Sub-System**: Unified member management interface (`includes/members/`) featuring multi-role capabilities, shop assignments, password policy validation, and official TFAA Board position assignments.
- **Live Scoring & Leaderboard Engine**: Multi-category sorting (Division, Gender, Style), Guest style assignments for unturned cards, season standings leaderboards, and instant CSV export capability.
- **Financial & Host Fee Accounting**: Automatic 50/50 host fee splits, family cap adjustments, embedded Base64 Zelle QR code generation, and printable invoice renderers.

---

## 📂 Sub-System Architecture & Module Reference

### 1. Core Plugin Loader (`/`)
- **`sywat-archery-manager.php`** — Master WordPress plugin entry point. Initializes constants (`SYWAT_PLUGIN_VERSION = 6.5.0`), enqueues modular global CSS/JS assets across both front-end (`wp_enqueue_scripts`) and WP Admin (`admin_enqueue_scripts`) hooks, registers activation hooks, and bootstraps modular controllers.

### 2. Assets & Styling (`assets/`)
- **`assets/css/sywat-tables.css`** (v6.5.0) — Table layout engine featuring horizontal overflow wrappers, percentage column width modifiers (`.sywat-col-w-10` through `.sywat-col-w-32`), row hover states (`.sywat-member-row`, `.sywat-board-row`), isolated block width locks (`.sywat-modal-roster-actions`), compact cell padding, and centered vertical stacking containers (`.sywat-table-actions-vertical`) for flush button alignment under table action headers.
- **`assets/css/sywat-modals.css`** (v6.5.0) — High z-index modal backdrops, dialog container dimensions (`.sywat-modal-lg`, `.sywat-modal-xl`), scrollable bodies, role selection grids (`.sywat-role-grid`), member info banners (`.sywat-member-info-banner`), password rule requirement badges (`.sywat-password-rules-box`, `.sywat-rule-valid`, `.sywat-rule-invalid`), and fixed header/footer structures.
- **`assets/css/sywat-buttons.css`** (v6.4.0) — Utility classes for standard action buttons (`.sywat-btn`), color variants (`.sywat-btn-primary`, `.sywat-btn-success`, `.sywat-btn-danger`), `.sywat-btn-full` block stretch rules, icon badges, automated pseudo-icons (`.sywat-btn-icon-add`, `.sywat-btn-icon-clear`, `.sywat-btn-icon-save`), and status indicators (`.sywat-badge-open`, `.sywat-badge-full`, `.sywat-waitlist-pill`).
- **`assets/css/sywat-core.css`** (v6.4.0) — Global typography, card containers (`.sywat-card`), card-docked tab tracks (`.sywat-nav-tabs`), spacing utilities (`.sywat-mt-3`, `.sywat-mb-4`, `.sywat-ml-0`), alert banners, notice utility classes (`.sywat-notice-banner`), metadata subtext helpers (`.sywat-meta-subtext`), and color variables.
- **`assets/css/sywat-toolbar.css`** (v6.5.0) — Authentication header bar (`.sywat-auth-bar`), integrated card header toolbars (`.sywat-card-header-bar`), table control headers (`.sywat-table-header-bar`), search input groups (`.sywat-search-input-group`), desk filter forms, and TFAA sync pills (`.sywat-sync-pill`).
- **`assets/css/sywat-public.css`** (v6.4.0) — Public leaderboard displays, division cards (`.sywat-div-gen-card`), search input wrappers, participation stat banners (`.sywat-counter-banner`), and front-end styling.
- **`assets/js/`** — Dedicated client script controllers:
  - `sywat-members.js` (v3.0.0): Client JavaScript for the Member sub-module handling phone number formatting (`XXX-XXX-XXXX`), real-time password policy validation badges (`.sywat-rule-valid` / `.sywat-rule-invalid`), role-based shop dropdown toggles (`classList`), live table search filtering, and auto-dismissing system notices.
  - `sywat-checkin.js`: Dynamic check-in search, family group inline prompts, and modal toggles.
  - `sywat-registration.js`: Multi-archer dynamic row insertion (`addArcherRow`), distance auto-switch, and group balance calculation.
  - `sywat-roster.js`: Dynamic line-scoped family group selection for archer editing.
  - `sywat-tournaments.js`: Tournament line template repeaters and season modal handlers.
  - `sywat-shops.js`: Shop location editing tabs and CSV/Excel import file preview handlers.

### 3. Authentication & Access Control (`includes/auth/`)
- **`sywat-auth-actions.php`** — User role capability checks (`sywat_can_manage_tournaments()`, `sywat_can_edit_scores()`, `sywat_can_checkin()`, `sywat_can_create_members()`, `sywat_can_manage_board()`).
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
- **`registration-modals.php`** — Renders Phone Intake Form, FareHarbor Bulk Upload Form, and Line Slot Usage Table. Includes direct `Manage Roster` navigation links to `class-sywat-roster.php` with line filtering.
- **`registration-scripts.php`** — Localized script loader for intake calculation and line override listeners.

### 6. Archer Roster Sub-System (`includes/roster/`)
- **`class-sywat-roster.php`** — Master sub-module loader shell for archer roster management, division/style restriction enforcement, line-scoped family group assignments, and line purges.
- **`roster-views.php`** — Registered Archer Roster management table (`[sywat_manage_tournaments]` / `modal_action=manage`). Features line dropdown filtering, live name searching, waitlist status detection (`$reg['status'] === 'Waitlist'`) displaying gold `🕒 Waitlist` pills, line purges (`purge_line`), and inline archer edit/removal actions.
- **`roster-modals.php`** — Pop-up modal overlay (`#sywat_edit_archer_modal`) for updating archer profiles, line assignments, divisions, styles, payment statuses, and family group assignments.
- **`roster-actions.php`** — POST request action handlers for saving archer edits (`save_edit`), purging full line rosters (`purge_line`), and deleting archer registrations (`delete_shooter`).
- **`roster-scripts.php`** — Client logic localizing line-scoped family group arrays for dynamic select dropdowns in the edit modal.

### 7. Member & Board Directory Sub-System (`includes/members/`)
- **`class-sywat-members.php`** (v3.0.0) — Master sub-module loader shell binding shortcode `[sywat_members]`, enforcing `sywat_can_create_members()`, and enqueuing `assets/js/sywat-members.js`.
- **`members-helpers.php`** — Member data getters, user meta retrieval, TFAA role slug definitions, and official Board of Directors position list builders.
- **`members-actions.php`** — Nonce-verified (`sywat_desk_action`) POST request processor handling account creation, profile edits, password complexity rules, multi-role assignment, host shop assignment, board position management, and self-deletion guards.
- **`members-views.php`** (v3.0.0) — Zero-inline-style UI renderer for Member Directory and Board Management tabs using standard `.sywat-card`, `.sywat-table`, `.sywat-col-w-*`, and metadata subtext classes.
- **`members-modals.php`** (v3.0.0) — Isolated modal overlays for Add Member, Edit Member, Assign Board Member, and Edit Board Positions. Free of embedded `<script>` blocks and inline `style="..."` attributes.

### 8. Results & Leaderboards Sub-System (`includes/results/`)
- **`class-sywat-results.php`** — Sub-module loader shell and shortcode binding (`[sywat_results]`).
- **`results-helpers.php`** — Division/gender sort weights, 11-tier style priority sorting, complete roster score retrieval with default `0` mapping, and season standings leaderboard aggregation excluding zero scores and Guest entries.
- **`results-standings.php`** — Public Season Standings UI (`?view=standings`) with multi-variable filters (Division, Gender, Style, Search) and total participation stat banners.
- **`results-tournaments.php`** — Public Tournament Results UI (`?view=tournaments` / `?view=tourn_results`) with automatic Guest style assignment for unturned cards and inline staff edit shortcuts.

### 9. Score Entry Sub-System (`includes/scores/`)
- **`class-sywat-scores.php`** — Sub-module loader shell and shortcode binding (`[sywat_scores]`). Enforces access using `sywat_can_edit_scores()`.
- **`scores-actions.php`** — AJAX single score auto-save and batch save/lock/reset action logic.
- **`scores-export.php`** — CSV score report export generator (`template_redirect`).
- **`scores-scripts.php`** — Client JavaScript for offline draft storage, background auto-save, and alert auto-dismissal.
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
- **`tournament-helpers.php`** — Line capacity math, date range formatting, line matchers, and TFAA search queries.
- **`tournament-seasons.php`** — Season Management CRUD UI (`tab=seasons`), live season search, and modal overlay (`#sywat_add_season_modal`).
- **`tournament-actions.php`** — POST request action handlers for dynamic shooting line templates, tournament setup, TFAA standings cache syncing, and season CRUD.
- **`tournament-views.php`** — Master Admin Portal UI protected by `sywat_can_manage_tournaments()` with standardized navigation tabs (*Tournament Management*, *Season Management*), side-by-side shop filters, and line repeaters.
- **`volunteer-hub.php`** — Staff Volunteer Hub portal view (`[sywat_volunteer_hub]`) featuring `.sywat-tracker-grid` line cards and real-time roster stats.

### 12. Standalone Supporting Modules (`includes/`)
- **`class-sywat-accounting.php`** — Financial accounting engine, 50/50 host/TFAA fee split math, family cap calculations, embedded Base64 Zelle QR code rendering, and printable invoice/roster renderers with dynamic shop metadata.
- **`class-sywat-tfaa-sync.php`** — TFAA membership verification, web scraping standings cache engine, standalone sync button (`[sywat_tfaa_sync_btn]`), and daily WP-Cron background sync.

---

## 📜 Version 6.5.0 Changelog Summary

1. **Member Sub-Module Modular JS Extraction & Zero-Inline-Style Refactoring**:
   - Extracted all embedded JavaScript out of `members-modals.php` into standalone `assets/js/sywat-members.js`.
   - Purged 100% of inline `style="..."` attributes across `members-views.php` and `members-modals.php`, migrating layout styling to CSS class utility definitions.
   - Refactored DOM state transitions to use CSS class toggles (`classList.add('sywat-hidden')`, `classList.toggle('active')`) instead of direct JS style mutations.
   - Implemented client-side real-time phone formatting (`XXX-XXX-XXXX`), password policy requirement indicators (`.sywat-rule-valid` / `.sywat-rule-invalid`), role-based shop dropdown toggles, table search filtering, and auto-dismissing system notices.

2. **Dual-Context Asset Delivery & Core Shell Updates (`sywat-archery-manager.php`)**:
   - Bumped main plugin version to `6.5.0`.
   - Enqueued modular CSS stylesheets and `sywat-members.js` across both `wp_enqueue_scripts` (front-end) and `admin_enqueue_scripts` (WP Admin) hooks to ensure pixel-perfect styling inside the WP Admin dashboard.

3. **CSS Suite Standardization (`assets/css/`)**:
   - Standardized and synchronized `sywat-buttons.css` (v6.4.0), `sywat-core.css` (v6.4.0), `sywat-modals.css` (v6.5.0), `sywat-tables.css` (v6.5.0), `sywat-toolbar.css` (v6.5.0), and `sywat-public.css` (v6.4.0).
   - Added percentage column width modifiers (`.sywat-col-w-10` through `.sywat-col-w-32`), password requirement badges, role selection grid layouts (`.sywat-role-grid`), notice banners (`.sywat-notice-banner`), and spacing utility helpers (`.sywat-mt-3`, `.sywat-mb-4`, `.sywat-ml-0`).

---

## 📜 Historical Changelogs

### Version 6.3.1
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
