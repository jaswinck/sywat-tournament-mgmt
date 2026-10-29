# SYWAT Archery Tournament Management Plugin

Custom WordPress plugin consolidating multi-tenant shop scoping, tournament administration, check-in operations, score entry, and TFAA data syncing into a unified portal architecture.

## Included Structure & Modules

- `sywat-archery-manager.php` — Main plugin header, custom role installer (`staff_shooter`, `management`, `sywat_shop_manager`, `sywat_shop_user`), and asset/module loader.
- `assets/css/sywat-admin.css` — Centralized master stylesheet enforcing uniform button palettes, card containers, data tables, and modal overlays across all portal screens.
- `includes/class-sywat-tournament-config.php` — Master admin portal shell, tournament configuration CRUD, host shop scoping, live name filter, and Volunteer Hub portal view.
- `includes/class-sywat-shops.php` — Multi-tenant shop management CRUD, state selection (TX, OK, LA), staff user account linking, default line templates, and phone formatting.
- `includes/class-sywat-registration.php` — Quick-add archer registration, FareHarbor CSV roster importer, text report parser, and intake modal UI.
- `includes/class-sywat-roster.php` — Archer roster management, division/style restriction enforcement, family group assignments, and line purges.
- `includes/class-sywat-accounting.php` — Financial accounting engine, 50/50 host/TFAA fee split math, family cap calculations, Zelle QR code rendering, and printable invoice/roster renderers with dynamic shop metadata.
- `includes/class-sywat-checkin.php` — Check-in desk portal shortcode interface (`[sywat_desk]`) for line check-ins, payment verification, walk-ins, and capacity tracking.
- `includes/class-sywat-scores.php` — Batch score entry app (`[sywat_scores]`) with real-time AJAX auto-save, offline local draft syncing, line locking, tie-breakers, and CSV export.
- `includes/class-sywat-tfaa-sync.php` — TFAA membership verification, web scraping standings cache engine, standalone sync button (`[sywat_tfaa_sync_btn]`), and daily WP-Cron background sync.
