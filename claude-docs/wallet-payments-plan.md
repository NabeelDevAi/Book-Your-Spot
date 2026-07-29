# BookYourSpot — Wallet, Payments & Cancellation Plan

**Version:** 1.0 (implementation plan, pre-code)
**Supersedes:** SRS §12 deferral of "Online payment integration"
**Amends:** SRS §2.4 ("no online payment processing"), §4.4 FR-4.9, §9.3, §10
**Status:** Phases 1–7 built and green. **The money loop is closed** — funds can enter, move, and leave.

| Phase | State |
|---|---|
| 1 — Ledger core | **Done** |
| 2 — Top-ups | **Done**, against a simulated gateway |
| 3 — Booking integration | **Done** |
| 4 — Cancellation & refunds | **Done** |
| 5 — Maturity & owner wallet | **Done**, including the Owner earnings console |
| 6 — Withdrawals | **Done** — request queue, manual settlement over local rails, reversal on reject/fail |
| 7 — Admin oversight & hardening | **Done** — float dashboard, live reconciliation, wallet inspector, adjustments, freeze, chargebacks, refund-to-source |
| 8 — Promo credit rails | Not started. Referrals must wait on phone verification regardless. |

---

## 1. What this changes about the product

V1 collects payment in person. This plan replaces that with a **closed-loop prepaid wallet**:

1. User tops up their wallet in PKR. (Currently simulated — see §2.1. With a live provider the money lands in the platform bank account.)
2. Booking is paid **in full from wallet balance**. No cash at the venue, ever.
3. On approval the amount moves to the Owner's wallet as **pending** earnings.
4. Earnings **mature** to withdrawable once the booking completes and a dispute window passes.
5. Owner requests a withdrawal; an Admin settles it over local bank rails and marks it paid.

**Users cannot withdraw.** Wallet balance is service credit, spendable on bookings only. Refunds
return to wallet. Refund-to-source exists only as an Admin-executed exception (§9.4).

### 1.1 What does *not* change

The reservation lifecycle is untouched. No new `ReservationStatus` cases, no new deadline, no new
sweep on the booking side.

This is the central design result and it is worth stating plainly. `ReservationStatus::blocking()`
deliberately excludes `pending`, so several customers may queue on one slot and the first approval
wins. With card payments that would mean charging four people and refunding three — card refunds
cost money and take days, so payment would have had to move *after* approval, requiring an
`awaiting_payment` state, a payment deadline, a second sweep, and a rework of
`autoRejectCompeting()`.

A **wallet hold is free to place and free to release**. So funds can be reserved at request time,
the losers' holds released at the moment they are auto-rejected, and the existing state machine
carries the money layer unchanged.

```
request()        → place hold on user wallet        (funds reserved, balance untouched)
approve()        → capture hold → owner pending     (money moves)
                 → autoRejectCompeting() releases every loser's hold
reject()         → release hold
expire()         → release hold
cancel()         → resolve per refund matrix        (§6)
complete()       → owner pending → withdrawable     (after dispute window)
flagNoShow()     → forfeit to owner, matures normally
```

### 1.2 Commission

Explicitly **out of scope** for this plan, per instruction. The ledger is designed so a platform
fee can be introduced later as an additional split at capture time without a migration to the
transaction table — `wallet_transactions.type` is an enum-backed string and a `platform_fee` type
plus a platform-owned wallet is an additive change.

---

## 2. Known risks — read before approving

**2.1 Payments are simulated.** There is no card processor. `SimulatedGateway`
credits a wallet instantly and nothing is charged. Stripe was removed outright
rather than left half-wired: it has no merchant support for Pakistani entities
and cannot pay out to PK bank accounts, so it could never have gone live.

What that does and does not cover is worth being precise about. **Only the
money-in leg is fake.** The ledger, holds, capture to an owner, refund tiers,
maturity window and withdrawal debit all run through the same `WalletService`
code a live provider would drive, and reconcile the same way. So the accounting
is genuinely under test; only the front door is pretend.

Reintroducing a real provider is a new class implementing `PaymentGateway` plus
one line in `AppServiceProvider`. An asynchronous provider additionally needs
its own callback controller calling `TopupService::fulfil()`, which is already
idempotent and still covered by tests driven through a fake asynchronous
gateway.

The outbound leg is unaffected either way — withdrawals were always going to be
manual bank transfers over local rails.

**2.2 Float is a liability.** Money in the platform account belongs to users until spent and to
owners until withdrawn. It is not revenue. It must be accounted for as a liability, and there is a
regulatory question (SBP) around holding customer balances that sits outside this document.
Flagging once, not re-litigating — this is a business decision already taken.

**2.3 Chargeback exposure.** A user can top up Rs 50,000, spend it, and dispute the card charge.
The money is already in an owner's wallet. Mitigations in §7.5.

**2.4 Money events need notifications that do not exist yet.** All notifications are currently
database-channel only (`MAIL_MAILER=log`). "Your wallet was credited", "your refund was processed",
"your withdrawal was paid" are exactly the messages users check for. This plan ships in-app
notifications; the email/SMS work already parked elsewhere becomes materially more urgent once real
money moves.

**2.5 No email verification.** `RegisteredUserController` waives FR-1.4. Once wallets exist, an
unverified account is a fraud vector. This does not block Phases 1–6 but should gate nothing
promotional (referrals) until verification lands.

---

## 3. Money representation

**All ledger amounts are integer paisa (`bigint`).** No floats, no decimals in the money path.

`PricingCalculator::total()` currently returns a float via `round($units * $rate, 2)`. Rather than
convert a float to paisa at the boundary, add an exact integer path:

```php
// PricingCalculator
public function totalMinor(Spot $spot, int $durationMinutes): int
{
    // price_amount is decimal(10,2); convert to paisa first, then multiply by
    // an integer unit count. No float arithmetic occurs at any point.
    return Money::toMinor($spot->price_amount) * $this->units($spot, $durationMinutes);
}
```

`App\Support\Money` gains:

```php
public static function toMinor(int|float|string $rupees): int;   // "2500.00" -> 250000
public static function fromMinor(int $paisa): float;             // 250000 -> 2500.00
public static function pkrMinor(int $paisa): string;             // 250000 -> "Rs. 2,500"
```

`toMinor()` takes the decimal *string* from the database wherever possible and parses it, falling
back to `(int) round($value * 100)` only for float input. The existing `pkr()` display helpers stay
as they are for spot pricing; `pkrMinor()` is used everywhere a wallet amount is rendered.

Existing `reservations.total_price` (`decimal(10,2)`) stays for display and reporting continuity. A
new `amount_paid_minor` column becomes the authoritative figure for anything financial.

---

## 4. Schema

### 4.1 `wallets` — one per user, both roles

| Column | Type | Notes |
|---|---|---|
| `id` | bigint pk | |
| `user_id` | fk unique | one wallet per account |
| `balance_minor` | bigint **signed**, default 0 | settled funds. Spendable (user) / withdrawable (owner) |
| `held_minor` | bigint signed, default 0 | reserved by active holds. User side only |
| `pending_minor` | bigint signed, default 0 | earned, not yet matured. Owner side only |

> **Deviation from the original draft of this plan:** the balance columns are
> **signed**, not unsigned. A Phase 7 chargeback reversal claws back money the
> customer has already spent and can legitimately drive a balance below zero;
> an unsigned column would need an `ALTER` on a live money table at exactly the
> moment nobody wants one. The non-negative rule therefore lives in
> `WalletService`, which knows about the single case permitted to break it.
| `status` | string(20) | `active` / `frozen` |
| `frozen_reason` | text nullable | |
| `timestamps` | | |

**`available = balance_minor − held_minor`.** This is the figure shown to a user and the figure
checked before a hold. `held_minor` never exceeds `balance_minor` — enforced as an invariant in
`WalletService`. `CHECK` constraints are added on MySQL for `held_minor >= 0` and
`pending_minor >= 0` only; there is deliberately none on `balance_minor`, for the chargeback reason
above.

The row exists to be **locked**. Every balance mutation takes `lockForUpdate()` on it, which is why
balances are cached columns rather than derived sums — a derived balance has no lockable row and
concurrent spends race.

Wallets are created lazily on first need (`WalletService::for(User)` does a
`firstOrCreate` inside the caller's transaction), so no backfill migration is required.

### 4.2 `wallet_transactions` — append-only ledger

| Column | Type | Notes |
|---|---|---|
| `id` | bigint pk | |
| `wallet_id` | fk | |
| `amount_minor` | bigint **signed** | positive = credit, negative = debit |
| `bucket` | string(10) | `balance` or `pending` — which column this row moved |
| `type` | string(30) | see §4.3 |
| `balance_after_minor` | bigint | the bucket's value after this row. Makes reconciliation and dispute forensics tractable without replaying the whole table |
| `reservation_id` | fk nullable | |
| `topup_id` | fk nullable | |
| `withdrawal_id` | fk nullable | |
| `counterparty_wallet_id` | fk nullable | the other side of a transfer |
| `idempotency_key` | string unique nullable | |
| `meta` | json | |
| `created_at` | timestamp | **no `updated_at`** |

Never updated, never deleted — same discipline as `audit_logs`. A correction is a new reversing
row. `created_at` only, because a row that can be touched after the fact is not a ledger.

Indexes: `(wallet_id, created_at)` for statements, `(reservation_id)`, `(type, created_at)` for
reporting.

### 4.3 `WalletTransactionType` enum

| Value | Bucket | Sign | Raised by |
|---|---|---|---|
| `topup` | balance | + | `TopupService::fulfil()` |
| `booking_payment` | balance | − | `approve()` capture, user side |
| `booking_earning` | pending | + | `approve()` capture, owner side |
| `booking_refund` | balance | + | `cancel()`, user side |
| `earning_reversal` | pending | − | `cancel()`, owner side |
| `earning_matured_out` | pending | − | maturity sweep |
| `earning_matured_in` | balance | + | maturity sweep |
| `withdrawal_debit` | balance | − | withdrawal request |
| `withdrawal_reversal` | balance | + | withdrawal rejected/failed |
| `admin_adjustment` | either | ± | Admin tool, reason mandatory |
| `chargeback_reversal` | balance | − | dispute webhook |

Maturity is deliberately **two rows**, not one. A single row cannot represent movement between two
buckets while keeping `balance_after_minor` meaningful for each.

### 4.4 `wallet_holds`

| Column | Type | Notes |
|---|---|---|
| `id` | bigint pk | |
| `wallet_id` | fk | |
| `reservation_id` | fk **unique** | one hold per reservation, ever |
| `amount_minor` | bigint unsigned | |
| `status` | string(20) | `active` / `captured` / `released` |
| `released_reason` | string(40) nullable | `rejected`, `expired`, `cancelled`, `slot_taken`, `admin` |
| `resolved_at` | timestamp nullable | |
| `timestamps` | | |

Holds are **not** ledger rows. They reserve funds without moving them; the ledger records only real
movement. `unique(reservation_id)` is what makes double-holding structurally impossible rather than
merely unlikely.

Index `(wallet_id, status)` for the available-balance calculation and for an orphan-hold sweep.

### 4.5 `topups`

| Column | Notes |
|---|---|
| `id`, `user_id` | |
| `amount_minor` | bigint unsigned |
| `status` | `pending` / `succeeded` / `failed` / `cancelled` |
| `gateway` | string — which provider produced the row |
| `gateway_reference` | string **unique** nullable |
| `gateway_charge_id` | string nullable |
| `failure_code`, `failure_message` | nullable |
| `succeeded_at` | nullable |
| `timestamps` | |

### 4.6 Callback idempotency — not built

The original plan had a `stripe_events` table keyed on the provider's event id.
With a synchronous simulated gateway there are no callbacks to deduplicate, so
it was dropped. `TopupService::fulfil()` is still idempotent on its own (row lock
plus a unique `wallet_transactions.idempotency_key`), which is what an
asynchronous provider will need. Whoever adds one should reinstate an events
table alongside it: a callback that can be replayed and a ledger that can be
credited twice are the same bug.

### 4.7 `withdrawals`

| Column | Notes |
|---|---|
| `id`, `reference` (unique, `BYSW-` prefix) | quoted in support conversations |
| `owner_id` | fk users |
| `amount_minor` | bigint unsigned |
| `status` | `requested` / `approved` / `processing` / `paid` / `rejected` / `failed` |
| `payout_account_snapshot` | json — bank details **as they were at request time** |
| `requested_at` | |
| `processed_by`, `processed_at` | nullable, fk users |
| `external_reference` | nullable — the bank transfer's reference |
| `failure_reason` | nullable |
| `timestamps` | |

Snapshotting bank details follows the same rule the codebase already applies to price (SRS 9.10): a
later edit to the payout account must not rewrite what was actually paid where.

### 4.8 `payout_accounts`

`owner_id`, `bank_name`, `account_title`, `account_number`, `iban` nullable, `is_default`,
`verified_at` nullable, timestamps.

### 4.9 Changes to `reservations`

| Column | Notes |
|---|---|
| `amount_paid_minor` | bigint unsigned nullable — authoritative paid figure |
| `refund_policy_snapshot` | json — the tier table in force at booking time |
| `refund_amount_minor` | bigint unsigned nullable — what the user got back |
| `owner_settlement_minor` | bigint unsigned nullable — what the owner kept |
| `earnings_matured_at` | timestamp nullable |

Snapshotting the refund policy is the same principle as `price_amount_snapshot`. Policies change;
disputes are argued against the policy the customer agreed to, not today's.

---

## 5. `WalletService` — the only thing that moves money

Mirrors the existing rule in `ReservationService` ("nothing outside this class writes
`reservations.status`"). **Nothing outside `WalletService` writes a wallet balance or a ledger row.**

```php
final class WalletService
{
    public function for(User $user): Wallet;

    public function availableMinor(Wallet $w): int;         // balance - held

    public function credit(Wallet $w, int $minor, WalletTransactionType $t, array $ctx): WalletTransaction;
    public function debit(Wallet $w, int $minor, WalletTransactionType $t, array $ctx): WalletTransaction;

    public function hold(Wallet $w, Reservation $r, int $minor): WalletHold;   // throws InsufficientFunds
    public function release(WalletHold $h, string $reason): void;
    public function capture(WalletHold $h, Wallet $ownerWallet): void;         // user held -> owner pending

    public function matureEarnings(Reservation $r): void;                      // owner pending -> balance
    public function reverseEarning(Reservation $r, int $minor, string $reason): void;
}
```

### 5.1 Invariants, asserted on every mutation

1. `balance_minor >= 0`
2. `held_minor >= 0` and `held_minor <= balance_minor`
3. `pending_minor >= 0`
4. Every balance change writes exactly one `wallet_transactions` row with correct `balance_after_minor`
5. A transfer writes two rows referencing each other via `counterparty_wallet_id`
6. Amounts are always positive integers at the API boundary; sign is the ledger's business
7. A `frozen` wallet rejects every mutation except `admin_adjustment`

Violations throw `WalletException` — a hard failure, never a silent clamp. A clamped balance is a
lost rupee that nobody notices until reconciliation.

### 5.2 Lock ordering — mandatory

Global acquisition order, applied everywhere without exception:

```
spots  →  reservations  →  wallets (ascending wallet id)
```

`approve()` already takes the spot lock; wallet locks are acquired last, and when two wallets are
locked in one transaction they are ordered by ascending `id`. This is what prevents the classic
A-B / B-A deadlock between two captures touching the same pair of wallets. Cheap insurance, and it
must be a written rule because the failure mode only appears under production concurrency.

### 5.3 Transaction boundaries

Every money operation joins the **existing** `DB::transaction` in `ReservationService`, never opens
its own nested one. Money and status must commit or roll back together — a captured hold against an
unconfirmed reservation is unrecoverable without manual intervention.

Notifications continue to fire **after** commit, matching the pattern already used throughout
`ReservationService`.

---

## 6. Cancellation & refund resolution

### 6.1 Policy (config, snapshotted per reservation)

```php
// config/wallet.php
'refund_tiers' => [
    ['min_hours_before' => 24, 'refund_percent' => 100],
    ['min_hours_before' => 2,  'refund_percent' => 50],
    ['min_hours_before' => 0,  'refund_percent' => 0],
],
```

### 6.2 Resolution matrix

| Event | User receives | Owner receives |
|---|---|---|
| Cancel > 24h before start | 100% | 0% |
| Cancel 2–24h before start | 50% | 50% |
| Cancel < 2h before start | 0% | 100% |
| **Owner cancels — any time** | **100%** | **0%** + reliability hit |
| **Admin cancels** (business suspended, SRS 9.8) | **100%** | **0%** |
| No-show (owner-flagged) | 0% | 100% |
| Rejected / expired / auto-rejected | hold released, nothing moved | — |

The owner-cancellation asymmetry is the point. `cancelled_by_role` already exists on the
reservation, so this is a `match` on one field. An owner must never be able to cancel a paid booking
at zero cost.

### 6.3 `RefundResolver`

```php
final class RefundResolver
{
    /** @return array{user_minor: int, owner_minor: int} */
    public function resolve(Reservation $r, CancellationEvent $event): array;
}
```

**Hard invariant: `user_minor + owner_minor === amount_paid_minor`, exactly, in every branch.**
Percentages are applied with integer division and the remainder is assigned to the user, so rounding
never creates or destroys a paisa. This gets a property test over every tier × actor combination.

Because refunds resolve against the owner's **`pending_minor`**, which is not withdrawable, a
clawback from a settled owner balance is never required. That is the whole reason for the maturity
rule and it should not be softened for convenience.

### 6.4 Existing code paths that now move money

These already call `ReservationService::cancel()` and therefore inherit refunds automatically, but
each needs a test proving it:

- `Admin\BusinessModerationService` — suspending a business with future confirmed bookings (SRS 9.8)
- Spot deactivation with future bookings (SRS 9.9)
- `Booking\ConflictService` — owner blocks over a confirmed booking (SRS 9.6). Raises a conflict
  rather than cancelling, so money moves only when the owner resolves it by cancelling.

---

## 7. Top-ups

### 7.1 Limits

| Rule | Value |
|---|---|
| Minimum top-up | Rs 500 |
| Maximum top-up | Rs 50,000 |
| Amount | arbitrary between the two |

Enforced in `StartTopupRequest` **and** re-checked in `TopupService` — the request
guards one HTTP endpoint, the service guards the operation.

### 7.2 Flow (simulated)

1. Customer submits an amount → validated → `topups` row written as `pending`.
2. `SimulatedGateway::createIntent()` returns an intent already `succeeded`.
3. `TopupService::start()` sees that and calls `fulfil()`, which credits the
   wallet through `WalletService` and writes the ledger row.

The `topups` row is written **before** the gateway is called, so an attempt that
dies mid-call still leaves evidence. A charge with no local record is the one
outcome that cannot be reconciled afterwards.

The UI states plainly that payments are not live, and a test asserts it does.
Nobody should be able to add money without noticing it is not real money.

### 7.3 What a real provider changes

Only `createIntent()` returning a pending intent rather than a settled one. Then:

- a callback controller calls `TopupService::fulfil()`; it is already idempotent
- reinstate an events table for callback deduplication (§4.6)
- verify the callback signature before reading any field — an unverified body is
  attacker-controlled input, and without that check the endpoint is a public
  "credit my wallet" API
- never credit from a browser redirect: users close tabs, and a replayable
  success URL is a wallet that mints itself

The asynchronous path already has tests, driven through a fake gateway put into
asynchronous mode.

### 7.4 Reconciliation

`topups:reconcile` runs hourly over `pending` top-ups older than 30 minutes and
asks the gateway what really happened, applying it through the same idempotent
`fulfil()`. Against a synchronous gateway it finds nothing — correct, since a
synchronous gateway cannot strand a top-up. It exists for the provider that
replaces it, where a dropped callback means a customer was charged and never
credited.

### 7.5 Chargebacks

No live provider means no dispute callback, so a chargeback is recorded by an
Admin from the wallet inspector. The recovery is the same code a real callback
will drive: debit the wallet — below zero if the money is already spent — and
freeze it. See §12a.9 for why two invariants relax for this.

## 8. Owner earnings & withdrawal

### 8.1 Maturity

Earnings sit in `pending_minor` from approval until the booking is **completed** plus a dispute
window (`wallet.maturity_hours`, default 24). Then they move to `balance_minor` and become
withdrawable.

New scheduled command `wallet:mature`, every minute, alongside the three existing reservation
sweeps in `routes/console.php` — same `withoutOverlapping()` and `runInBackground()` treatment. It
selects `completed` reservations with `earnings_matured_at IS NULL` and
`end_datetime < now() - maturity_hours`, using the existing `['status', 'end_datetime']` index.

A no-show matures on the same schedule: the owner held the slot and is owed for it.

### 8.2 Withdrawal request

1. Owner sets up a `payout_account`.
2. Owner requests a withdrawal ≤ `balance_minor`, ≥ `wallet.min_withdrawal` (default Rs 1,000).
3. The amount is **debited immediately** (`withdrawal_debit`) and the withdrawal row snapshots the
   bank details. Debiting at request time — not at settlement — is what stops an owner queueing
   three withdrawals against one balance.
4. Admin reviews the queue, executes the bank transfer manually, records the external reference,
   marks it `paid`.
5. Reject or fail → `withdrawal_reversal` credit restores the balance, reason recorded, owner
   notified.

Every transition is audited via the existing `AuditLogger` with new constants
(`WITHDRAWAL_REQUESTED`, `WITHDRAWAL_APPROVED`, `WITHDRAWAL_PAID`, `WITHDRAWAL_REJECTED`).

---

## 9. Admin surface

1. **Withdrawal queue** — pending requests, bank details, mark paid/rejected with reference.
2. **Wallet inspector** — any user's balance, holds, and full ledger with running balance.
3. **Manual adjustment** — credit/debit with a mandatory reason, audited. Needed on day one for
   goodwill and error correction; without it every mistake needs a developer and a SQL client.
4. **Refund to source** — the only route by which money leaves a user wallet outward. Admin-executed
   gateway refund against the original charge, never self-serve.
5. **Reconciliation report** — §11.4's invariant rendered as a page, with the discrepancy figure
   front and centre. If it is ever non-zero, that is a production incident.
6. **Float dashboard** — total user balances, total owner pending, total owner withdrawable, total
   settled out. This is the liability position and someone must be able to see it daily.

---

## 10. Phasing

Each phase is independently mergeable and leaves the app in a working state.

### Phase 1 — Ledger core *(no gateway, no booking integration)*
Migrations for `wallets`, `wallet_transactions`, `wallet_holds`. Enums. `Money` minor-unit helpers.
`PricingCalculator::totalMinor()`. `WalletService` with full invariant enforcement.
`WalletException`. Unit tests including a concurrency test on holds.
**Exit:** money can be moved correctly in tests; nothing user-facing.

### Phase 2 — Top-ups
`topups` migration. `PaymentGateway` contract, `SimulatedGateway`, container binding. Top-up form,
wallet page with balance and statement, balance chip in the topbar. `topups:reconcile` command.
**Exit:** a customer can add money and see their balance. No way to spend it yet.

### Phase 3 — Booking integration
`amount_paid_minor` on reservations. Hold on `request()`, capture on `approve()`, release on
`reject()` / `expire()` / `autoRejectCompeting()`. `BookingValidator` gains an insufficient-funds
check with a "top up Rs X more" message. Booking form shows balance and shortfall.
**Exit:** bookings are paid from wallet. Cancellation still refunds nothing — Phase 4.

> Phase 3 must not ship to production without Phase 4. Between them, a cancelled paid booking
> strands the money. Merge both before any release.

### Phase 4 — Cancellation & refunds
`refund_policy_snapshot`, `refund_amount_minor`, `owner_settlement_minor`. `RefundResolver` with the
matrix. Wire into `cancel()` and `flagNoShow()`. Refund policy shown on the booking form and in
booking history. Tests for the suspension/deactivation/conflict paths of §6.4.
**Exit:** the full booking money loop is correct end to end.

### Phase 5 — Maturity & owner wallet
`earnings_matured_at`. `wallet:mature` command + schedule entry. Owner wallet page: pending vs
withdrawable, ledger, per-booking breakdown.
**Exit:** owners can see what they have earned and when it becomes available.

### Phase 6 — Withdrawals
`withdrawals`, `payout_accounts`. Request flow, admin queue, settlement, rejection/reversal. Audit
constants.
**Exit:** money can leave the platform. **The loop is closed here.**

### Phase 7 — Admin oversight & hardening
Wallet inspector, manual adjustment, refund-to-source, reconciliation report, float dashboard.
Chargeback webhook + freeze. Velocity and new-account caps.
**Exit:** operable without developer intervention.

### Phase 8 — Promo credit rails *(foundation only)*
`promo_credits` table (amount, remaining, expiry, non-withdrawable), spend-promo-before-balance
ordering in `hold()`, expiry sweep. This is the substrate referrals and loyalty sit on; neither
feature is built here, and referrals should not launch before phone verification exists.

---

## 11. Testing

### 11.1 Unit
- `Money` minor-unit round-tripping, including the decimal-string path
- `PricingCalculator::totalMinor()` exactness against the float path across the full
  `allowed_price_unit_minutes` set
- `RefundResolver` — every tier × every actor; assert `user + owner === paid` in all of them
- `WalletService` invariant violations each throw

### 11.2 Feature
- Hold rejected when `available` is insufficient, even though `balance` looks sufficient
- Two users with Rs 600 each competing for one Rs 500 slot: both hold, one captures, one releases
- One user with Rs 600 holding two Rs 500 bookings: second is refused
- Approve captures user → owner pending; losers' holds released with reason `slot_taken`
- Cancel at each tier boundary moves exactly the right amounts
- Owner cancellation always refunds 100%
- Business suspension refunds every affected future booking
- Webhook delivered twice credits once
- Webhook for an unknown PaymentIntent returns 200 and changes nothing
- Withdrawal debits at request, reversal restores exactly

### 11.3 SQLite vs MySQL — already handled
`phpunit.xml` already forces `DB_CONNECTION=mysql` against `bookyourspot_test`, with a comment
explaining that NFR-1's row locking cannot be tested on SQLite. Nothing to change: the wallet
concurrency tests inherit that decision for free.

One consequence worth recording. `RefreshDatabase` wraps every test in its own transaction, so
`DB::transactionLevel()` is always ≥ 1 inside the suite and `WalletService::assertInTransaction()`
can never fire there. The guard is correct and valuable in production; it is simply not reachable
by a test. `WalletServiceTest` skips that one case with an explicit reason rather than deleting it,
so the gap stays visible in the test output.

### 11.4 The capstone: conservation of money

One test that runs a randomised sequence of top-ups, bookings, approvals, rejections,
cancellations at every tier, no-shows, maturities and withdrawals, then asserts:

```
Σ(all wallet balance_minor)
  + Σ(all wallet pending_minor)
  + Σ(all active holds' amount)      // still inside balance, not double counted
  + Σ(paid withdrawals)
  ===
Σ(succeeded topups)
```

and independently, that every wallet's `balance_minor` equals the sum of its `balance`-bucket ledger
rows, and `pending_minor` the sum of its `pending`-bucket rows.

If this passes under a randomised sequence, the ledger is sound. If it fails, nothing else matters.

---

## 12a. Decisions taken during implementation

Recorded here because each one departs from, or adds to, the plan above.

1. **Balance columns are signed** (§4.1) — so a Phase 7 chargeback needs no `ALTER` on a live money table.

2. **`assertInTransaction()` is production-only.** `RefreshDatabase` holds an open transaction, so the guard cannot fire in the suite. `WalletServiceTest` skips that case loudly rather than pretending it does not exist.

3. **A gateway interface, not a vendor dependency.** `App\Contracts\PaymentGateway`, with `SimulatedGateway` as the only implementation today. Nothing outside an implementation class knows which provider is in use, so replacing it is a new class plus one container binding. Deliberately no callback method on the interface — that plumbing is provider-specific and belongs in the implementation's own controller.

4. **`bootstrap/app.php` narrows `shouldRenderJsonWhen()` to `api/*`**, which replaces Laravel's default `expectsJson()` check. Any JSON endpoint outside `api/*` must answer its own validation failures — `StartTopupRequest` overrides `failedValidation`/`failedAuthorization` for exactly this reason. **This trap applies to every future JSON route.**

5. **Money lives in `SettlementService`, state stays in `ReservationService`.** The latter already had a hard rule that nothing else writes `reservations.status`; adding six money paths to it would have doubled its size. Settlement methods are always called from inside the caller's transaction.

6. **Customer factories are funded by default** (`UserFactory::DEFAULT_WALLET_MINOR`, Rs 50,000), credited through the ledger so reconciliation holds. From Phase 3 a booking cannot be made without money, so this reflects reality rather than papering over it. `broke()` and `withWalletBalance()` opt out.

   Note for anyone extending it: Laravel binds an `afterCreating` closure to the factory instance that registered it and copies the callback array into every derived instance. A flag on `$this` read inside that closure silently reads the *original* factory's value. `broke()` therefore undoes the default rather than suppressing it.

7. **Withdrawals debit at REQUEST time, not at settlement.** Otherwise an Owner queues three withdrawals against one balance and an Admin discovers it having already sent the first. Rejecting or failing reverses the debit; paying leaves it debited and writes no second ledger row.

8. **Reconciliation must account for admin adjustments.** `expected = topups − payouts + net_adjustments`, where net adjustments sum the signed `admin_adjustment` and `chargeback_reversal` rows. Without that term a single goodwill credit reads as a discrepancy and the dashboard cries wolf.

9. **Two invariants had to relax for chargebacks.** `held ≤ balance` is only enforced while the balance is non-negative — after a chargeback the holds were placed against money the bank has taken back, so the relationship describes a state that no longer exists. And `debit()` accepts `allow_frozen`, because a freeze must stop money going *out*, not stop the platform recovering money already clawed back; without it the second dispute on an account the first one froze would be unrecoverable.

10. **An account with a wallet ledger cannot self-delete.** A ledger row is a financial record and outlives the login it is attached to. The database enforces it (`wallet_transactions` restricts deleting its wallet); `ProfileController::destroy` turns that 500 into an explanation. An account that never transacted still deletes cleanly.

---

## 12. Open items

1. **Which real payment provider** (§2.1). Nothing is blocked on it — the whole economy runs simulated — but no real money can be taken until it is answered.
2. **Dispute window length** — 24h after booking end is proposed. Owners will want it shorter, and
   it is a config value, so this can be tuned after launch.
3. **Minimum withdrawal** — Rs 1,000 proposed, to keep manual settlement effort proportionate.
4. **Unspent balance policy** — users cannot withdraw, so a dormant account accumulates trapped
   funds. No expiry is proposed for now, but a stated policy will be needed before scale.
