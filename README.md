# SYWAT Archery Tournament Management Plugin

Custom WordPress plugin consolidating multi-tenant shop scoping, tournament administration, check-in operations, score entry, and TFAA data syncing into a unified portal architecture.

## Included Structure & Modules

- **`sywat-archery-manager.php`** — Main plugin header, custom role installer (`staff_shooter`, `management`, `sywat_shop_manager`, `sywat_shop_user`), and asset/module loader.
- **`assets/css/sywat-admin.css`** — Centralized master stylesheet enforcing uniform button palettes, card containers, data tables, and modal overlays across all portal screens.
- **`includes/class-sywat-tournament-config.php`** — Master admin portal shell, tournament configuration CRUD, host shop scoping, live name filter, and Volunteer Hub portal view.
- **`includes/class-sywat-shops.php`** — Multi-tenant shop management CRUD, state selection (TX, OK, LA), staff user account linking, default line templates, and phone formatting.
- **`includes/registration/`** — Modular archer registration & phone intake sub-system:
  - `class-sywat-registration.php` — Sub-module loader shell.
  - `registration-actions.php` — Multi-archer phone intake handler, per-line $75 family capping engine, and database options persistence.
  - `registration-import.php` — FareHarbor CSV roster importer and raw report text parser.
  - `registration-views.php` — Phone intake modal UI, CSV upload form, and current line slot usage table.
  - `registration-scripts.php` — Client-side JavaScript engine (phone number auto-formatting, dynamic archer row addition, and real-time family fee calculation).
- **`includes/checkin/`** — Modular Check-In Desk portal sub-system (`[sywat_desk]`):
  - `class-sywat-checkin.php` — Sub-module loader shell.
  - `checkin-actions.php` — Action handler for check-ins, line closing/reopening, walk-ins, and multi-archer family balance synchronization.
  - `checkin-data.php` — Data aggregation and TFAA / shop profile history matching.
  - `checkin-modals.php` — Check-in and walk-in modals with dynamic real-time unpaid balance banner toggles.
  - `checkin-views.php` — Visual views, line capacity tracker, dynamic return navigation bar, and registered archers table.
- **`includes/scores/`** — Modular Score Entry portal sub-system (`[sywat_scores]`):
  - `class-sywat-scores.php` — Sub-module loader shell and shortcode binding.
  - `scores-actions.php` — Action handlers for AJAX single score auto-save and batch save/lock/reset logic.
  - `scores-export.php` — Early CSV score report export generation (`template_redirect`).
  - `scores-scripts.php` — Client-side JavaScript engine (offline local draft storage, background AJAX auto-save, and input range validation).
  - `scores-views.php` — Score entry filter bar with dynamic return navigation bar and batch scoring table UI.
- **`includes/class-sywat-roster.php`** — Archer roster management, division/style restriction enforcement, family group assignments, and line purges.
- **`includes/class-sywat-accounting.php`** — Financial accounting engine, 50/50 host/TFAA fee split math, family cap calculations, Zelle QR code rendering, and printable invoice/roster renderers with dynamic shop metadata.
- **`includes/class-sywat-tfaa-sync.php`** — TFAA membership verification, web scraping standings cache engine, standalone sync button (`[sywat_tfaa_sync_btn]`), and daily WP-Cron background sync.
