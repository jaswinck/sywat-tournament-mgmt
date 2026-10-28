# SYWAT Archery Tournament Management Plugin

Custom WordPress plugin consolidating tournament administration, check-in operations, score entry, and TFAA data syncing into a unified portal architecture.

## Included Modules
* **`sywat-tournament-mgmt.php`** — Main plugin header, role definition installer, and module loader.
* **`includes/class-sywat-tournaments.php`** — Tournament setup, roster import (CSV/FareHarbor), archer editing, and financial invoice generation (Snippet 25).
* **`includes/class-sywat-checkin.php`** — Check-in desk shortcode interface for line check-ins and lane assignments (Snippet 22).
* **`includes/class-sywat-scores.php`** — Score entry app with AJAX auto-save, tie-breaking algorithms, and CSV export tools (Snippet 37).
* **`includes/class-sywat-tfaa-sync.php`** — TFAA membership verification and state standings cache engine (Snippet 38).
