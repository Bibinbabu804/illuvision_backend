# 23 — Creative Do-Not-Touch Boundaries

**Generated:** 2026-09-26
**For:** the engineer implementing Creative Marketplace.
**Classification:** `[ARCHITECTURE DOCUMENT]` for every item traceable to root `AGENTS.md` or `docs/architecture/ARCHITECTURE_RULES.md`. `[CURRENT FACT]` where the item exists in the repository and its behaviour is verified. `[RECOMMENDATION]` for the guard rails proposed around it.

**Rule of this document:** every entry below is justified by something verified in this audit. Nothing here is a generic "be careful" warning. If you find an item on this list that you believe must change, that is a valid architectural signal — stop and escalate it (§7). It is not permission to change it.

---

## 1. The three questions to ask before touching anything outside `app/**Creative**`

1. Does an existing **external contract** depend on it? (URI, method, request field, validation, response shape, status code, auth, authorization, pagination)
2. Does a **financial fact** depend on it? (pricing, split, rounding, currency, payment state, settlement, refund)
3. Does a **concurrency or inventory invariant** depend on it? (transaction boundary, `lockForUpdate`, availability recomputation, unique constraint)

If the answer to any of the three is yes, the answer is **no** — unless the change is itself the explicitly authorised subject of the task.

---

## 2. Advertising booking behaviour

`[CURRENT FACT]` The advertising booking is the commercial core of Project 01: 385 routes, ~40 booking routes, and the pricing, availability, payment, revenue-distribution and settlement subsystems all key off it.

| Do not touch | Why | Verified evidence |
|---|---|---|
| `App\Services\ManualBookingService.php` (647 LOC) | Booking creation, transaction boundaries, pricing invocation | the largest booking writer; contains 2 `DB::transaction` blocks |
| `App\Services\BookingWorkflowService.php`, `App\Services\BookingPriceCalculator.php` | Booking state transitions and price derivation | booking lifecycle entry points |
| `App\Services\BookingCapacityService.php` (602 LOC) | Availability and capacity computation | the source of every "only N units available" message |
| `App\Services\ConflictDetectionService.php`, `App\Services\InventoryService.php` | Conflict detection and the `lockForUpdate` inventory path | `InventoryService` is one of only 2 services that lock rows |
| `app/Domain/Booking/**` | Extracted booking domain | already extracted; do not re-litigate during a Creative task |
| `bookings`, `booking_screens`, `booking_media`, `booking_activities`, `booking_pricing_breakdowns` (tables) | The campaign aggregate and its immutable financial snapshot | artifact 10, highest-risk rank 1 |

**Creative's position on bookings:** none. `creative_projects` has **no** FK to `bookings`. If a future requirement is "attach a creative deliverable to a campaign", that is a new cross-domain feature requiring its own decision record, its own contract, and its own tests — not something to introduce by adding a nullable column.

---

## 3. Campaign pricing

`[CURRENT FACT]` `[ARCHITECTURE DOCUMENT]` Rule 9 and `AGENTS.md` §10: the pricing domain is autonomous, deterministic, and its behaviour is pinned by the golden suites.

| Do not touch | Why |
|---|---|
| `app/Domain/Pricing/**` including `CampaignPricingEngine` | Canonical, deterministic pricing. The 7 pinned `Domain -> Services` imports are recorded debt with a scheduled order — not a licence to add more |
| `App\Services/PricingService.php`, `PricingEngine.php`, `PricingRuleEngine.php`, `BookingPriceCalculator.php` | Legacy + active engines, both live |
| `App\Services/Pricing/*` (`DailyPricingCalculator`, `ScreenPricingResolver`, `ReferralPricingCalculator`, `FirstBookingDiscountPricingCalculator`) | Active calculator set |
| `pricing_plans`, `pricing_plan_durations`, `pricing_discount_rules`, `pricing_screen_rules`, `pricing_duration_rules`, `pricing_peak_rules`, `pricing_weekend_rules` | Advertising media-time pricing |

**Creative's position:** Creative amounts are **not** media-time prices. Creative quote derivation is a new, small, deterministic Creative domain calculation, and the accepted amount is **frozen** at acceptance. If someone reaches for `PricingService` to price a creative project, stop: that is the price-manipulation defect path.

**Mandatory regression suites if you are anywhere near this area** (`AGENTS.md` §10):

```text
tests/Feature/Golden/GoldenPricingRegressionTest.php
tests/Feature/Golden/GoldenFinancialRegressionTest.php
tests/Feature/Golden/GoldenPaymentRegressionTest.php
tests/Feature/Golden/GoldenFinancialBoundaryTest.php
tests/Feature/Golden/GoldenPaymentBoundaryTest.php
tests/Feature/FinancialRegressionAndHardeningTest.php
```

---

## 4. Advertising availability

`[CURRENT FACT]` Availability is inventory-bearing and concurrency-sensitive. `AGENTS.md` §11 forbids moving `lockForUpdate` out of a transaction, removing a re-check inside the transaction, or merging availability with reservation.

Do not touch: `app/Domain/Availability/**`, `App\Services/BookingCapacityService.php`, `App\Services/InventoryService.php`, the `bookings` conflict-detection path, the scheduled command `bookings:expire-pending` (`app/Console/Commands/ExpirePendingBookings.php`, the other `lockForUpdate` site).

**Creative's position:** none. Creative capacity (how many engagements a creator can take) is a different question from advertising inventory and has no current equivalent. Introducing a "creative availability" concept that locks advertising rows would be a serious error.

---

## 5. Existing payment verification

`[CURRENT FACT]` Verification is client-initiated: `POST /api/payments/razorpay/verify`. There is **no** payment webhook route. `App\Services\PaymentService::verifyAndConfirmPayment` couples gateway verification to a **booking status transition**, inside `DB::transaction`.

| Do not touch | Why |
|---|---|
| `App\Services\PaymentService.php` | The verified payment path, including its booking coupling |
| `App\Domain\Payment/Contracts/PaymentGatewayInterface.php` | The shared contract. Reuse it; never modify or replace it |
| `App\Infrastructure\Payments/RazorpayPaymentGateway.php` | The only place the SDK exists. Vendor exception handling and data structures live here (Rule 3) |
| `payments` table | `booking_id` is `NOT NULL` with `CASCADE`; relaxing it would alter an existing financial table and every booking payment query |
| `AdminPaymentController` direct-payment approval/rejection endpoints | Manual financial approval flow with its own state machine |
| `payment_logs`, `refunds` | Append-only financial log and refund ledger |

**Creative's position:** reuse the **contract**; own the **record**. `creative_fundings` / `creative_funding_logs` / `creative_refunds` are new tables. A Creative funding confirmation follows the same transaction discipline and the same "no network call inside a lock" rule.

**Note the asymmetry honestly:** Creative funding confirmed only by a client-initiated call is weaker than a webhook-confirmed payment, because the client can walk away from the callback. Whether Creative adopts a webhook — and therefore needs signature validation, replay protection and an idempotent handler — is `[OPEN DECISION]`, and it must be decided as a design decision, not improvised.

---

## 6. Settlement and provider commercial logic

`[CURRENT FACT]` `App\Services/SettlementService.php` (352 LOC), `AdminSettlementController`, `screen_provider_settlements` (period + reference + generated/approved/paid + three actor FKs, **no currency column**).

| Do not touch | Why |
|---|---|
| `App\Services/SettlementService.php` | Provider settlement period logic |
| `App\Services/RevenueDistributionService.php`, `RevenueSplitResolverService.php` | Revenue split resolution for bookings |
| `App\Services/CommissionService.php` | Partner commission lifecycle. Its `lockForUpdate` was relocated to `App\Infrastructure/Financial/Persistence/EloquentPartnerCommissionRepository.php:31` in Phase 0.10; `CommissionService.php:19` now only documents the legacy call in a docblock. |
| `screen_providers.illuvision_percentage` / `screen_provider_percentage` (defaults 10.00 / 90.00) | Provider commercial terms. **A Creative commission must not inherit these numbers.** |
| `screen_provider_settlements`, `partner_commissions`, `referral_codes`, `referral_usages` | Partner/provider commercial records |

**Creative's position:** `creator_earnings`, `creator_payouts`, `creator_payout_items` are new tables patterned on these structures. Reuse the **lifecycle and the locking discipline**; do not reuse the columns, and do not reuse the settlement *service* until someone has demonstrated that it is genuinely provider-agnostic. The commission basis, approval authority, payout cadence and payout provider are `[OPEN DECISION]` and **no percentage may be invented**.

---

## 7. Provider and partner commercial relationships

`[CURRENT FACT]` `App\Services/PartnerService.php`, `ScreenProviderService.php`, `ScreenProviderEnquiry` flows, `PartnerApplicationController`, `PartnerApprovalController`, `partner_commissions`, `reward_accounts` / `reward_transactions` / `reward_redemptions`.

**Why:** these are the commercial counterparties of the advertising marketplace. A creator is a different counterparty: an individual supplier of creative work, not an affiliate organisation (`partners`) and not a media owner (`screen_providers`). Overloading either table corrupts partner commission, referral attribution and provider settlement simultaneously.

**Also:** `reward_transactions` is an **integer points** ledger. Copying its shape into a money ledger introduces a precision defect into a financial record. Creative money is `decimal(12,2)` with an explicit currency.

---

## 8. Existing production API contracts

`[ARCHITECTURE DOCUMENT]` Rule 5 and `AGENTS.md` §13. Creative may **add** routes. Creative may not change an existing one.

Frozen without exception: every existing URI and HTTP method; every request field name, type and validation semantic; every response field, envelope, status code and error format; every authentication and authorization semantic; every pagination semantic. `tests/Feature/Golden/GoldenApiContractRegressionTest.php` must stay green. Route parity is currently **385** and may only increase by newly added Creative routes.

Additionally: **do not restructure `routes/api.php` for Creative.** The file already carries a documented, deliberate comment block explaining permission decisions on the finance routes (including a note that RBAC was explicitly out of scope for that work). Creative routes are added as their own group; the existing file is not reorganised.

**Do not migrate existing routes to policies** as part of a Creative task. `app/Policies/` does not exist today and the application has 0 policies; introducing Creative policies must not alter authorization for the 385 existing routes.

---

## 9. Authentication core

`[CURRENT FACT]` `config/auth.php` (single `web` guard), `personal_access_tokens` (Sanctum; `config/sanctum.php` expiration is `null`, so tokens never expire), `password_reset_tokens`, `password_setup_tokens`, `app/Http/Controllers/Auth/**`, `app/Infrastructure/Auth` (Google identity adapter).

**Why:** `AGENTS.md` and artifact 26 — a second user system or a second authentication path is explicitly forbidden. A creator authenticates exactly as any other Marketplace user, and `creative_creators.user_id` references `users.id`.

**Recorded, not fixed:** Sanctum tokens never expiring is a pre-existing platform property. It is a security finding for the register, not a Creative task.

**Escalate if:** the Creative design appears to require a separate creator login, a separate token type, or a second identity provider.

---

## 10. Shared infrastructure

`[CURRENT FACT]` These already exist and are reused, never duplicated:

```text
config/filesystems.php (local / public / s3)      App/Helpers/MediaHelper.php
ImageProcessingContractInterface                   App/Services/NotificationService.php + app/Mail/*
app/Support/Events/Notifications (33 events)      notification_logs
app/Support/Authorization/RolePermissionChecker   roles + role_permissions
currency_rates + ExchangeRateService               activity_logs
settings                                          countries / states / cities
cache / cache_locks / jobs / failed_jobs / sessions
```

**Exception that is permitted:** `app/Support/Jobs/Creative/` may be created, because the directory `app/Support/Jobs` does not exist and Creative needs at least one real job. That is a documented decision (artifact 24), not a general licence to add cross-cutting infrastructure.

**Not permitted:** a Creative notification log, a Creative audit table (unless `activity_logs` is proven unable to carry a Creative subject), a Creative settings table, a Creative FX table, a Creative cache, a Creative queue.

---

## 11. Existing database tables

`[ARCHITECTURE DOCUMENT]` `AGENTS.md` §14: application refactoring is not database redesign.

**Never** edit an already-applied migration. **Never** add a destructive migration. **Never** rename a table or column, remove an index or foreign key, change a column type on a financial or identity column, or add a cross-database foreign key. Reference baseline: **112 migrations, 73 tables, 96 foreign-key definitions (94 ->constrained() + 2 legacy ->foreign())** — any deviation is a schema change and must be reported as one.

Tables Creative must not touch at all (full list with reasons in artifact 10):

```text
screens, screen_categories, screen_types, asset_coverage_cities,
mobile_asset_coverage_states, mobile_asset_coverage_cities,
bookings, booking_screens, booking_media, booking_activities,
booking_pricing_breakdowns, advertisement_categories,
pricing_plans, pricing_plan_durations, pricing_discount_rules,
pricing_screen_rules, pricing_duration_rules, pricing_peak_rules,
pricing_weekend_rules, payments, payment_logs, refunds,
partners, referral_codes, referral_usages, partner_commissions,
reward_accounts, reward_transactions, reward_redemptions,
testimonials, galleries, success_stories, blogs, blog_descriptions,
categories, blog_tags, blog_tag_relations, faqs, achievements,
announcements, enquiries, contact_enquiries, products,
product_enquiries, job_categories, job_posts, job_applications,
qr_codes, qr_code_scans
```

Tables Creative may **add rows** to, and nothing else: `roles` (one `creator` row), `role_permissions` (`creative.*` grants), `settings` (a `creative.*` group, non-financial values only), `notification_logs` (a `creator` recipient type). `users` and `currency_rates` are read/referenced only.

**Two specific traps:**

- **`testimonials`** has a `rating` column and no author binding, no soft delete, and public marketing edit paths. It is not a review table. A commercial Creative review written there is a data-protection and integrity defect.
- **`booking_media`** has the file columns a developer will want (`file_path`, `file_type`, `file_size`, `mime_type`, `original_name`, `sort_order`) and **no owner column, no visibility column and no access check.** Copying the columns without the access model reproduces a file-access gap. `creative_files` must carry owner + visibility, and reading one must go through an authorization check.

---

## 12. The CMS application (Project 03)

`[ARCHITECTURE DOCUMENT]` `AGENTS.md` §9. The CMS is a **separate Laravel application with a separate MySQL database and a separate bounded context.** There is no shared database.

Never: access CMS tables from this application; add a cross-database join, foreign key or migration against the CMS database; introduce a direct synchronous call from a Marketplace transaction into the CMS; create CMS controllers, models, migrations, tables or routes in this repository. Communication is via versioned APIs, events, webhooks, authentication, idempotency and external IDs.

---

## 13. The Creative directories do not exist yet

`[CURRENT FACT]` `app/Domain/Creative`, `app/Services/Creative`, `app/Repositories/Creative`, `app/Http/Controllers/Creative`, `app/Http/Requests/Creative`, `app/Http/Resources/Creative` are **absent**, and `find app -type d -empty` returns nothing.

**This cuts both ways:** an unrelated task must not create them as a side effect, and no empty placeholder directories should be committed. Creating the module root is a Creative task, and it is the first line of the Creative implementation sequence (artifact 24, Phase C1).

Several Phase 0.18/0.20 documents assert these directories were "CREATED (empty)". **That claim is false** and has already been corrected in root `AGENTS.md` §8. Do not treat those documents as evidence that a module root exists.

---

## 14. Existing debt that is deliberately not yours to fix

`[ARCHITECTURE DOCUMENT]` `AGENTS.md` §5, §19. The following is controlled, documented debt with a scheduled migration order. Touching it during a Creative task is out-of-order work that creates unreviewed risk in exactly the areas the phase plan protects.

```text
App\Domain\Pricing\CampaignPricingEngine   7 pinned imports of App\Services\*  (enforced)
6 Domain files                              9 imports of App\Models            (tracked, unenforced)
App\Support\Configs\* -> SettingsService   Support -> Services imports        (tracked)
11 service-locator call sites               app(...) / resolve(...)            (tracked)
33 repository-extraction candidates         recorded backlog, not a to-do list
2 failing tests + 1 warning                 pre-existing, must stay pre-existing
./vendor/bin/pint --test                    fails repository-wide, pre-existing
```

Also out of bounds: any "while I was here" reformat, rename, reorder or modernisation of a file the task does not already require changing. A diff larger than the task requires is a finding to report, not a quality signal.

---

## 15. What you must never do to "make it work"

| Temptation | Why it is wrong | Do instead |
|---|---|---|
| Make `payments.booking_id` nullable so Creative can use it | Alters an existing financial table and every booking payment query | New `creative_fundings` table |
| Reuse `booking_screens` for Creative project lines | FKs are `booking_id` and `screen_id` | New table, copying the *snapshot discipline* |
| Reuse `testimonials.rating` for Creative reviews | Public marketing surface, no author, no soft delete, no audit | New `creative_reviews` table |
| Put Creative files on the `public` disk for convenience | Public URL, no authorization, no revocation | `creative_files` with visibility + `CreativeFilePolicy` + a grantor service |
| Put a commission percentage in `settings` | Editable retroactively, so historical earnings stop being reproducible | Versioned Creative commission table |
| Add `is_creator` to `users` | Identity model is `[OPEN DECISION]` OD-DB-08 | Wait for the decision; derive from a `creators` row meanwhile |
| Hard-code a commission %, revision limit or dispute window | Invented business rules | `[OPEN DECISION]`; ask |
| Use `CampaignPricingEngine` / `PricingService` to price a creative project | Media-time pricing is not creative pricing | New small deterministic Creative domain calculation, frozen at acceptance |
| Accept a client-supplied amount | Price manipulation, the top Creative security risk | Server-derived quote; the request may carry display preferences only |
| Add a `creative_policies` or a second permission resolver | Duplicates `RolePermissionChecker`, breaks Rule 12 | Add role/permission rows; add resource-level policies for Creative only |
| Weaken a guard so a test passes | Unauthorized access | Fix the test or report the finding |
| Reorganise `routes/api.php` while adding Creative routes | Touches a file with documented permission decisions | Add a new, separate group |

---

## 16. Pre-existing outcomes you must report, not absorb

`[ARCHITECTURE DOCUMENT]` `AGENTS.md` §17. If your change alters any of these, that is a **reportable regression**, not a silent improvement:

```text
Full suite          673 passed, 2 failed, 1 warning, 4 skipped, 5564 assertions
Failure 1           tests/Feature/BookingConcurrencyTest.php
                    expects "no longer available"; actual "... has only N units available, but M requested."
Failure 2           tests/Feature/CampaignBookingPricingIntegrityTest.php  (same wording drift)
Warning             MediaHelperTest tautological assertion
ArchitectureRuleTest 14 passed (with PHPUnit deprecations)
pint --test         fails repository-wide (pre-existing)
```

---

## 17. If you believe you must touch something on this list

That is a legitimate architectural finding, not a defect in your work. The correct response is:

1. **Stop.** Do not make the change.
2. Record it as a **deferred finding** with the file reference, the reason you need it, and what you believe is wrong (`AGENTS.md` §18, §24).
3. Escalate it as an architecture decision. State the required decision, the options, and the contract impact. If it would change an external contract, a financial calculation, an authorization behaviour or the schema, name that explicitly — `AGENTS.md` §13 and §3 require a `STOP -> report the blocker and the required delta -> wait for an explicit decision`.
4. Continue with the part of your task that does not depend on the decision, if any.

**Security exception:** if you find an actively exploitable security defect, report it prominently and get authorisation before a broad fix. Do not silently widen scope, and do not narrow a guard to make a test pass.
