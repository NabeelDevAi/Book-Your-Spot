# Venu365 — Software Requirements Specification & Functional Requirements Document

**Version:** 1.0
**Document Type:** SRS + FRD (Combined)
**Release Target:** V1 — Full Functional Release (not MVP)
**Tech Stack:** Laravel (PHP), MySQL, Blade views — Desktop web only, not responsive
**Last Updated:** 27 July 2026

---

## 1. Introduction

### 1.1 Purpose
Venu365 is a web platform that lets sports and gaming venue owners ("Businesses") list their games/activities and individual bookable units ("Spots") for reservation, and lets Users discover, filter, and request bookings for those spots. This document defines the complete functional and non-functional requirements for the first full version (V1) of the platform.

### 1.2 Scope
V1 covers three user roles — **Business Owner**, **User (Customer)**, and **Admin** — operating on a single web application (desktop-only, not mobile-responsive) built on Laravel + MySQL. V1 supports all sport and game verticals (cricket, futsal, padel, snooker, PS5/gaming zones, table tennis, etc.) — the platform is category-agnostic by design.

**Out of scope for V1** (explicitly deferred — see Section 12):
- Mobile app (native or responsive web)
- Online payments / payment gateway integration
- Advertisements
- Premium/no-ads subscription tier
- Commission billing automation
- Ratings & reviews
- In-app chat or player matchmaking
- Multi-city / multi-timezone support (V1 assumes single city, single timezone: Asia/Karachi)

### 1.3 Definitions

| Term | Definition |
|---|---|
| **Business** | A venue/owner account entity — e.g. "Cue & Console Gaming Zone." One owner account can operate one or more Businesses. |
| **Game** | A category of activity offered by a Business — e.g. Snooker, PS5, Futsal, Padel. Games are drawn from an Admin-managed master list. |
| **Spot** | A single physical bookable unit belonging to a Business's Game — e.g. "Snooker Table 1," "PS5 Room A," "Futsal Court 2." Spots are what actually get reserved. |
| **Reservation / Booking** | A User's request to occupy a Spot for a specific date/time range, subject to Owner approval. |
| **Slot** | A discrete bookable time unit generated from a Spot's pricing/duration rules (e.g. 10-minute snooker blocks, 60-minute futsal blocks). |
| **Owner** | The authenticated account that manages one or more Businesses. |
| **Admin** | Platform-level oversight account (internal, not self-registerable). |

### 1.4 Product Hierarchy

```
Business (owned by Owner account)
   └── Game (category, e.g. Snooker, PS5, Futsal — from Admin master list)
         └── Spot (physical unit, e.g. Table 1, Room A, Court 2)
               └── Pricing rule (rate per time unit, min/max duration)
               └── Availability schedule (operating hours per day)
               └── Reservations (bookings against this spot)
```

This hierarchy is the backbone of the entire data model and every functional module below is built around it.

---

## 2. Overall Description

### 2.1 Product Perspective
Venu365 is a new, standalone three-sided platform (Owner / User / Admin). It is not an extension of an existing system. V1 is a full functional release intended for real-world pilot use in a single geographic cluster within Karachi, not a throwaway prototype — but online payments, mobile apps, and monetization features are deliberately deferred to keep V1 shippable and focused.

### 2.2 User Classes and Characteristics

| Role | Description | Technical Literacy Assumption |
|---|---|---|
| **Business Owner** | Runs a gaming/sports venue. May manage multiple locations under one account. Time-poor, wants minimal-friction daily use. | Low-to-medium. UI must be simple; onboarding likely assisted in-person for early pilot businesses. |
| **User** | End customer looking to book a spot to play. | Medium. Comfortable with basic web forms and account creation. |
| **Admin** | Internal platform operator (you / your team). | High. Comfortable with dashboards, moderation tools, and raw data views. |

### 2.3 Operating Environment
- Server: Laravel (latest stable LTS-compatible version), PHP 8.x
- Database: MySQL
- Frontend: Laravel Blade templates, server-rendered, desktop-only CSS (no mobile breakpoints in V1)
- Single timezone: Asia/Karachi (hardcoded assumption for V1)
- Single currency: PKR (hardcoded, no multi-currency support)

### 2.4 Constraints
- No online payment processing in V1 — all payments are collected in person at the venue ("pay at spot").
- Not responsive — V1 is desktop-browser only. Mobile users will get a usable but non-optimized layout; this is an accepted tradeoff, not a bug.
- Single-city assumption baked into V1 (no city/region selector required, though the `city` field should still exist on Business for future-proofing).

### 2.5 Assumptions and Dependencies
- Businesses are manually vetted/approved by Admin before going live (no fully automatic self-listing in V1 — see 6.3.1).
- Owners are trusted to input accurate operating hours and pricing; Admin has override/edit capability for moderation.
- Users must register to make a reservation; browsing/search is open to guests (see 6.2.1).

---

## 3. User Roles & Permissions Matrix

| Capability | Guest | User | Owner | Admin |
|---|:---:|:---:|:---:|:---:|
| Browse/search businesses & spots | ✅ | ✅ | ✅ | ✅ |
| Register/login | — | ✅ | ✅ | (internal only) |
| Create a reservation | ❌ | ✅ | ❌* | ❌* |
| View own booking history | — | ✅ | — | — |
| Register a Business | ❌ | ❌ | ✅ | ✅ (on behalf of owner) |
| Manage own Business profile | — | — | ✅ | ✅ (override) |
| Add/edit/delete own Games & Spots | — | — | ✅ | ✅ (override) |
| Approve/reject reservations on own Business | — | — | ✅ | ✅ (override) |
| View own Business dashboard/analytics | — | — | ✅ | — |
| Approve/suspend any Business | — | — | ❌ | ✅ |
| Manage master Game category list | — | — | ❌ | ✅ |
| View/manage all Users | — | — | ❌ | ✅ |
| View/manage all Reservations platform-wide | — | — | ❌ | ✅ |
| Platform-wide reports/analytics | — | — | ❌ | ✅ |
| Handle disputes/flags | — | — | ❌ | ✅ |

\* An Owner may hold a User account separately to book at *other* businesses, but cannot book their own Business's spots through the normal flow (see edge case 9.14).

---

## 4. Functional Requirements

### 4.1 Module: Authentication & Account Management

**FR-1.1** Users can self-register with name, email, phone number, and password.
**FR-1.2** Owners can self-register as an Owner account (separate registration flow/flag from User), then must create at least one Business, which enters a "Pending Approval" state (see 4.3.1).
**FR-1.3** Admin accounts are not self-registerable; created directly in DB/seeder or by a Super Admin only.
**FR-1.4** Email or phone verification is required before a User can create a reservation (verification mechanism: email link or OTP — implementation detail, but must exist; unverified accounts can browse only).
**FR-1.5** Standard login/logout/password reset flows for User and Owner.
**FR-1.6** Role-based route/middleware protection: a User cannot access Owner or Admin routes and vice versa, enforced server-side regardless of UI.
**FR-1.7** An Owner account can be linked to multiple Businesses (one owner running several venues under one login).

### 4.2 Module: Business, Game & Spot Management (Owner)

**FR-2.1** Owner can create a Business profile: name, description, address, city, area/locality, contact number, operating hours (per day of week), and up to N images (define N, e.g. 5).
**FR-2.2** New Business submissions default to status `pending_review` and are not visible to Users until Admin approves (see 4.3.1).
**FR-2.3** Owner selects one or more Games for their Business from the Admin-managed master Game list (e.g. Snooker, PS5, Futsal, Cricket, Padel, Table Tennis). Owner cannot invent new top-level Game categories — only Admin can add new ones to the master list (prevents fragmented/duplicate taxonomy, e.g. "PS5" vs "Playstation 5" vs "PS 5").
**FR-2.4** For each Game a Business offers, the Owner adds one or more Spots. Each Spot has:
   - Spot name/label (e.g. "Table 1", "Room A")
   - Pricing: rate + billing unit (e.g. Rs. 100 per 10 minutes; Rs. 2,500 per 60 minutes)
   - Minimum booking duration and maximum booking duration
   - Spot-level operating hours (defaults to Business hours, but can be overridden per spot, e.g. one court closes earlier)
   - Spot status: `active` / `inactive` (owner can temporarily disable a spot for maintenance without deleting it)
   - Optional images specific to that spot
**FR-2.5** Owner can edit or deactivate (not hard-delete if it has booking history — see 9.9) any Spot or Game listing at any time.
**FR-2.6** Owner dashboard shows, per Business: today's reservations, pending approval count, upcoming reservations, occupancy overview per Spot, and basic historical booking counts.
**FR-2.7** Owner can view and manage all reservations for their Business(es): filter by status (pending/approved/rejected/completed/cancelled/no-show), date range, and Spot.
**FR-2.8** Owner can manually mark a completed reservation as `no_show` after the booked time has passed without the customer arriving (self-reported by owner in V1, since there's no payment/check-in system yet).
**FR-2.9** Owner can block off ad hoc time ranges on a Spot (e.g. private event, personal use) without creating a fake reservation — this must render as unavailable to Users in search/booking.

### 4.3 Module: Admin Oversight

**FR-3.1 (Business Approval)** Admin reviews `pending_review` Businesses and can Approve (→ `active`, visible to Users) or Reject (→ `rejected`, with a reason field, owner notified).
**FR-3.2** Admin can suspend an already-active Business at any time (e.g. complaints, fraud, inactivity), which immediately hides it from search and auto-cancels/flags any future pending reservations for Owner+User notification (see edge case 9.10).
**FR-3.3** Admin manages the master Game category list: add, rename, deactivate categories. Deactivating a category does not delete existing Business-Game links but prevents new ones.
**FR-3.4** Admin can view/search/manage all Users and Owners: view profile, suspend/ban an account (e.g. repeat no-show abuse, spam reservations).
**FR-3.5** Admin can view all reservations platform-wide with full filtering (business, user, date, status, city/area).
**FR-3.6** Admin has override edit access to any Business/Game/Spot listing (for correcting owner errors or moderating content).
**FR-3.7** Admin dashboard: platform-wide metrics — total businesses (by status), total users, total reservations (by status), reservations over time, most-booked categories/areas, businesses with high rejection/no-show rates (early fraud/quality signal).
**FR-3.8** Admin can manually create a Business on behalf of an Owner (useful for early pilot onboarding done in person/over the phone rather than pure self-serve).

### 4.4 Module: Search, Discovery & Booking (User)

**FR-4.1** Users (and guests) can browse a list/grid of active Businesses, filterable by:
   - Game/category (Snooker, PS5, Futsal, etc.)
   - City/area/locality
   - Price range
   - Availability (date + time range — only show Businesses/Spots with at least one open Spot matching)
**FR-4.2** Business detail page shows: profile info, operating hours, all Games offered, and all Spots under each Game with live pricing and current availability calendar.
**FR-4.3** User selects a Spot, chooses a date, start time, and duration (bounded by that Spot's min/max duration rules and billing unit — e.g. duration must be in 10-minute increments for a snooker table billed per 10 minutes).
**FR-4.4** System validates in real time that the requested time range does not overlap any existing `approved` or `pending` reservation, any owner-blocked range, or fall outside operating hours, before allowing submission (see 9.1, 9.5).
**FR-4.5** On submission, a reservation is created with status `pending` and the Owner is notified (in-app + email at minimum).
**FR-4.6** Owner has a defined response window (configurable, default e.g. 2 hours or until X time before slot start — see 9.2) to Approve or Reject. On approval → status `confirmed`, User notified. On rejection → status `rejected` with optional reason, User notified, slot released.
**FR-4.7** If the Owner does not respond within the window, the reservation auto-expires → status `expired`, slot released, both parties notified (see 9.2).
**FR-4.8** User can view booking history: upcoming (pending/confirmed) and past (completed/cancelled/rejected/expired/no-show).
**FR-4.9** User can cancel a `pending` or `confirmed` reservation up until a configurable cutoff before start time (e.g. 1 hour prior); cancellations after the cutoff are still allowed but flagged as late-cancel for the Owner's/Admin's visibility (no payment penalty possible in V1 since no payment is collected upfront).
**FR-4.10** A `confirmed` reservation automatically transitions to `completed` once its end time has passed, unless the Owner marks it `no_show` instead.

### 4.5 Module: Notifications
**FR-5.1** System-generated notifications (in-app minimum; email and/or SMS/WhatsApp as available) are triggered on: new reservation request (→ Owner), approval/rejection (→ User), auto-expiry (→ both), cancellation (→ Owner), Business approval/rejection (→ Owner), Business suspension (→ Owner), reminder before an upcoming confirmed booking (→ User, and optionally Owner).

---

## 5. Reservation Status Lifecycle

```
                 ┌──────────┐
   User books →  │ pending  │
                 └────┬─────┘
        ┌─────────────┼───────────────┬───────────────┐
        ▼             ▼               ▼               ▼
   [approved by   [rejected by   [auto-expired,   [cancelled
    Owner/Admin]   Owner/Admin]   no response]      by User]
        │
        ▼
  ┌────────────┐
  │ confirmed  │
  └─────┬──────┘
        │
   ┌────┼─────────────────┐
   ▼    ▼                 ▼
[cancelled       [time passes,        [time passes,
 by User,         Owner takes          Owner marks
 pre-cutoff or    no action]           no_show]
 late-flagged]         │
                        ▼
                  ┌────────────┐
                  │ completed  │
                  └────────────┘
```

**States:** `pending`, `confirmed`, `rejected`, `expired`, `cancelled`, `completed`, `no_show`

---

## 6. Data Model (Entity Overview)

| Entity | Key Fields |
|---|---|
| **users** | id, name, email, phone, password, role (`user`/`owner`/`admin`), status (`active`/`suspended`), email_verified_at, phone_verified_at, timestamps |
| **businesses** | id, owner_id (FK → users), name, description, address, city, area, contact_number, status (`pending_review`/`active`/`rejected`/`suspended`), rejection_reason, operating_hours (JSON per weekday), timestamps |
| **business_images** | id, business_id, image_path, sort_order |
| **games** (master list, admin-managed) | id, name, slug, status (`active`/`inactive`) |
| **business_games** | id, business_id (FK), game_id (FK) — a Business's offering of a given Game category |
| **spots** | id, business_game_id (FK), name, price_amount, price_unit_minutes, min_duration_minutes, max_duration_minutes, status (`active`/`inactive`), operating_hours_override (JSON, nullable), timestamps |
| **spot_images** | id, spot_id, image_path, sort_order |
| **spot_blocks** | id, spot_id, start_datetime, end_datetime, reason, created_by (owner/admin) — for owner-blocked non-reservation downtime |
| **reservations** | id, spot_id (FK), user_id (FK), start_datetime, end_datetime, status, requested_at, responded_at, response_deadline, cancelled_by, rejection_reason, no_show_flagged_by, timestamps |
| **notifications** | id, user_id, type, payload (JSON), read_at, timestamps |
| **audit_logs** | id, actor_id, actor_role, action, target_type, target_id, meta (JSON), timestamps — recommended for admin moderation traceability |

---

## 7. Non-Functional Requirements

**NFR-1 (Data Integrity):** No two reservations may be created for overlapping time ranges on the same Spot with status `pending` or `confirmed` — enforced at the application layer with a database-level uniqueness/overlap check (row-locking or transaction-based check-then-insert) to prevent race conditions (see 9.1).

**NFR-2 (Security):** Passwords hashed (bcrypt/argon2 via Laravel defaults). Role-based middleware on every route group. CSRF protection on all forms (Laravel default). Input validation via Form Requests on every write endpoint.

**NFR-3 (Performance):** Search/filter queries on Businesses/Spots should return in an acceptable time for a single-city dataset (a few hundred to low thousands of businesses) — proper indexing on city/area, game_id, and status columns.

**NFR-4 (Availability):** No formal SLA required for V1 pilot, but daily automated MySQL backups are required given this handles real business operations.

**NFR-5 (Usability):** Owner-side flows (adding a spot, responding to a reservation) must be completable in minimal clicks — this audience has low tolerance for complex UI.

**NFR-6 (Auditability):** All Admin override actions (editing a listing, suspending a business/user) must be logged with actor, timestamp, and reason where applicable.

**NFR-7 (Not Responsive):** Explicitly out of scope — layouts are fixed-width, desktop-target only. No mobile breakpoint testing required for V1.

---

## 8. Reports & Analytics (Admin)

- Total businesses by status (pending/active/rejected/suspended)
- Total reservations by status, filterable by date range
- Bookings per category (which Games are most booked)
- Bookings per area/locality
- Businesses ranked by reservation volume, rejection rate, and no-show-flag rate (quality/fraud signal)
- New user/owner signups over time

---

## 9. Edge Cases & Business Rules (Must Be Handled in V1)

1. **Concurrent booking race condition:** Two Users attempt to reserve the same Spot/overlapping time simultaneously. The system must guarantee only one succeeds (transaction-level overlap check before insert) and the second gets a clear "slot no longer available" response, not a silent double-booking.
2. **Owner non-response window:** A `pending` reservation must have a defined expiry (e.g. auto-expire if unactioned by X hours before the slot start, or after a fixed response window from request time — decide and hardcode one rule for V1). Without this, unresponded requests pile up and block the slot indefinitely for other Users.
3. **User cancels after Owner approval:** Reservation moves to `cancelled`, slot is released back to availability, Owner is notified. Distinguish "on-time cancellation" vs. "late cancellation" (past the cutoff) for the Owner's/Admin's visibility, even without financial penalty in V1.
4. **Owner needs to cancel an already-confirmed reservation** (e.g. equipment broke, double-booked outside the app): Owner can cancel with a mandatory reason; User is notified immediately; this event should be tracked/flagged since owner-side cancellations reflect on Business reliability.
5. **Booking outside operating hours:** System must reject any booking attempt (User-side) or Spot creation attempt (Owner-side, for blocks) that falls outside the applicable operating hours (Spot override, else Business default).
6. **Owner blocks a spot for maintenance/personal use** mid-day: existing `pending`/`confirmed` reservations already inside that window are NOT auto-cancelled silently — this must trigger a conflict flag for Owner to manually resolve (contact the customer), since silently cancelling a confirmed customer booking is a trust-breaking failure mode.
7. **Duration/pricing unit mismatch:** A booking duration must be validated against the Spot's `min_duration_minutes`, `max_duration_minutes`, and be a valid multiple of `price_unit_minutes` (e.g. can't book 7 minutes on a table billed in 10-minute blocks) — reject with a clear message and suggested valid durations.
8. **Business suspended by Admin while it has future confirmed reservations:** All future `pending`/`confirmed` reservations for that Business must be auto-flagged/cancelled with explicit notification to affected Users (with reason: "venue temporarily unavailable"), not silently orphaned.
9. **Owner deletes/deactivates a Spot with existing future reservations:** Deactivation must be soft (status flag), never a hard delete, if any reservation history exists — hard delete only permitted if the Spot has zero reservation history ever. Deactivating a Spot with future confirmed bookings should warn the Owner and require explicit confirmation, with the same auto-flag/notify pattern as case 8.
10. **Price change on a Spot:** Changing price applies only to *new* bookings going forward; already-`confirmed` reservations retain the price that was active at time of booking (store price snapshot on the reservation record, don't just reference live Spot price).
11. **Duplicate/spam Business registration:** Same phone number or address attempting multiple Business registrations should be flagged for Admin review rather than auto-approved, to catch accidental duplicates or bad-faith listings.
12. **Repeat no-show Users:** Since there's no payment penalty mechanism in V1, repeated `no_show` flags against a User should be visible to Admin (and ideally surfaced to Owners before approving that specific User's future requests) as the only available deterrent — consider a "no-show count" badge on the user's booking request visible to the Owner at approval time.
13. **Guest browsing vs. booking:** Guests can search/filter/view Business and Spot detail pages fully, but any "Book Now" action redirects to login/register — never silently fail or hide pricing from guests (pricing transparency drives trust and is a stated goal of the whole platform).
14. **Owner attempting to book their own Business's Spot as a "customer":** Either explicitly block this (Owner accounts cannot submit reservations on Businesses they own) or, if allowed, auto-approve without going through the pending/notification flow — must be a deliberate decision, not an accidental gap (recommend: block it, and let Owners use the ad hoc block feature — 4.2/FR-2.9 — instead for personal use of their own spot).
15. **Invalid/past date-time selection:** Reject any booking attempt for a start time in the past, both at UI level and server-side validation (never trust client-side date pickers alone).
16. **Empty search results:** Explicitly handle and display "no matching spots found" with a suggestion to broaden filters, rather than a blank page.
17. **Business with zero active Spots:** Should not appear in User-facing search results even if the Business itself is `active`, since there's nothing bookable — Business visibility should be conditional on having at least one `active` Spot.
18. **Multiple pending reservations by same User:** Decide and enforce a cap (e.g. max 3 simultaneous `pending` reservations per User) to prevent one user from tying up multiple Owners' response queues speculatively — recommend a cap for V1.
19. **Rejection reason handling:** Rejection should support (at minimum) a short predefined reason set (e.g. "Slot unavailable", "Fully booked", "Other") plus optional free text, so Owners aren't forced to type an explanation every time but Users still get context.
20. **Admin override edits during an active dispute:** Any Admin edit to a Business/Spot/Reservation that a User or Owner has flagged/disputed should be logged in `audit_logs` with the reason, to protect against "who changed what" disputes later.

---

## 10. Assumptions Log

- V1 targets a single geographic cluster/city; multi-city fields exist in the schema for future-proofing but are not exposed as filters yet if only one city is live.
- No payment collection means trust/no-show mitigation in V1 relies entirely on notification discipline, no-show flagging, and Admin moderation — not financial penalty. This is an accepted limitation of V1, to be revisited when payments are introduced.
- "Full functional V1" here means: complete, production-usable core booking loop for all three roles, robust edge-case handling, and an Admin oversight layer — not feature-complete against every possible future addition (payments, mobile, ads, reviews, matchmaking are deliberately V2+).

---

## 11. Success Criteria for V1

- An Owner can fully self-onboard a Business, Game(s), and Spot(s) without developer intervention.
- A User can search, filter, and successfully complete a booking request end-to-end, and receive a clear confirmation or rejection.
- No double-booking occurs under concurrent load on the same Spot/time range.
- Admin has full visibility and override control over every Business, Spot, User, and Reservation on the platform.
- All 20 edge cases in Section 9 are explicitly handled (not just "won't crash" — each has a defined, intentional behavior).

---

## 12. Deferred to V2+ (Explicitly Out of Scope for V1)

- Online payment integration (JazzCash/Easypaisa/card) and payment-based no-show penalties
- Commission billing/invoicing automation for Owners
- Advertisements
- Premium/no-ads subscription tier for Users
- Mobile app (native) and responsive web layout
- Ratings & reviews
- In-app chat / player matchmaking / team formation
- Multi-city, multi-timezone, multi-currency support
- Tournament/event hosting features
