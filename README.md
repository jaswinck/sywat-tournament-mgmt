# SYWAT Archery Tournament Management Plugin

Custom WordPress plugin consolidating multi-tenant shop scoping, tournament administration, check-in operations, score entry, season tracking, public standings, role-based access control, member directories, board management, branded desk authentication, and TFAA data syncing into a unified portal architecture.

## Included Structure & Modules

- **`sywat-archery-manager.php`** — Main plugin header (v2.6.0), master sub-module loader, asset enqueuer, and activation hook invoking custom role registration (`tfaa_member`, `tfaa_tournament_director`, `tfaa_field_governor`, `tfaa_admin`, `tfaa_webmaster`, `tfaa_president`, `staff_shooter`, `management`, `non-shooting-member`, `sywat_shop_manager`, `sywat_shop_user`).
- **`assets/css/sywat-admin.css`** — Centralized master stylesheet enforcing uniform button palettes, card containers, centered division headers, left-aligned table data, high-contrast modal overlays, form element theme resets, TFAA live search dropdowns, operator top bars, and branded login cards across all portal screens.

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
- **`sywat-auth-views.php`** — Front-end UI renderer providing the dark Operator Header Bar (`sywat_render_auth_header`) with dynamic role titles across all TFAA Board, Shop, and Member roles, the standalone `[sywat_login]` shortcode for dedicated portal login pages, and the 3-state TFAA Login Card (`sywat_render_login_screen`) with `redirect_to` target preservation:
  - **State 1 (Sign In)**: Username/Email, Password, Remember Me, and Sign In button with "Forgot Password?" link.
  - **State 2 (Request Password Reset)**: Front-end password recovery input triggering reset emails.
  - **State 3 (Set New Password)**: Front-end password update form for operators arriving via email reset tokens.

#### 3. Tournaments Sub-System (`includes/tournaments/`)
- **`class-sywat-tournament-config.php`** — Sub-module loader shell and shortcode bindings (`[sywat_manage]`, `[sywat_admin]`, `[sywat_manage_tournaments]`, `[sywat_volunteer_hub]`).
- **`tournament-helpers.php`** — Line capacity calculations, date range formatting, and season getters.
- **`tournament-seasons.php`** — Season Management CRUD UI (`tab=seasons`), live season search bar, pop-up modal overlay (`#sywat_add_season_modal`), auto-generated season names/year labels (e.g., *"2026–2027 Indoor"*), and newest-to-oldest sorting logic.
- **`tournament-actions.php`** — POST request action handlers for tournament configuration, line counts, and role-restricted season management.
- **`tournament-views.php`** — Master Admin Portal UI protected by `sywat_can_manage_tournaments()` with standardized navigation tabs (*Tournament Management*, *Season Management*, *Shop Management*), chronological tournament sorting (soonest to farthest out), side-by-side shop filter dropdown and search input, and compact TFAA stat chip.
- **`volunteer-hub.php`** — Staff Volunteer Hub portal view (`[sywat_volunteer_hub]`) featuring line check-in/scoring shortcuts and real-time roster stats.

#### 4. Archery Shops Sub-System (`includes/shops/`)
- **`class-sywat-shops.php`** — Sub-module loader shell for multi-tenant shop scoping and management.
- **`shops-helpers.php`** — Phone number auto-formatting, seed shop initialization, shop getters, current shop context resolution, and WP Admin user profile integration (`Users > Edit User`).
- **`shops-actions.php`** — Shop CRUD action processing (creation, updates, deletions, and staff account linking/unlinking).
- **`shops-views.php`** — Shop Management UI (`tab=shops`), directory table with live shop search, edit shop view, staff assignment view, and pop-up shop creation modal overlay.

#### 5. Public Results & Standings Sub-System (`includes/results/`)
- **`class-sywat-results.php`** — Sub-module loader shell and shortcode binding (`[sywat_results]`). Features conditional `✏️ Enter Scores` desk shortcuts in the tournament directory table for authorized staff (`sywat_can_edit_scores()`).
- **`results-helpers.php`** — Division/gender sort weights (Pro Male -> Male -> Pro Female -> Female -> Senior), 11-tier style sort weight priority (Freestyle through Guest), score getters, and season standings aggregation excluding re-entries.
- **`results-standings.php`** — Public Season Standings UI (`?view=standings`) with multi-variable filters (Division, Gender, Style, Archer Search) and centered division header cards.
- **`results-tournaments.php`** — Public Tournament Results UI (`?view=tournaments` / `?view=tourn_results`) with automatic Guest style assignment for multi-line re-entries, live result filters, and inline `✏️ Edit` shortcut buttons next to archer names for logged-in staff (`sywat_can_edit_scores()`).

#### 6. Archer Registration Sub-System (`includes/registration/`)
- **`class-sywat-registration.php`** — Sub-module loader shell.
- **`class-sywat-member-signup.php`** — Administrative Member Creation Portal and shortcode binding (`[sywat_member_signup]`). Restricted to `administrator`, `tfaa_webmaster`, and `tfaa_admin` via `sywat_can_create_members()`. Handles WP User insertion with `tfaa_member` role, metadata attachment (`sywat_tfaa_number`, `sywat_phone`), password hashing, and input validation.
- **`registration-actions.php`** — Multi-archer phone intake handler, per-line $75 family capping engine, duplicate/capacity blocking banners, and database options persistence.
- **`registration-import.php`** — FareHarbor CSV roster importer and raw report text parser.
- **`registration-views.php`** — Phone intake modal UI, CSV upload form, and current line slot usage table.
- **`registration-scripts.php`** — Client-side JavaScript engine (phone number auto-formatting, dynamic archer row addition, real-time family fee calculation, TFAA autocomplete dropdown, caller name population, and 5-second banner auto-dismissal).

#### 7. Check-In Desk Sub-System (`includes/checkin/`)
- **`class-sywat-checkin.php`** — Sub-module loader shell and shortcode binding (`[sywat_desk]`). Enforces access using `sywat_can_access_desk()`.
- **`checkin-actions.php`** — Action handler for check-ins, line closing/reopening, walk-ins, and multi-archer family balance synchronization.
- **`checkin-data.php`** — Data aggregation and TFAA / shop profile history matching.
- **`checkin-modals.php`** — Check-in and walk-in modals with dynamic real-time unpaid balance banner toggles.
- **`checkin-views.php`** — Visual views, line capacity tracker, dynamic return navigation bar, and registered archers table.

#### 8. Score Entry Sub-System (`includes/scores/`)
- **`class-sywat-scores.php`** — Sub-module loader shell and shortcode binding (`[sywat_scores]`). Enforces access using `sywat_can_edit_scores()`.
- **`scores-actions.php`** — Action handlers for AJAX single score auto-save and batch save/lock/reset logic.
- **`scores-export.php`** — Early CSV score report export generation (`template_redirect`).
- **`scores-scripts.php`** — Client-side JavaScript engine (offline local draft storage, background AJAX auto-save, and input range validation).
- **`scores-views.php`** — Score entry filter bar with dynamic return navigation bar and batch scoring table UI.

#### 9. Members & Board Roster Sub-System (`includes/members/`)
- **`class-sywat-members.php`** — Sub-module loader shell and master shortcode binding (`[sywat_members]`). Binds `sywat_render_members_portal` to handle public board roster views, member directory search, and administrative role updates.
- **`members-helpers.php`** — Resolves user board designations (`tfaa_president`, `tfaa_field_governor`, etc.), maps district assignments (`sywat_tfaa_district`), checks active status, and formats contact info.
- **`members-actions.php`** — Action handlers for updating member profiles, assigning custom TFAA roles, toggling active/inactive status, and updating TFAA membership numbers.
- **`members-views.php`** — Public Board of Directors roster with district contact buttons, searchable Member Directory cards, and the administrative Member Management panel (`tab=members`).

#### 10. Standalone Supporting Modules
- **`class-sywat-roster.php`** — Archer roster management, division/style restriction enforcement, family group assignments, line purges, and high z-index nested edit archer modal overlay (`#sywat_edit_archer_modal`).
- **`class-sywat-accounting.php`** — Financial accounting engine, 50/50 host/TFAA fee split math, family cap calculations, Zelle Base64 QR code rendering, and printable invoice/roster renderers with dynamic shop metadata.
- **`class-sywat-tfaa-sync.php`** — TFAA membership verification, web scraping standings cache engine, standalone sync button (`[sywat_tfaa_sync_btn]`), and daily WP-Cron background sync.
