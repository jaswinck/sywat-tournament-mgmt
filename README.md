# SYWAT Archery Tournament Management Plugin

Custom WordPress plugin consolidating multi-tenant shop scoping, tournament administration, check-in operations, score entry, season tracking, public standings, role-based access control, member directories, board management, branded desk authentication, financial invoicing, Excel/CSV directory imports, and TFAA data syncing into a unified portal architecture.

---

## Included Structure & Assets

- **`sywat-archery-manager.php`** — Main plugin header (v6.3.0), master sub-module loader, asset enqueuer, and activation hook invoking custom role registration (`tfaa_member`, `tfaa_tournament_director`, `tfaa_field_governor`, `tfaa_admin`, `tfaa_webmaster`, `tfaa_president`, `staff_shooter`, `management`, `non-shooting-member`, `sywat_shop_manager`, `sywat_shop_user`).

### Style & Asset Bundle (`assets/`)

- **`assets/css/sywat-buttons.css`** — Dedicated button palette stylesheet enforcing uniform color tiers (`.sywat-btn-primary`, `.sywat-btn-secondary`, `.sywat-btn-success`, `.sywat-btn-success-solid`, `.sywat-btn-danger`, `.sywat-btn-global-push`), dedicated solid green primary CTA styling for high-volume row check-ins, outline success triggers for general actions, pill action triggers (`.sywat-btn-pill`, `.sywat-btn-use-official`), icon alignment utilities, hover states, and full-width block sizing.
- **`assets/css/sywat-core.css`** — Centralized master stylesheet enforcing card containers (`.sywat-card`, `.sywat-card-sm`), structural utilities (`.sywat-hidden`), form input wrappers (`.sywat-field-group`), card header bars (`.sywat-card-header-bar`), table toolbars (`.sywat-table-header-bar`), navigation tab tracks (`.sywat-nav-tabs`), centered division headers, left-aligned table data, descender padding resets (`min-height: 38px`, `line-height: 1.5`), operator top bars, branded login cards, unified single-category headers (`.sywat-single-category-header`), and dynamic participation stat banners (`.sywat-counter-banner`).
- **`assets/css/sywat-modals.css`** — High-contrast modal overlay styling (`.sywat-modal-backdrop`), dialog containers (`.sywat-modal-content`), modal header/footer structures, modal action split wrappers (`.sywat-modal-form-actions-split`), capacity overflow warning modals (`.sywat-modal-overflow-backdrop`), waitlist pills (`.sywat-waitlist-pill`), TFAA status badges (`.sywat-badge-verified`, `.sywat-badge-known`, `.sywat-badge-none`), class-driven TFAA autocomplete dropdowns (`.sywat-desk-tfaa-dropdown`), multi-style alert callouts (`.sywat-multi-style-alert`), high z-index nested modal overlays, and `@media print` engine reset rules.
- **`assets/css/sywat-public.css`** — Dedicated public-facing stylesheet styling read-only tournament results tables, season standings leaderboards, member directory search grids, and public location directory cards.
- **`assets/css/sywat-tables.css`** — Responsive data table structures (`.sywat-table-responsive`), cell padding alignments, hover states, alignment helper classes (`.sywat-col-center`, `.sywat-col-right`), stacked vertical action button containers (`.sywat-table-actions-vertical`), family prompt callout banners (`.sywat-family-banner`), owe status badges, and table column width lock utilities.
- **`assets/css/sywat-toolbar.css`** — Dedicated toolbar stylesheet managing filter forms (`.sywat-filter-bar-form`), field alignment wrappers (`.sywat-field-auto`), select dropdown specificity overrides (`.sywat-select-tournament`, `.sywat-select-line`), search input groups, and line capacity tracker grid cards (`.sywat-tracker-grid`).
- **`assets/images/logo.png`** — TFAA brand asset logo graphic.
- **`assets/images/zelle-qr-code.png`** — Accounting payment Zelle QR code graphic asset.
- **`assets/js/sywat-checkin.js`** — Client-side JavaScript engine managing check-in desk modals, TFAA autocomplete dropdowns, dynamic family group selectors, unpaid balance banners, and division/style restriction enforcement.
- **`assets/js/sywat-registration.js`** — Client-side JavaScript engine managing phone intake forms, TFAA autocomplete dropdowns, dynamic archer row repeaters, real-time family fee calculation, line capacity prompts, session state line memory, and interactive alert banner retention.
- **`assets/js/sywat-shops.js`** — Client-side JavaScript engine managing tabbed location editing, dynamic contact repeaters, shooting line schedule repeaters, phone number formatting, and live directory/district filtering.

---

### Module Sub-Systems (`includes/`)

#### 1. Core System & Security Sub-System (`includes/core/`)
- **`class-sywat-permissions.php`** — Sub-module for automatic custom WordPress role registration and global permission check helpers:
  - `sywat_can_manage_tournaments()` — Master tournament portal access.
  - `sywat_can_manage_seasons()` — Season CRUD & global settings access.
  - `sywat_can_manage_shops()` — Shop CRUD access.
  - `sywat_can_edit_scores()` — Score entry desk & public edit shortcut access.
  - `sywat_can_access_desk()` — Check-In Desk portal access.
  - `sywat_can_create_members()` — Account creation access (restricted to `administrator`, `tfaa_webmaster`, `tfaa_admin`).
  - `sywat_get_user_district()` — District scoping helper for Field Governors.

#### 2. TFAA Desk Authentication Sub-System (`includes/auth/`)
- **`sywat-auth-actions.php`** — Backend authentication action handler managing user sign-in via `wp_signon()`, dynamic post-login role redirection via `sywat_get_post_login_redirect_url()`, secure sign-out via `wp_logout()`, lost password email requests via `retrieve_password()`, and new password updates via `reset_password()` with nonce security and inline error tracking.
- **`sywat-auth-views.php`** — Front-end UI renderer providing the dark Operator Header Bar (`sywat_render_auth_header`) with dynamic role titles across all TFAA Board, Shop, and Member roles, streamlined `Tournaments ▼` navigation dropdown pointing directly to the Tournament Portal, standalone `[sywat_login]` shortcode for dedicated portal login pages, and 3-state TFAA Login Card (`sywat_render_login_screen`) with `redirect_to` target preservation:
  - **State 1 (Sign In)**: Username/Email, Password, Remember Me, and Sign In button with "Forgot Password?" link.
  - **State 2 (Request Password Reset)**: Front-end password recovery input triggering reset emails.
  - **State 3 (Set New Password)**: Front-end password update form for operators arriving via email reset tokens.

#### 3. Check-In Desk Sub-System (`includes/checkin/`)
- **`class-sywat-checkin.php`** — Sub-module loader shell and shortcode binding (`[sywat_desk]`). Enforces permission access via `sywat_can_access_desk()`, enqueues `sywat-checkin.js`, localizes data state via `wp_add_inline_script()`, and renders permission notices inside clean `.sywat-card-sm` containers.
- **`checkin-actions.php`** — Action handler for check-ins, line closing/reopening, walk-in registrations, and multi-archer family balance synchronization.
- **`checkin-data.php`** — Data aggregation engine featuring transient caching for historical profile lookups (`sywat_history_profiles_cache`), line index resolution, and TFAA / shop profile history matching.
- **`checkin-modals.php`** — Pre-registration check-in, edit, and walk-in modal overlays with real-time unpaid balance warning banners, zero inline styles, and class-driven TFAA autocomplete dropdowns (`.sywat-desk-tfaa-dropdown`).
- **`checkin-views.php`** — Visual views, line capacity tracker grid cards, filter bar toolbar, family prompt banners, and registered archers data table featuring centered `.sywat-table-actions-vertical` column with top-positioned solid green primary CTA (`.sywat-btn-success-solid`) and secondary edit triggers.

#### 4. Third-Party Library Dependencies (`includes/lib/`)
- **`SimpleXLSX.php`** — High-performance PHP XLSX reader library for parsing Excel spreadsheet files (`.xlsx` / `.xls`) during shop/club directory imports without requiring external server extensions.

#### 5. Members & Board Roster Sub-System (`includes/members/`)
- **`class-sywat-members.php`** — Sub-module loader shell and master shortcode binding (`[sywat_members]`). Binds `sywat_render_members_portal` to handle public board roster views, member directory search, and administrative role updates.
- **`members-helpers.php`** — Resolves user board designations (`tfaa_president`, `tfaa_field_governor`, etc.), maps district assignments (`sywat_tfaa_district`), checks active status, and formats contact info.
- **`members-actions.php`** — Action handlers for updating member profiles, assigning custom TFAA roles, toggling active/inactive status, and updating TFAA membership numbers.
- **`members-modals.php`** — Modal overlay interfaces for member profile editing and role assignments.
- **`members-views.php`** — Public Board of Directors roster with district contact buttons, searchable Member Directory cards, and administrative Member Management panel (`tab=members`).

#### 6. Archer Registration Sub-System (`includes/registration/`)
- **`class-sywat-registration.php`** — Sub-module loader shell.
- **`registration-actions.php`** — Multi-archer phone intake handler, per-line $75 family fee capping engine, 3-choice Capacity Overflow & Waitlist Engine (entire group waitlisting with $0 due, available spot booking + waitlist remainder, or cancel/line change), payload pre-validation, form state retention on overflow/error, duplicate/capacity blocking alerts, and database options persistence.
- **`registration-import.php`** — FareHarbor CSV roster importer and raw report text parser with pre-import capacity tally and overflow warning prompts.
- **`registration-modals.php`** — Renderers for phone intake form with form payload pre-filling, FareHarbor CSV bulk upload panel, line slot usage table with live `🕒 Waitlisted` counters, and class-driven capacity overflow modal backdrop wrapper (`.sywat-modal-overflow-backdrop`).
- **`registration-views.php`** — Shortcode handler (`[sywat_register]`), main registration card UI, active tournament selector bar, line slot usage component dispatcher, and high-concurrency scoped database query execution.
- **`registration-scripts.php`** — Client-side JavaScript enqueuer localizing cached TFAA and phone data (`assets/js/sywat-registration.js`), managing phone formatting, dynamic archer rows, fee calculations, TFAA autocomplete, caller name population, and interactive alert banner retention.

#### 7. Public Results & Standings Sub-System (`includes/results/`)
- **`class-sywat-results.php`** — Sub-module loader shell and shortcode binding (`[sywat_results]`). Features conditional `✏️ Enter Scores` desk shortcuts in the tournament directory table for authorized staff (`sywat_can_edit_scores()`).
- **`results-helpers.php`** — Division/gender sort weights (Pro Male -> Male -> Pro Female -> Female -> Senior), 11-tier style sort weight priority (Freestyle through Guest), complete roster score retrieval with automatic mapping of un-submitted/blank DB scores to `0` (`sywat_get_tournament_scores`), and season standings aggregation explicitly excluding Guests and zero-score cards (`sywat_aggregate_season_standings`).
- **`results-standings.php`** — Public Season Standings UI (`?view=standings`) with multi-variable filters (Division, Gender, Style, Archer Search), inline search reset button, unified single-category headers (`.sywat-single-category-header`), and dynamic participation stat banner (`.sywat-counter-banner`) calculating total standings leaderboard entries supporting multi-style archers.
- **`results-tournaments.php`** — Public Tournament Results UI (`?view=tournaments` / `?view=tourn_results`) with automatic Guest style assignment for unturned cards (`0` scores) and multi-line re-entries, dynamic participation stat banner (`.sywat-counter-banner`), live result filters with inline search clear action buttons, and inline `✏️ Edit` shortcut buttons next to archer names for logged-in staff (`sywat_can_edit_scores()`).

#### 8. Score Entry Sub-System (`includes/scores/`)
- **`class-sywat-scores.php`** — Sub-module loader shell and shortcode binding (`[sywat_scores]`). Enforces access using `sywat_can_edit_scores()`.
- **`scores-actions.php`** — Action handlers for AJAX single score auto-save and batch save/lock/reset logic.
- **`scores-export.php`** — Early CSV score report export generation (`template_redirect`).
- **`scores-scripts.php`** — Client-side JavaScript engine (offline local draft storage, background AJAX auto-save, input range validation, and 4-second notice/alert banner auto-dismissal).
- **`scores-views.php`** — Score entry filter toolbar with standardized `Active Tournament:` selection, visual Line Score Tracker grid card (displaying completion progress badges, line lock statuses, and 1-click line entry buttons), dynamic return navigation bar, and clean Archer Scores table UI.

#### 9. Archery Shops & Directory Sub-System (`includes/shops/`)
- **`class-sywat-shops.php`** — Sub-module loader shell for multi-tenant shop scoping and management.
- **`shops-helpers.php`** — Phone number auto-formatting, seed shop initialization, shop getters, current shop context resolution, and WP Admin user profile integration (`Users > Edit User`).
- **`shops-actions.php`** — Shop CRUD action processing (creation, updates, deletions, primary and additional contacts array parsing, dynamic default shooting lines, 2-step dry-run import preview transient processor with Excel `.xlsx`/`.xls` and CSV parsing, delimiter auto-detection, UTF-8 BOM stripping, safe merge/upsert and skip modes, and staff account linking/unlinking).
- **`shops-views.php`** — Admin shortcode controller (`[sywat_manage_shops]`) enqueuing `sywat-shops.js` and routing to modular view partials.
- **`shops-public-views.php`** — Public shortcode controller (`[sywat_public_shops]`) enqueuing `sywat-shops.js` and loading the public directory view partial.
- **`views/`** — Modular sub-folder containing focused UI template partials:
  - **`view-shop-directory.php`** — Admin directory list table with district filtering, search, location creation modal, and 2-step dry-run import preview modal (`📥 Import Locations`).
  - **`view-shop-edit.php`** — Tabbed Location Editor UI (*📍 Profile & Address*, *👥 Contacts*, *🎯 SYWAT Indoor Lines*, *🌲 SYWAT Outdoor Lines*) featuring primary contact fields, dynamic additional contact repeaters (`➕ Add Additional Contact`), facilities checkboxes, address details, and dynamic default shooting line template repeaters (`➕ Add New Line`).
  - **`view-shop-staff.php`** — Staff account assignment and unlinking management panel for shops and clubs.
  - **`view-shop-public.php`** — Public-facing shop and club directory listing with primary/additional contact search integration and range amenity badges.

#### 10. Tournaments Sub-System (`includes/tournaments/`)
- **`class-sywat-tournament-config.php`** — Sub-module loader shell and shortcode bindings (`[sywat_manage]`, `[sywat_admin]`, `[sywat_manage_tournaments]`, `[sywat_volunteer_hub]`).
- **`tournament-helpers.php`** — Line capacity calculations, date range formatting, line matchers, TFAA shooter search queries, and season getters.
- **`tournament-seasons.php`** — Season Management CRUD UI (`tab=seasons`), live season search bar, pop-up modal overlay (`#sywat_add_season_modal`), auto-generated season names/year labels (e.g., *"2026–2027 Indoor"*), and newest-to-oldest sorting logic.
- **`tournament-actions.php`** — POST request action handlers for dynamic shooting line templates array processing without hardcoded limits, tournament configuration, TFAA standings cache syncing, and role-restricted season management.
- **`tournament-views.php`** — Master Admin Portal UI protected by `sywat_can_manage_tournaments()` with standardized navigation tabs (*Tournament Management*, *Season Management*), chronological tournament sorting (soonest to farthest out), side-by-side shop filter dropdown and search input, side-by-side Start/End Date grid fields in the edit modal, dynamic shooting lines repeater (`➕ Add New Line` / `🗑️ Remove`), standardized action buttons, and compact TFAA stat chip.
- **`volunteer-hub.php`** — Staff Volunteer Hub portal view (`[sywat_volunteer_hub]`) featuring `.sywat-tracker-grid` line cards, check-in/scoring shortcuts, and real-time roster stats.

#### 11. Standalone Supporting Modules (`includes/`)
- **`class-sywat-roster.php`** — Archer roster management, division/style restriction enforcement, line-scoped family group assignments, line purges, and high z-index nested edit archer modal overlay (`#sywat_edit_archer_modal`).
- **`class-sywat-accounting.php`** — Financial accounting engine, 50/50 host/TFAA fee split math, family cap calculations, embedded Base64 Zelle QR code rendering for print/PDF reliability, and printable invoice/roster renderers with dynamic shop metadata.
- **`class-sywat-tfaa-sync.php`** — TFAA membership verification, web scraping standings cache engine, standalone sync button (`[sywat_tfaa_sync_btn]`), and daily WP-Cron background sync.
