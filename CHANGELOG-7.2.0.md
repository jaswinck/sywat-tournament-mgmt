# Changelog — Version 7.2.0

**Release Version:** `7.2.0` (`SYWAT_PLUGIN_VERSION`)  
**Release Summary:** Introduces Shop-level Public Online Registration defaults, per-tournament registration window gatekeeping, synchronized List/Calendar schedule registration CTAs, and the modular Member Online Self-Registration engine (`[sywat_member_register]`) with a zero-default per-archer multi-line checkbox matrix.

---

## 1. Shop-Level Public Online Registration Defaults & Tournament Window Gatekeeper
- **`includes/shops/shops-actions.php` (v5.1.0) & `includes/shops/shops-modals.php`**:
  - Added default Public Online Registration settings (`public_reg_default_enabled` and `public_reg_default_lead_days`) to Host Shop create and edit modals so each shop can define its standard online registration lead window.
- **`includes/tournaments/tournament-helpers.php` (v7.6.0)**:
  - Added centralized gatekeeper helper `sywat_get_public_registration_status()` to evaluate whether a tournament's online registration is `open`, `scheduled` (with formatted `Opens [Date]` label), `closed`, or routed to an external host link based on per-tournament overrides or inherited shop defaults.
- **`includes/tournaments/tournament-modals.php` (v7.11.0), `includes/tournaments/tournament-actions.php` (v7.17.0), & `includes/tournaments/tournament-views.php` (v7.20.0)**:
  - Configured new and bulk-imported tournaments to inherit their host shop's default online registration toggle and lead-time days while supporting per-event overrides in the Add/Edit Tournament modal.
  - Added live status pills (`Public Reg: Open`, `Public Reg: Opens [Date]`, `Public Reg: Off`) to the Tournament Management table.

---

## 2. Public Schedule Integration, Shortcode Routing & Navigation
- **`includes/core/class-sywat-frontend.php`**:
  - Registered `[sywat_member_register]` in `sywat_shortcode_tags()` for conditional asset loading, optimizer opt-outs, `Cache-Control: no-cache` headers, and Divi Visual Builder placeholders.
  - Added the configurable `member_register` (`/register-online/`) page-URL key to `sywat_page_defaults()` under **Settings → SYWAT Display**.
- **`includes/schedule/schedule-helpers.php` (v1.3.0), `includes/schedule/views/view-schedule-public.php` (v1.3.0), & `assets/js/sywat-schedule.js` (v1.4.0)**:
  - Enriched schedule payloads with `public_reg_status`, `sywat_reg_url`, and `public_reg_opens_label`.
  - Synchronized **Register Online** links (`?tournament=<event>`) and **Opens [Date]** lead-time badges across both List View rows and Calendar View event details modals.
- **`includes/auth/sywat-auth-views.php` (v5.9.1)**:
  - Kept the top `Tournaments ▾` dropdown focused on **📅 Season Schedule**, **🏆 Results & Standings**, and staff-gated **🎯 Tournament Portal** so members enter online registration directly from the Season Schedule with the target event pre-selected.

---

## 3. Member Online Self-Registration Engine (`[sywat_member_register]`)
- **`includes/registration/class-sywat-registration.php` (v6.1.0)**:
  - Bound shortcode `[sywat_member_register]` and wired the new modular self-registration sub-files (`member-registration-data.php`, `member-registration-helpers.php`, `member-registration-actions.php`, and `views/view-member-registration.php`).
- **`includes/registration/member-registration-data.php` (v1.0.0)**:
  - Pure read-only data layer retrieving open tournaments (`sywat_get_public_registration_status()`), scoped tournament registrations, Head of Household and linked dependent records, and active division options.
- **`includes/registration/member-registration-helpers.php` (v1.2.0)**:
  - Pure calculation and formatting layer resolving tournament-date NFAA age divisions, target distances (`20yd` vs. `Cub`), fee rate tiers, per-line slot availability maps, per-archer/per-line $O(1)$ existing registration status maps (`by_line_idx` / `by_line_name`), and complete `\$view_data` payloads (`sywat_member_reg_build_view_data()`).
- **`includes/registration/member-registration-actions.php` (v1.1.0)**:
  - Nonce-verified POST handler (`sywat_member_self_register`) processing per-archer multi-line checkbox selections (`member_lines[member_key][]`) so individual household members can register for 1 or multiple shooting lines in a single submission.
  - Validates open-tournament status, line locks, and per-line duplicate guards; books open slots as `Pre-Reg` (balance due at check-in) or automatically places full-line entries on `Waitlist` (`\$0.00` due); prevents duplicate First-Time/Lapsed Shooter promo claims across lines; applies per-line Household Family Caps; and returns line-by-line confirmation summaries (`line_summaries`).
- **`includes/registration/views/view-member-registration.php` (v1.3.0)**:
  - Pure HTML view template rendering guest sign-in prompts, direct selected-tournament Event Details, toolbar-matched `📅 Season Schedule` and `👤 My Household` header actions, live **Shooting Line Availability** summary table, **Per-Archer Multi-Line Checkbox Matrix** (with zero pre-selected defaults and per-line `Pre-Reg` / `Waitlist` status badges), line-by-line confirmation breakdowns, and **Complete Online Registration** + **Cancel** (return to Season Schedule) controls.

---

## 4. Plugin Version & Cache Busting
- **`sywat-archery-manager.php` (v7.2.0)**:
  - Bumped plugin header `Version` and `SYWAT_PLUGIN_VERSION` constant to `7.2.0` to invalidate cached front-end scripts and stylesheets.
