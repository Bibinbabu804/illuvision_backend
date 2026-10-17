# 24 — Creative Marketplace Implementation Sequence

**Generated:** 2026-09-26
**Classification:** `[RECOMMENDATION]` for the phase order and completion criteria. `[CURRENT FACT]` for the dependency facts the order is derived from. `[OPEN DECISION]` for anything gated on a product or architecture decision.
**Status:** PLAN ONLY. No phase below has been started. No file, migration, route or class exists.

---

## 1. How this sequence was derived

`[RECOMMENDATION]` The brief suggested a phase list (C1 Foundation, C2 Creator, C3 Services, C4 Brief, C5 Proposal, C6 Project, C7 Revision, C8 Delivery, C9 Review, C10 Earnings, C11 Notifications, C12 Reporting, C13 Hardening) and explicitly said not to use it blindly. The order below is derived from three kinds of hard dependency observed in the audit:

| Dependency type | Instances found in this audit |
|---|---|
| **Data dependency** | `creators` needs `users`; `creator_services` needs `creators`; `creative_proposals` needs `creative_briefs` + `creators`; `creative_projects` needs an accepted `creative_proposals` row; `creative_fundings` needs `creative_projects`; `creative_deliveries` need `creative_projects`; `creative_revisions` reference `creative_deliveries`; `creator_earnings` need `creative_projects` |
| **Rule dependency** | Server-derived quote must exist before a proposal can be accepted; accepted terms must be frozen before funding can be reconciled; funding and acceptance must exist before an earning has a financial basis |
| **Capability dependency** | Private file access must exist before a client uploads a brief attachment; authorization for a role must exist before that role can be given a route; notification events need a stable event vocabulary |
| **Order found in the DB** | artifact 09 `implementation_order_for_migrations` — Creative migrations must be additive, independently reversible, and a deferred FK (`creative_proposals.project_id`) is added in a later step than the table it points at |

Two phase orderings were **rejected** on evidence:

- **Earnings before Delivery/Review was rejected.** An earning needs a financial trigger. The trigger is either delivery acceptance or review submission, both of which are `[OPEN DECISION]` (OD-DB-05). Building earnings before the trigger exists means inventing the trigger.
- **Notifications before the event vocabulary was rejected.** The 33 existing events are a stable, dispatch-consumed vocabulary; adding 13 Creative events before the states they describe are final means rewriting listeners later.

---

## 2. Blocking decisions — resolve before C3, not before C1

`[OPEN DECISION]` Ten decisions are carried forward (full statements in artifact 09 and artifact 22 §H). They are **not** all blocking at the start:

| Decision | Blocks | Why it cannot be deferred further |
|---|---|---|
| **OD-DB-08 / OD-AUTH-01** dual role (advertiser + creator) | **C1** | Determines the identity model. `RolePermissionChecker::permissionsForRole()` resolves a SINGLE slug from `users.role`, so one user holds exactly one role: setting `role='creator'` strips every other permission that user had. It changes the `creators` table design, so it must precede C2. **This is the only true blocker, and it blocks the `roles`/`role_permissions` row inserts, not just the schema.** |
| OD-DB-01 public identifier | C3 | Determines `public_id` column type on every table and every route parameter. Cheapest to decide before the first migration that needs it. |
| OD-DB-02 brief targeting | C4 | Determines `creative_briefs.creator_service_id` nullability and whether a match table exists. |
| OD-DB-03 one proposal or many | C5 | Determines the unique constraints that make acceptance race-free. |
| OD-DB-04 escrow / milestones | C6 | Determines whether funding needs tranches and a held balance. |
| OD-DB-05 earning accrual trigger + dispute window | C9/C10 | Determines the earning status vocabulary and whether a settlement period is needed. |
| OD-COMMISSION basis / approval / payout cadence / payout provider | C10 | No percentage may be invented. |
| OD-DB-06 tax / third-party costs | C6 | Determines the `AGREED TERMS` invariant formula. |
| OD-DB-07 multi-currency | C6 | Determines every `*_currency` column. Note the existing `screen_provider_settlements` has **no** currency column, so there is no precedent to copy. |
| OD-FILE privacy / retention / consent | C2 | Determines the visibility semantics of the first `creative_files` row. |
| OD-AUDIT can `activity_logs` carry a Creative subject | C2 | Determines whether a Creative audit table is needed. |
| OD-JOBS `app/Support/Jobs/Creative/` | C10 | Determines whether payout processing is a job or a queued Mailable. |

**Handling rule:** a phase that is blocked by a decision is **partially** delivered — everything not dependent on the decision proceeds, and the dependent part is recorded as deferred with the exact missing information. No default is chosen silently.

---

## Phase C0 — Decisions and instrumentation (no code)

| Field | Content |
|---|---|
| **Prerequisites** | This audit accepted by a senior engineer / project architect |
| **Database work** | None |
| **Backend work** | None |
| **Reusable components** | n/a |
| **New components** | A written decision record per open item (OD-DB-01 … OD-FILE) |
| **API endpoints** | None |
| **Tests** | None |
| **Risks** | Proceeding without the OD-DB-08 decision means the identity model may have to be migrated after users exist — the most expensive class of rework in this plan |
| **Completion criteria** | OD-DB-08 answered in writing. Every other open decision has a named owner and a date. Artifact 27's open-decision list is either closed or explicitly accepted as deferred |

---

## Phase C1 — Creative Foundation (module root, boundary, guards)

| Field | Content |
|---|---|
| **Prerequisites** | C0 (specifically OD-DB-08) |
| **Database work** | **None yet.** No migration in this phase |
| **Backend work** | Create the module root: `app/Domain/Creative/{Creator,Service,Brief,Proposal,Project,Revision,Delivery,Review,Earnings,Shared}` (artifact 20 §3) with the first class only where it carries real behaviour. Add `tests/Unit/Architecture/CreativeBoundaryTest.php` asserting: zero `App\Http`, `App\Services`, `App\Models` imports under `app/Domain/Creative`; zero `Http::` / `Mail::` / `Storage::` / vendor SDK references under `app/Domain/Creative` and `app/Services/Creative`; zero service-locator calls in Creative business logic. **Extend `ArchitectureRuleTest` in the same commit** so the Creative rules are enforced, not merely documented |
| **Reusable components** | `ArchitectureRuleTest`'s token-stream approach (copy the enforcement pattern, not the advertising rules) |
| **New components** | The Creative boundary test; the decision records from C0 |
| **API endpoints** | None |
| **Tests** | The boundary test itself must fail on a deliberately bad import during development, proving it can fail |
| **Risks** | Creating empty directories and committing them (forbidden — `AGENTS.md` §8 and the false Phase 0.20 claim). Creating a `Common/` / `Utils/` umbrella directory (forbidden — `AGENTS.md` §7) |
| **Completion criteria** | Module root exists with a stated purpose per directory; Creative boundary rules enforced by a **passing** test; full suite still 673/2/1/4 with no new failure; `route:list` still 385 |

---

## Phase C2 — Creator identity, storage contract and audit trail

| Field | Content |
|---|---|
| **Prerequisites** | C1, OD-DB-08, OD-FILE, OD-AUDIT |
| **Database work** | New tables, additive and independently reversible: `creators` (FK `users.id`), `creative_files`. Migrations 1–2 of artifact 09's order. Extend `roles` with **one row** (`creator`); extend `role_permissions` with `creative.*` grants for it; add a `creative.*` settings group (non-financial values only, and NO key for a rule that is still OPEN_DECISION - a key name presupposes its rule, so `creative.revision_limit` / `creative.dispute_window_days` / `creative.payout_minimum` may not be created until those rules are approved) |
| **Backend work** | `App\Domain\Creative\Creator\Creator` aggregate with `CreatorStatus` and an approval transition guard. `RegisterCreatorHandler`. The **file access contract** and `CreativeFileGrantor` — authorize-then-stream, because no signed-URL mechanism exists in this repository and this is new work, not reuse. `CreativeFilePolicy`. Confirm `activity_logs` can express a Creative subject; if not, a Creative-scoped audit table is added here and nowhere else |
| **Reusable components** | `users` (reference only), `roles` + `role_permissions` + `RolePermissionChecker` (rows only), `config/filesystems.php` disks, `MediaHelper` / `ImageProcessingContractInterface`, `activity_logs`, `settings`, `PasswordSetupTokenTable` mechanism for invitation (`ScreenProviderService::inviteToPortal` is the precedent) |
| **New components** | `creators`, `creative_files`, the file grantor, the file policy, `CreatorStatus` |
| **API endpoints** | `POST /api/creative/creators` (register), `GET /api/creative/creators/me`, `GET/PATCH /api/creative/creators/me` (profile), `POST /api/creative/files` (upload, `multipart`), `GET /api/creative/files/{publicId}` (**authorization required — not a public URL**) |
| **Tests** | Registration and approval state transitions; a user who is advertiser+creator resolves correctly under both role readings; file upload rejects an unauthorised mime type and an oversized file (note: `FileController` has no FormRequest — Creative must not copy that); **IDOR test**: creator A cannot read creator B's private file by guessing the identifier; audit entry written for every approval and every private file access |
| **Risks** | Private file placed on the `public` disk "to get it working" (the highest-likelihood defect in this phase). Adding a column to `users`. Assuming `activity_logs` works without verifying it |
| **Completion criteria** | A creator can register, be approved, upload a file, and read back **only** their own private file; every access is audited; no existing route or table changed; no `public` URL is issued for a private Creative file |

---

## Phase C3 — Creative service catalogue and taxonomy

| Field | Content |
|---|---|
| **Prerequisites** | C2, OD-DB-01 (public identifier strategy — needed from the first `public_id` column) |
| **Database work** | `creative_service_categories`, `creator_services`, `creative_service_category_map` (junction, unique composite — copy the `blog_tag_relations` pattern), `creative_portfolio_items`. Migrations 1 and 3–4 of artifact 09's order |
| **Backend work** | `CreatorService` aggregate with a rate (`RateCard` value object). `PublishCreatorServiceHandler` (publish/unpublish). Portfolio item aggregate bound to a `CreativeFile` |
| **Reusable components** | `creative_files` from C2; the unique-composite pivot idiom from `blog_tag_relations`; the slug/public-identifier pattern from `qr_codes` |
| **New components** | The three tables, the aggregate, the publish handler, the rate value object |
| **API endpoints** | `GET /api/creative/services` (public catalogue, filterable), `GET /api/creative/services/{publicId}`, `POST/PATCH /api/creative/creators/me/services`, `POST /api/creative/creators/me/services/{publicId}/publish`, portfolio CRUD under `/api/creative/creators/me/portfolio` |
| **Tests** | Publish/unpublish transitions; only an approved creator can publish; catalogue listing returns only published services; the junction table's unique composite rejects a duplicate category; rate is stored as `decimal(12,2)` and never as a float |
| **Risks** | Reusing `screen_categories` or `products` for service taxonomy (forbidden — artifact 10). Putting the rate in `settings`. Introducing a search engine |
| **Completion criteria** | An approved creator can publish a priced service with a category and a portfolio, and a public client can browse it; no advertising taxonomy table touched |

---

## Phase C4 — Brief

| Field | Content |
|---|---|
| **Prerequisites** | C3, OD-DB-02 (brief targeting) |
| **Database work** | `creative_briefs`, `creative_brief_attachments` (FK to `creative_files`). Migration 5 of artifact 09's order |
| **Backend work** | `Brief` aggregate with `BriefStatus` and a submission transition. `CreateBriefHandler`. Attachment rules reuse the C2 file grantor — a brief attachment inherits the brief's visibility |
| **Reusable components** | `creative_files`, `creative_service_categories`, `creator_services` |
| **New components** | Two tables, the aggregate, the handler |
| **API endpoints** | `POST /api/creative/briefs`, `GET /api/creative/briefs/{publicId}`, `PATCH /api/creative/briefs/{publicId}`, `POST /api/creative/briefs/{publicId}/submit`, `POST /api/creative/briefs/{publicId}/close`, `GET /api/creative/briefs/mine`, `POST /api/creative/briefs/{publicId}/attachments` |
| **Tests** | Only the author can read or edit a draft brief; a closed brief is immutable; attachment access follows the brief; a brief cannot reference a service its target creator does not offer (if OD-DB-02 says targeted) |
| **Risks** | Caching a brief's attachment list and serving it without re-authorizing each file. Letting a brief be edited after submission in a way that changes scope without a new version |
| **Completion criteria** | A client can author a brief with attachments, submit it, and no other client can read it; scope changes after submission require an explicit, audited action |

---

## Phase C5 — Proposal and the server-derived quote

| Field | Content |
|---|---|
| **Prerequisites** | C4, OD-DB-03 (one proposal or many) |
| **Database work** | `creative_proposals` (FK briefs, creators), with a **deferred** FK to `creative_projects` added in C6 step 7. Migration 6 of artifact 09's order |
| **Backend work** | `Proposal` aggregate, `ProposalStatus`, and — the important part — a **pure, deterministic** `CreativeQuoteCalculator` domain service producing a `ProposalQuote`. `SubmitProposalHandler`, `WithdrawProposalHandler`. The quote is server-derived: the request may carry scope description and duration, never an amount |
| **Reusable components** | `booking_pricing_breakdowns` and `booking_screens` as the **snapshot discipline** templates (store amount *and* the percentages that produced it). `ActivityLog` for the audit entry. **No** reuse of `PricingService` / `CampaignPricingEngine` — media-time pricing is not creative pricing |
| **New components** | The table, the aggregate, the pure quote calculator, the handlers |
| **API endpoints** | `POST /api/creative/briefs/{briefId}/proposals`, `GET /api/creative/proposals/{publicId}`, `GET /api/creative/proposals/mine`, `POST /api/creative/proposals/{publicId}/withdraw` |
| **Tests** | **The quote is identical for identical inputs** (determinism, mirroring Rule 9's testing discipline for the Creative domain); the quote is stored, not recomputed on read; a client-supplied amount is ignored; a creator cannot submit two proposals where OD-DB-03 says one; withdrawal is permitted only in the draft/submitted states; a withdrawn proposal cannot be accepted |
| **Risks** | Reading an amount from the request — the top Creative security risk. Recalculating the quote on read so the displayed number can drift from the accepted number. Duplicating the pricing engine instead of writing a small Creative calculation |
| **Completion criteria** | A creator can submit a proposal whose amount is computed server-side, stored, and reproducible from the stored inputs; a client-supplied amount provably has no effect |

---

## Phase C6 — Project creation, frozen terms and funding

| Field | Content |
|---|---|
| **Prerequisites** | C5, OD-DB-03, OD-DB-04 (escrow), OD-DB-06 (tax/fees), OD-DB-07 (currency) |
| **Database work** | `creative_projects` (FK briefs, proposals, creators, users) + `ALTER creative_proposals ADD project_id`; `ALTER creative_briefs ADD awarded_proposal_id`; `creative_fundings`; `creative_funding_logs`; `creative_refunds`; optionally `creative_project_items` (conditional in artifact 09). Migrations 7–9 |
| **Backend work** | `Project` aggregate, `ProjectStatus`, and the `AgreedTerms` invariant (`total == sum(lines) + tax + fee`) enforced in the domain. `AcceptProposalHandler` — the most safety-critical handler in the system. `FundProjectHandler`. This is the phase where `DB::transaction` + `lockForUpdate` + re-check earns its keep: two concurrent acceptances of the same proposal must produce exactly one project |
| **Reusable components** | `PaymentGatewayInterface` + `RazorpayPaymentGateway` (**contract only — `payments` is not reused, not extended, not relaxed**); `payment_logs` as the append-only log template; `refunds` as the refund template; `currency_rates` + `ExchangeRateService` if multi-currency |
| **New components** | The tables, the aggregate, the `AgreedTerms` value object, acceptance, funding |
| **API endpoints** | `POST /api/creative/proposals/{publicId}/accept` (client), `GET /api/creative/projects/{publicId}`, `GET /api/creative/projects/mine`, `POST /api/creative/projects/{projectId}/funding` (create funding intent), `POST /api/creative/projects/{projectId}/funding/{fundingId}/confirm`, `GET /api/creative/projects/{projectId}/funding` |
| **Tests** | **Concurrency:** two simultaneous acceptances of one proposal yield one project and one declined sibling, deterministically. **Immutability:** the accepted terms cannot change afterwards; a later catalogue price change does not alter them. **Financial:** amounts are `decimal(12,2)`; the `AgreedTerms` invariant holds; FX is stored when used. **Idempotency:** a repeated confirm request does not create a second funding record. **Authorisation:** only the brief's client can accept; only the proposal's creator can be awarded. **Boundary:** the gateway call happens **outside** the row lock |
| **Risks** | Making `payments.booking_id` nullable. Holding a row lock across the Razorpay call. Letting the client send the total. Treating a client-initiated confirm as a webhook — a client that abandons the callback leaves funding unconfirmed, which is a design decision (`[OPEN DECISION]`), not an implementation detail |
| **Completion criteria** | One accepted proposal produces exactly one project with frozen, auditable terms; funding records exist with an append-only log; no booking table touched; the gateway SDK appears nowhere outside `app/Infrastructure/Payments` |

---

## Phase C7 — Delivery

| Field | Content |
|---|---|
| **Prerequisites** | C6 |
| **Database work** | `creative_deliveries`, `creative_delivered_files` (FK to `creative_files`). Migration 10 |
| **Backend work** | `Delivery` aggregate, `DeliveryStatus`, submission and acceptance transitions. `SubmitDeliveryHandler`, `AcceptDeliveryHandler`, `RequestRevisionHandler` (creates the C8 revision round). Delivered files are `CreativeFile` records with delivery-scoped visibility |
| **Reusable components** | `creative_files` + the C2 grantor; `booking_media` as the **pattern** for a review-status lifecycle and for the file column set — the columns only, never the absence of ownership/visibility/access control |
| **New components** | Two tables, the aggregate, three handlers |
| **API endpoints** | `POST /api/creative/projects/{projectId}/deliveries`, `GET /api/creative/projects/{projectId}/deliveries`, `GET /api/creative/deliveries/{publicId}`, `POST /api/creative/deliveries/{publicId}/accept`, `POST /api/creative/deliveries/{publicId}/reject`, `POST /api/creative/deliveries/{publicId}/revision-requests` |
| **Tests** | Only the project's creator can submit a delivery; only the project's client can accept or reject; a delivery cannot be accepted twice; acceptance is rejected when the project is in a state that forbids it; delivered files are readable only by project participants; every transition is audited |
| **Risks** | Serving a delivered file through a `public` URL. Accepting a delivery with no files. Allowing acceptance by anyone other than the paying client |
| **Completion criteria** | A creator can submit a delivery with files; the client can accept or request a revision; files are accessible only to project participants; every transition is audited and no transition is reachable from an invalid state |

---

## Phase C8 — Revision

| Field | Content |
|---|---|
| **Prerequisites** | C7, **OD: revision limit** `[OPEN DECISION]` |
| **Database work** | `creative_revisions` (FK projects, deliveries, users, files). Migration 11 |
| **Backend work** | `Revision` aggregate, `RevisionStatus`, a `RevisionLimitPolicy` domain service. `SubmitRevisionHandler`. The round must be **auditable as a round**: request, submission, response |
| **Reusable components** | `creative_deliveries` (a revision references the delivery it responds to) |
| **New components** | The table, the aggregate, the limit policy, the handler |
| **API endpoints** | `GET /api/creative/projects/{projectId}/revisions`, `POST /api/creative/projects/{projectId}/revisions`, `POST /api/creative/revisions/{publicId}/submit` |
| **Tests** | A revision round cannot be opened after the limit — the limit is a **domain** rule, tested without a database; a concurrent pair of revision requests cannot exceed the limit (this is a genuine race and needs a concurrency test); over-limit behaviour is a `[OPEN DECISION]` and must be recorded, not invented; a revision submission is immutable once accepted |
| **Risks** | **Inventing a revision limit.** This is the most likely place for an unrequested business rule to appear. It is `[OPEN DECISION]` and the phase cannot complete without it |
| **Completion criteria** | Revision rounds are bounded by an explicitly approved limit, enforced in the domain and proven under concurrency; each round is auditable end to end |

---

## Phase C9 — Client review

| Field | Content |
|---|---|
| **Prerequisites** | C7 (a review follows a completed engagement) |
| **Database work** | `creative_reviews` (FK projects, users, creators). Migration 12 |
| **Backend work** | `CreativeReview` aggregate, `ReviewStatus`. `SubmitReviewHandler`. **A review is immutable once written** — corrections are new rows, never updates |
| **Reusable components** | The immutability discipline of `booking_pricing_breakdowns`; `ActivityLog` |
| **New components** | The table, the aggregate, the handler |
| **API endpoints** | `POST /api/creative/projects/{projectId}/reviews`, `GET /api/creative/projects/{projectId}/reviews`, `GET /api/creative/reviews/{publicId}` |
| **Tests** | **Self-review is impossible** (reviewer ≠ reviewed creator); exactly one review per project per reviewer; a review cannot be edited or deleted; a review cannot be submitted before delivery acceptance; the review is not written to `testimonials` — an explicit assertion that `testimonials` is untouched |
| **Risks** | **Reusing `testimonials`** — the single most dangerous name collision in the schema: a public marketing rating surface with no author binding and no soft delete. Allowing a creator to review themselves, or a client to edit a review |
| **Completion criteria** | An eligible client can submit exactly one immutable review; self-review and duplicate review are impossible; `testimonials` row count is unchanged by the entire Creative feature |

---

## Phase C10 — Creator earnings, commission and payout

| Field | Content |
|---|---|
| **Prerequisites** | C6, C9, **OD-DB-05** (accrual trigger, dispute window), **OD-COMMISSION** (basis, approval, cadence, provider), **OD-JOBS** |
| **Database work** | `creator_earnings`, `creator_payouts`, `creator_payout_items`. Migration 13. Optionally `app/Support/Jobs/Creative/` (decision) |
| **Backend work** | `CreatorEarning` aggregate, `EarningStatus` (with `held` if a dispute window exists), `PayoutBatch`, `PayoutStatus`. `AccrueCreatorEarningHandler`, `ApproveCreatorEarningHandler`, `ProcessCreatorPayoutHandler`. The Creative commission service **copies the locking discipline of `CommissionService`** — the only financial service besides `InventoryService` that uses `lockForUpdate` |
| **Reusable components** | `partner_commissions` as the **lifecycle and locking** template (pending → approved → paid, plus cancel). `reward_transactions` as the **shape** template (account + append-only entries + lifetime counters) — with `decimal(12,2)` and a currency column, never integer points. `screen_provider_settlements` as the **payout batch structure** template. `commission basis` if and only if it is genuinely provider-agnostic — otherwise not |
| **New components** | Three tables, two aggregates, three handlers, possibly one job |
| **API endpoints** | `GET /api/creative/earnings/mine` (creator, own only), `GET /api/creative/earnings/{publicId}`, `POST /api/creative/admin/earnings/{publicId}/approve`, `POST /api/creative/admin/payouts` (batch), `GET /api/creative/admin/payouts`, `GET /api/creative/payouts/mine` |
| **Tests** | **Accrual determinism:** identical engagement history produces identical earnings. **Immutability:** an earning is never updated; a rejection creates a reversing entry. **Double-payment:** a concurrent approve+payout cannot pay the same earning twice (unique constraint, not an application check). **Authorisation:** a creator sees only their own earnings — enforced at the route **and** in the query. **Commission:** the stored percentage is the percentage that produced the stored amount, with no recomputation. **Concurrency:** the `lockForUpdate` path is exercised |
| **Risks** | **Inventing a commission percentage** — explicitly forbidden; it is `[OPEN DECISION]`. Reusing `CommissionService` or `SettlementService` wholesale and inheriting the 10.00/90.00 provider split. Copying the integer points representation into a money ledger. Paying an earning twice under concurrency |
| **Completion criteria** | Earnings accrue from a defined, approved trigger; the accrual is reproducible; a payout cannot double-pay; no percentage, cadence or provider was invented; a reversing entry is the only correction mechanism |

---

## Phase C11 — Notifications and events

| Field | Content |
|---|---|
| **Prerequisites** | C2–C10 (the event vocabulary is derived from the states, so the states must be final) |
| **Database work** | Extend `notification_logs` `recipient_type` with `creator`. No new notification table |
| **Backend work** | Creative domain events dispatched **after commit**; new Mailables following the existing `app/Mail` pattern; Creative recipient resolution added to the existing dispatch path — extended, not duplicated |
| **Reusable components** | `NotificationService`, `NotificationEventSubscriber`, the 28 existing Mailables as the template, `notification_logs`, the existing queue connection |
| **New components** | ~13 Creative events, their Mailables, and the recipient mapping |
| **API endpoints** | None |
| **Tests** | One event per meaningful transition and no more; a notification is not sent for a rolled-back transaction; recipient resolution includes `creator` and excludes roles that should not receive it; a failed mail is observable (the existing `failed_jobs` path) |
| **Risks** | Duplicating the notification log. Building a second dispatch path. Converting existing synchronous notification behaviour to queued behaviour as a side effect — that is a **platform** change requiring its own authorisation, and is forbidden here |
| **Completion criteria** | Every Creative transition notifies the right parties exactly once through the existing path; no Creative-specific notification infrastructure exists |

---

## Phase C12 — Creative reporting

| Field | Content |
|---|---|
| **Prerequisites** | C10 (reporting over earnings) |
| **Database work** | None. Reporting **adds no aggregate** |
| **Backend work** | A Creative reporting query following the current flat-service convention (`app/Services/Creative/…Reporting…`), plus an admin dashboard extension if required. Reads canonical Creative records and **never recalculates** a quote, a split or an earning |
| **Reusable components** | The existing analytics-service convention (`Campaign/ClientAnalyticsService`, `Booking/BookingAnalyticsService`, `Payment/PaymentAnalyticsService`) as the structural precedent; `maatwebsite/excel` / `phpoffice/phpword` if exports are required |
| **New components** | The reporting query only |
| **API endpoints** | `GET /api/creative/admin/reports/creators`, `GET /api/creative/admin/reports/projects`, `GET /api/creative/admin/reports/earnings` |
| **Tests** | A reported total equals the sum of the underlying stored records; a report never disagrees with an individual record; an unauthorised user cannot read a financial report |
| **Risks** | Recalculating money in a report — the defining sin of the reporting layer. Adding a report endpoint that mutates state |
| **Completion criteria** | Every reported figure traces to stored records with no recomputation; no reporting class owns a business rule |

---

## Phase C13 — Hardening and release readiness

| Field | Content |
|---|---|
| **Prerequisites** | C1–C12 |
| **Database work** | Index review against real query plans; **no** destructive migration |
| **Backend work** | N+1 and unbounded-query review on Creative listing endpoints; authorisation review of every Creative route; file-access review; idempotency review of every money endpoint; index and pagination verification |
| **Reusable components** | The existing golden and security suites as the harness |
| **New components** | Hardening tests only |
| **API endpoints** | None new; possible additive rate limiting on Creative write endpoints (a Creative-scoped change, recorded) |
| **Tests** | The complete Creative matrix: unit, feature, API contract, authorization, database, state transition, financial, transaction/concurrency, file access, notification, event, idempotency — per artifact 25 |
| **Risks** | Broad performance rewrites (out of scope — record findings instead). Touching advertising behaviour to "optimise" shared code. Fixing platform security gaps inside a Creative task (report them; get authorisation) |
| **Completion criteria** | Full suite green apart from the 2 documented pre-existing failures and 1 warning; `ArchitectureRuleTest` and the Creative boundary test pass; route parity = 385 + Creative routes; every security finding from artifact 19 is either mitigated or explicitly accepted; a written residual-risk list |

---

## 3. Dependency graph

```text
C0 decisions
  └── C1 foundation + boundary guards
        └── C2 creator + files + audit
              └── C3 catalogue + taxonomy
                    └── C4 brief
                          └── C5 proposal + server-derived quote
                                └── C6 project + frozen terms + funding   [most safety-critical]
                                      ├── C7 delivery
                                      │     ├── C8 revision
                                      │     └── C9 review
                                      └── C10 earnings + payout           [most safety-critical]
                                            ├── C11 notifications
                                            └── C12 reporting
                                                  └── C13 hardening
```

## 4. Effort and risk profile (qualitative, no invented estimates)

| Phase | Breadth | Risk if done wrong | Irreversibility |
|---|---|---|---|
| C0 | Decision-making | High — a wrong identity decision forces a data migration | High |
| C1 | Small | Low | Low |
| C2 | Medium | **High** — file access is a security boundary | Medium |
| C3 | Medium | Low–medium | Low |
| C4 | Medium | Medium — brief confidentiality | Low |
| C5 | Medium | **High** — price integrity | Medium |
| C6 | Large | **Critical** — money and concurrency | **High** |
| C7 | Medium | High — client/creator file exposure | Medium |
| C8 | Small | Medium — unbounded revisions | Low |
| C9 | Small | Medium — review integrity | Medium (immutability) |
| C10 | Large | **Critical** — money paid twice, or a wrong rate | **High** |
| C11 | Medium | Low | Low |
| C12 | Small | Medium — reports that disagree with records | Low |
| C13 | Medium | Medium — regression in protected areas | Low |

## 5. What is explicitly **not** in this sequence

- No migration to make `payments` reusable. Creative funding is a new table.
- No refactor of the advertising booking, pricing, availability, settlement or provider logic. Existing debt stays on its scheduled order.
- No policy migration of the 385 existing routes.
- No search subsystem, no event sourcing, no microservices, no CQRS framework, no second queue, no second storage stack, no new package without a decision record.
- No Creative–CMS integration. Project 03 is a separate application and database.
- No deletion or renaming of anything, including "obviously dead" code, without Rule 13 verification and an explicit task.
