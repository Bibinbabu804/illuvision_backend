# 07 — Creative Business Flow Map

**Generated:** 2026-09-26
**Status:** Design model. Nothing here is implemented. Every step is classified.

## Classification scheme

| Tag | Meaning |
|---|---|
| `CONFIRMED` | The step is stated in the project brief/docs and is in scope. |
| `EXISTING` | A working implementation already exists in this repository. |
| `TARGET` | Required by the brief, no implementation exists. |
| `NEEDS PRODUCT DECISION` | Cannot be implemented until product/architecture decides. |
| `NOT REQUIRED` | Out of scope for the initial release. |

**Critical framing note:** the only source for the flow below is the task brief's stated lifecycle. No `docs/` file in this repository describes the Creative business flow. Every state name, threshold and actor below is a `[RECOMMENDATION]` unless tagged otherwise.

---

## The end-to-end flow

```text
 1  Creator onboarding
 2  Creator profile
 3  Portfolio / capability
 4  Creative service listing
 5  Client discovers service
 6  Client creates creative brief
 7  Creator receives brief / opportunity
 8  Proposal
 9  Acceptance
10  Creative project
11  Payment / funding
12  Work execution
13  Revision cycle
14  Delivery
15  Client review
16  Completion
17  Creator earnings
18  Platform commission / payout
```

---

## Step 1 — Creator onboarding

| Aspect | Detail |
|---|---|
| **Classification** | `TARGET` + `NEEDS PRODUCT DECISION` |
| **Domain object** | `Creator` (aggregate root) |
| **API** | `POST /api/creative/creators` (apply), `GET /api/creative/creators/me`, `PATCH /api/creative/creators/me` |
| **Controller** | `Creative\CreatorController` (new) |
| **Use case** | `SubmitCreatorApplication` → `ApproveCreatorApplication` (admin) → `ActivateCreator` |
| **Tables** | `creators` (NEW), `users` (EXISTING — role promotion), `activity_logs` (EXISTING) |
| **Transaction boundary** | Application creation: single insert. Approval: one transaction — update creator status + promote `users.role` to `creator` + write activity log. **A failed role promotion with a successful creator update would leave an unusable account; both must be in one transaction.** |
| **Authorization** | `auth:sanctum` for apply. `permission:creative.creators.approve` for approval. Super-admin bypass applies (Rule 12). |
| **Event** | `CreatorRegistered` → admin notification (mirrors `ScreenProviderEnquirySubmitted` → `ScreenProviderApproved`) |
| **Notification** | Admin: "new creator application". Creator: "application received" and later "application approved" (mirrors `ScreenProviderEnquirySubmittedMail` / `ScreenProviderApprovedMail`, both already reusing the `ScreenProvider` pattern including the setup-token URL). |
| **Payment dependency** | None |
| **Financial consequence** | None |

**Open decisions:**
- Is registration open self-service, invite-only, or admin-created? (`AuthController::register` hardcodes `role: 'user'` — a self-service flow needs a separate, authorized role-promotion path.)
- Is identity/KYC verification required before activation? There is **no KYC infrastructure anywhere** in this repository.
- Can one user be both an advertiser (`client`) and a creator? A single `users.role` varchar cannot express that today. **This is a hard blocker requiring a decision.**

---

## Step 2 — Creator profile

| Aspect | Detail |
|---|---|
| **Classification** | `TARGET` |
| **Domain object** | `Creator`, `CreatorProfile` |
| **API** | `GET /api/creative/creators/{publicId}` (public), `GET/PATCH /api/creative/creators/me` |
| **Controller** | `Creative\CreatorController`, `Creative\CreatorProfileController` |
| **Use case** | `UpdateCreatorProfile` |
| **Tables** | `creators` (NEW) |
| **Transaction** | Single update |
| **Authorization** | `CreativeCreatorPolicy` (own profile only) |
| **Event** | `CreatorProfileUpdated` |
| **Notification** | None (self-service) |
| **Payment** | None |
| **Financial** | None |

**Do-not-confuse warning:** `App\Services\ProfileService` is the **advertiser** profile. A junior developer must not extend it. Creator profile mirrors the *shape* of `ScreenProviderService::create/update` (which is the in-repo precedent for a non-advertiser profile), not the class.

---

## Step 3 — Portfolio / capability

| Aspect | Detail |
|---|---|
| **Classification** | `TARGET` |
| **Domain object** | `PortfolioItem` (entity under `Creator`) |
| **API** | `GET/POST/PATCH/DELETE /api/creative/creators/{publicId}/portfolio`, `GET /api/creative/creators/me/portfolio` |
| **Controller** | `Creative\PortfolioController` |
| **Use case** | `AddPortfolioItem`, `RemovePortfolioItem` |
| **Tables** | `creative_portfolio_items` (NEW) — stores file references, never file blobs |
| **Transaction** | Upload is: store file → insert row. If the insert fails the file is orphaned. **Recommended:** store the file first (idempotent, content-addressed name), then insert the row; on insert failure, delete the file in a compensating step. `ProfileService::uploadAvatar` currently does the reverse (delete-old, store-new, then update the row) with no rollback — do not copy that. |
| **Authorization** | `CreativeCreatorPolicy` |
| **Event** | `PortfolioItemAdded` |
| **Notification** | Optional admin notification for moderation |
| **Payment** | None |
| **Financial** | None |

**Reuse:** `StorageContractInterface` (NEW private/public adapter as decided) + `ImageProcessingContractInterface` for a WebP preview. Validation must be enforced in the Form Request — never in the controller. Do **not** route through `App\Http\Controllers\FileController` (zero validation, path traversal).

---

## Step 4 — Creative service listing

| Aspect | Detail |
|---|---|
| **Classification** | `TARGET` + `NEEDS PRODUCT DECISION` |
| **Domain object** | `CreativeService` (aggregate root) |
| **API** | `GET /api/creative/services` (public discovery), `GET/POST/PATCH /api/creative/services/{publicId}`, `POST /api/creative/services/{publicId}/publish` |
| **Controller** | `Creative\CreativeServiceController` |
| **Use case** | `CreateCreativeService`, `PublishCreativeService`, `PauseCreativeService` |
| **Tables** | `creator_services` (NEW), `creative_service_categories` + `creative_service_category_map` (NEW) |
| **Transaction** | Create: service row + category junction rows in one transaction. Publish: status transition, validate required fields present. |
| **Authorization** | `CreativeServicePolicy` (own) + `permission:creative.services.*` (admin) |
| **Event** | `CreativeServicePublished` |
| **Notification** | Admin moderation notice; creator confirmation |
| **Payment** | None |
| **Financial** | A published **rate** is a price quote, not a financial fact. No financial record is created here. |

**Open decisions:**
- Is a rate mandatory or can a service be "quote on request"? This determines whether a brief can be auto-priced.
- Pricing unit: per project / per hour / per item / per day? (Note: `App\Domain\Pricing\Enums\PricingUnit` is for **ad media** — `per_screen`, `per_vehicle`, `per_team`, `per_event` — and must not be reused.)
- Does Creative permit multi-currency listing, or is it INR-only?

---

## Step 5 — Client discovers service

| Aspect | Detail |
|---|---|
| **Classification** | `TARGET` |
| **Domain object** | Read-only projections over `CreativeService`, `Creator`, `PortfolioItem` |
| **API** | `GET /api/creative/creators` (directory), `GET /api/creative/services` (search/filter), `GET /api/creative/creators/{publicId}` |
| **Controller** | `Creative\CreatorDirectoryController`, `Creative\CreativeServiceController@index` |
| **Use case** | None — a query layer, not a use case. Mirrors `App\Application\Reporting`'s `ReportQuery` + `ReportQueryRegistry` pattern. |
| **Tables** | `creator_services`, `creators`, `creative_portfolio_items`, `creative_service_categories` |
| **Transaction** | None (read-only) |
| **Authorization** | Public. Must not leak creator email, internal ids, earnings, or the identity of clients who have contacted them. |
| **Event** | None |
| **Notification** | None |
| **Payment** | None |
| **Financial** | Rate display only |

**Required indexes** (see the blueprint): `(status, category_id)`, `(creator_id, status)`, `creator_services.slug` unique. **There is no fulltext index anywhere in this repository** — 0 `fullText()` calls in 112 migrations. Discovery built on unindexed `LIKE` will not scale. Decision needed: LIKE with composite indexes (minimum) vs MySQL FULLTEXT (scalable).

**Rate limiting:** apply a limiter. Only one limiter exists today (`recommendations`, 30/min by IP).

---

## Step 6 — Client creates creative brief

| Aspect | Detail |
|---|---|
| **Classification** | `TARGET` + `NEEDS PRODUCT DECISION` |
| **Domain object** | `CreativeBrief` (aggregate root), `BriefAttachment` |
| **API** | `POST /api/creative/briefs`, `GET /api/creative/briefs/mine`, `GET/PATCH /api/creative/briefs/{publicId}` |
| **Controller** | `Creative\BriefController` |
| **Use case** | `CreateBrief`, `PublishBrief`, `CloseBrief` |
| **Tables** | `creative_briefs` (NEW), `creative_brief_attachments` (NEW) |
| **Transaction** | Brief + attachments in one transaction. Files stored before the transaction, referenced after. |
| **Authorization** | `CreativeBriefPolicy` (own client only) + `auth:sanctum` |
| **Event** | `BriefCreated` |
| **Notification** | If briefs are browsable: notify matching creators. **If notification is per-match, this is a fan-out with no durable dedup and no queue — a scale problem from day one.** OPEN DECISION: notify selected creators, notify all matching creators, or no notification (creators browse). |
| **Payment** | None |
| **Financial** | A budget range is an **expectation, not a financial fact**. No payment may reference a brief. |

**Open decisions:**
- Is a brief created against a specific service (and therefore a specific creator), or is it an open opportunity any creator may answer? The two models produce materially different schemas and different notification strategies.
- Is a budget range required, optional, or forbidden? Publishing a budget changes proposal dynamics.
- Is the brief visible to all creators, only to invited creators, or only to the service owner?

---

## Step 7 — Creator receives brief / opportunity

| Aspect | Detail |
|---|---|
| **Classification** | `TARGET` + `NEEDS PRODUCT DECISION` |
| **Domain object** | Read model over `CreativeBrief` |
| **API** | `GET /api/creative/briefs/opportunities`, `GET /api/creative/briefs/{publicId}` (creator-scoped view, must not reveal client identity) |
| **Controller** | `Creative\BriefOpportunityController` |
| **Tables** | `creative_briefs` (+ a `creative_brief_matches` table IF targeted delivery is chosen — OPEN DECISION) |
| **Authorization** | `auth:sanctum` + `permission:creative.briefs.view` **and** creator status = active |
| **Financial** | None |

**Critical privacy requirement:** the creator's view of a brief must not expose the client's name, email or user id. This requires a purpose-built read model, not a shared API Resource.

---

## Step 8 — Proposal

| Aspect | Detail |
|---|---|
| **Classification** | `TARGET` + `NEEDS PRODUCT DECISION` |
| **Domain object** | `CreativeProposal` (aggregate root) |
| **API** | `POST /api/creative/briefs/{publicId}/proposals`, `GET /api/creative/proposals/mine`, `GET /api/creative/briefs/{publicId}/proposals` (client view) |
| **Controller** | `Creative\ProposalController` |
| **Use case** | `SubmitProposal`, `WithdrawProposal` |
| **Tables** | `creative_proposals` (NEW) |
| **Transaction** | Submission: single insert + status transition + `BriefOpened` state change. |
| **Authorization** | `CreativeProposalPolicy` — the creator must be the proposal owner AND the brief must be open |
| **Event** | `ProposalSubmitted` |
| **Notification** | Client: "new proposal received". Creator: confirmation. |
| **Payment** | None |
| **Financial** | The quoted amount is **not yet a financial fact** |

**Open decisions:**
- Multiple proposals per brief, or exactly one? (Affects a unique constraint.)
- Does submitting a proposal change the brief's state?
- Is the quoted amount creator-supplied or derived from the service rate? **If client-supplied, a malicious client-supplied or creator-supplied price must never be trusted as a financial fact — it is frozen into `AgreedTerms` on acceptance and must be range-checked against the brief budget and/or the listed rate.**

---

## Step 9 — Acceptance — *the concurrency-critical step*

| Aspect | Detail |
|---|---|
| **Classification** | `TARGET` + `NEEDS PRODUCT DECISION` |
| **Domain object** | `CreativeProposal` → `CreativeProject` |
| **API** | `POST /api/creative/proposals/{publicId}/accept`, `POST /api/creative/proposals/{publicId}/decline` |
| **Controller** | `Creative\ProposalController` |
| **Use case** | `AcceptProposal` |
| **Tables** | `creative_proposals`, `creative_projects` (NEW), `creative_briefs`, `activity_logs` |
| **Transaction** | **MANDATORY, single transaction, with a pessimistic lock:**<br>1. `DB::transaction`<br>2. `lockForUpdate()` the brief<br>3. re-check the brief is still open<br>4. `lockForUpdate()` the proposal, re-check it is `submitted` and not expired<br>5. set proposal → `accepted`, all sibling proposals → `declined` (if multi-proposal)<br>6. **freeze `AgreedTerms` onto a new `creative_projects` row**<br>7. set brief → `awarded`<br>8. write `activity_logs`<br>9. commit<br>Events dispatched **after** commit. |
| **Authorization** | `CreativeProposalPolicy`: the caller must be the **brief's** owner (not merely any authenticated user), and the brief must belong to them. |
| **Event** | `ProposalAccepted` (post-commit), `ProjectCreated` (post-commit) |
| **Notification** | Both parties |
| **Payment** | None yet — see step 11 |
| **Financial** | **This is where an amount becomes a financial fact.** `AgreedTerms` is written once and is immutable thereafter. `EarningsCalculationDomainService` may be invoked here or at project completion, but the computed values are stored, never recomputed. |

**Why this step is the highest-risk in the build:** acceptance is (a) a state transition, (b) a money commitment, and (c) a fan-out if multiple proposals exist. Two concurrent accepts of different proposals on the same brief must produce exactly one project. See `16-transaction-concurrency-analysis.json` and risk `CR-01`.

**The direct precedent exists in this repository and must be copied in shape:** `InventoryService::lockScreensForBooking()` orders by `id` for deadlock avoidance, and `CommissionService` locks the commission row before the referral usage row. Creative must document and pin its own lock order.

---

## Step 10 — Creative project

| Aspect | Detail |
|---|---|
| **Classification** | `TARGET` |
| **Domain object** | `CreativeProject` (aggregate root) |
| **API** | `GET /api/creative/projects`, `GET /api/creative/projects/{publicId}`, `GET/PATCH /api/creative/projects/{publicId}/terms` (admin only) |
| **Controller** | `Creative\ProjectController` |
| **Use case** | `StartProject`, `CancelProject` |
| **Tables** | `creative_projects` (NEW), `creative_project_items` (NEW — **conditionally required**, see blueprint) |
| **Transaction** | Start: status transition + timestamp. Cancel: status transition + cancellation of any pending earning + activity log, in one transaction. |
| **Authorization** | `CreativeProjectPolicy` — the client and the assigned creator, with **asymmetric** permitted transitions |
| **Event** | `ProjectStarted`, `ProjectCancelled` |
| **Notification** | Both parties |
| **Payment** | See step 11 |
| **Financial** | Cancellation consequences depend on the funding model (OPEN DECISION) |

---

## Step 11 — Payment / funding — *the largest open decision*

| Aspect | Detail |
|---|---|
| **Classification** | `TARGET` + `NEEDS PRODUCT DECISION` (blocking) |
| **Domain object** | `CreativeFunding` (OPEN DECISION whether this is its own aggregate or a `payments`-like table scoped to a project) |
| **API** | `POST /api/creative/projects/{publicId}/fund`, `POST /api/creative/projects/{publicId}/fund/verify` |
| **Use case** | `FundProject`, `ConfirmProjectFunding` |
| **Tables** | `creative_fundings` (NEW). **OPEN DECISION:** reuse the existing `payments` table via a nullable `creative_project_id`, or a new table. Reusing `payments` couples Creative to `payments.booking_id` (NOT NULL, CASCADE) and to the booking status transition in `PaymentService:103`. **Recommendation: a new table.** |
| **Transaction** | Order creation: gateway call **outside** the transaction (Rule 4 — the existing `RefundService:51` violates this and must not be copied). Confirmation: single transaction — write funding row → transition project → write `payment_logs`-equivalent → activity log. |
| **Authorization** | `CreativeProjectPolicy` (client only). **No creator-side access to funding at any point.** |
| **Event** | `ProjectFunded` (post-commit) |
| **Notification** | Both parties |
| **Payment** | `PaymentGatewayInterface` (`createOrder`, `verifySignature`, `refund`) via the existing Razorpay adapter — **contract reuse only** |
| **Financial** | The funded amount, currency and exchange rate must be **snapshotted** exactly as `bookings` does (`exchange_rate_used` decimal(14,6), `currency_code`, `display_amount`). |

**Open decisions (blocking the whole financial design):**
1. **Escrow vs upfront vs milestone tranches.** `escrow` has **zero occurrences** in this repository, and no Razorpay escrow product is in use. There is no escrow infrastructure to reuse.
2. **Does funding precede or follow project creation?** Step 9 (accept) currently creates the project before funding, which implies an unfunded state.
3. **Server-derived vs client-supplied amounts.** Recommendation: the client supplies no amount; the server derives it from the accepted proposal. This is the same discipline as advertising pricing, where `CampaignPricingService` derives all amounts server-side.
4. **Verification is client-initiated only.** **No webhook endpoint exists in this repository.** Either Creative accepts client-initiated verification (with its replay risks) or webhook support is new work requiring signature verification and durable idempotency.
5. **Idempotency.** There is no idempotency mechanism anywhere. Funding is the highest-risk place for it.

---

## Step 12 — Work execution

| Aspect | Detail |
|---|---|
| **Classification** | `TARGET` + `NEEDS PRODUCT DECISION` |
| **Domain object** | `CreativeProject` status |
| **API** | `POST /api/creative/projects/{publicId}/start` |
| **Tables** | `creative_projects` |
| **Transaction** | Status transition with a guarded update (`where status = X` → `set status = Y`; zero rows affected = conflict) |
| **Authorization** | `CreativeProjectPolicy` (creator only) |
| **Event** | `ProjectStarted` |
| **Notification** | Client |
| **Financial** | None |

---

## Step 13 — Revision cycle

| Aspect | Detail |
|---|---|
| **Classification** | `TARGET` + `NEEDS PRODUCT DECISION` |
| **Domain object** | `CreativeRevision` (aggregate root) |
| **API** | `POST /api/creative/projects/{publicId}/revisions`, `GET /api/creative/projects/{publicId}/revisions`, `POST /api/creative/revisions/{publicId}/submit`, `POST /api/creative/revisions/{publicId}/accept` |
| **Controller** | `Creative\RevisionController` |
| **Use case** | `RequestRevision`, `SubmitRevision`, `AcceptRevision` |
| **Tables** | `creative_revisions` (NEW) |
| **Transaction** | Revision request: insert + round number allocation. **Round allocation must be concurrency-safe** — allocate `round = max(round)+1` inside the transaction with a lock on the project, or use a per-project counter on `creative_projects` updated with an atomic increment. A unique(project_id, round) constraint is mandatory as a backstop. |
| **Authorization** | `CreativeRevisionPolicy` — client requests, creator submits, client accepts. Asymmetric. |
| **Event** | `RevisionRequested`, `RevisionSubmitted`, `RevisionAccepted` |
| **Notification** | Both parties at each transition |
| **Payment** | None |
| **Financial** | An over-limit revision may create an additional earning. **Pricing rule is an OPEN DECISION.** |

**Open decisions:** included revision count (in `AgreedTerms`? on the listing? unlimited?); over-limit behaviour (charge, refuse, or allow with admin approval); whether a revision is always scoped to a specific delivery; whether revision files create a new delivery or a new revision artifact.

**Reuse note:** the `booking_media` approval pattern (`review_status` + `reviewed_by` + `reviewed_at` + `rejection_reason`) is a good **shape** template for a revision submission/acceptance, but it is a different aggregate and must not be extended.

---

## Step 14 — Delivery

| Aspect | Detail |
|---|---|
| **Classification** | `TARGET` |
| **Domain object** | `CreativeDelivery` (aggregate root) + `DeliveredFile` |
| **API** | `POST /api/creative/projects/{publicId}/deliveries`, `GET /api/creative/projects/{publicId}/deliveries`, `POST /api/creative/deliveries/{publicId}/accept`, `POST /api/creative/deliveries/{publicId}/reject`, `GET /api/creative/deliveries/{publicId}/files/{fileId}` (authorised download) |
| **Controller** | `Creative\DeliveryController` |
| **Use case** | `SubmitDelivery`, `AcceptDelivery`, `RejectDelivery` |
| **Tables** | `creative_deliveries` (NEW), `creative_delivered_files` (NEW) |
| **Transaction** | Delivery + files in one transaction. Files stored before the transaction; on failure, compensating delete. |
| **Authorization** | `CreativeDeliveryPolicy` for state transitions; **per-file authorization for every download** |
| **Event** | `DeliverySubmitted`, `DeliveryAccepted`, `DeliveryRejected` |
| **Notification** | Both parties |
| **Payment** | None |
| **Financial** | Acceptance **may** trigger `CreatorEarning` accrual. Trigger rule is an OPEN DECISION. |

**Security-critical:** delivered source files must be on a **private** disk with per-request authorization. This repository has **zero signed URLs** and every stored path is a permanent public URL. A creative delivery on the public disk is a data leak. See `19-creative-security-risk-register.json` (`SR-05`, `SR-06`).

**Reuse:** `StorageContractInterface` — but a **new** private-disk adapter and a **new** signed-access contract are required. `LaravelStorageAdapter` hard-codes `DISK = 'public'`.

---

## Step 15 — Client review

| Aspect | Detail |
|---|---|
| **Classification** | `TARGET` + `NEEDS PRODUCT DECISION` |
| **Domain object** | `CreativeReview` (aggregate root) |
| **API** | `POST /api/creative/projects/{publicId}/review`, `GET /api/creative/creators/{publicId}/reviews` |
| **Controller** | `Creative\ReviewController` |
| **Use case** | `SubmitReview` |
| **Tables** | `creative_reviews` (NEW) |
| **Transaction** | Insert with a **unique(project_id, reviewer_user_id)** constraint. A duplicate insert fails; the use case converts that into a 409. |
| **Authorization** | `CreativeReviewPolicy` — the reviewer must have been a party to the project. **No edit, no delete, ever.** |
| **Event** | `ReviewSubmitted` |
| **Notification** | Creator |
| **Payment** | None |
| **Financial** | A rating **must not** affect an already-accrued earning (that would break financial immutability). If a rating affects future commission tiering, that is a rules decision, not a data edit. |

**Open decisions:** required or optional for completion; one per project or one per delivery; public on the creator profile or private; single score or per-criterion; does it gate the dispute window?

**Reuse warning:** `testimonials.rating` is public marketing content with **no author binding** and **no soft delete**. Reusing it would allow anyone to edit anyone's rating. Do not.

---

## Step 16 — Completion

| Aspect | Detail |
|---|---|
| **Classification** | `TARGET` + `NEEDS PRODUCT DECISION` |
| **Domain object** | `CreativeProject` status |
| **API** | `POST /api/creative/projects/{publicId}/complete` (admin) or automatic on final delivery acceptance |
| **Tables** | `creative_projects` |
| **Transaction** | Status transition + `completed_at` + activity log, in one transaction |
| **Authorization** | `CreativeProjectPolicy` or `permission:creative.projects.*` (admin) |
| **Event** | `ProjectCompleted` (domain), `CreativeProjectCompleted` (integration, to CMS) |
| **Notification** | Both parties |
| **Payment** | None |
| **Financial** | Likely the earning-accrual trigger. OPEN DECISION. |

---

## Step 17 — Creator earnings

| Aspect | Detail |
|---|---|
| **Classification** | `TARGET` + `NEEDS PRODUCT DECISION` (blocking) |
| **Domain object** | `CreatorEarning` (aggregate root) |
| **API** | `GET /api/creative/earnings/mine`, `GET /api/creative/earnings/{publicId}` (admin) |
| **Controller** | `Creative\EarningsController` |
| **Use case** | `AccrueCreatorEarning` (system), `ApproveCreatorEarning` (finance), `ReleaseCreatorEarnings` (finance) |
| **Tables** | `creator_earnings` (NEW), `creator_earning_adjustments` (NEW, if adjustments are supported) |
| **Transaction** | Accrual: insert one immutable row. **A unique(project_id, [earning_type]) constraint is mandatory** so re-running an event handler cannot double-credit. This is the direct answer to the missing idempotency infrastructure. |
| **Authorization** | Creator sees **only their own**; `permission:creative.earnings.view` for admin; `permission:creative.payouts.approve` for approval/release |
| **Event** | `CreatorEarningCreated` (post-commit) |
| **Notification** | Creator on accrual and on release |
| **Payment** | None at accrual |
| **Financial** | **Immutable financial fact.** Mirrors `booking_screens` (gross_revenue, platform_share, provider_share + the split percentages) so the split is auditable without recomputation. |

**Open decisions (all blocking):**
1. Accrual trigger: delivery accepted, project completed, review submitted, or after a dispute window?
2. Is there a dispute/hold window? Nothing named "dispute" exists anywhere in this repository.
3. Who approves an earning — a finance role, an admin, or automatic?
4. Platform commission basis: the quoted amount, or the amount net of third-party costs?
5. Is commission rate fixed, tiered by creator, or tiered by category?
6. Does a cancelled or refunded project reverse an accrual — and if so, by deletion (forbidden) or by a compensating reversing record (required)?

**The reversal rule is not optional.** `AGENTS.md` §10: "Financial history is immutable. Fix forward with compensating records; never rewrite a stored financial fact." A `reversed` status plus a linked reversing `creator_earnings` row is the only compliant design.

---

## Step 18 — Platform commission and payout

| Aspect | Detail |
|---|---|
| **Classification** | `TARGET` + `NEEDS PRODUCT DECISION` (blocking) |
| **Domain object** | `CreatorPayout` (aggregate root) |
| **API** | `POST /api/creative/payouts` (creator requests), `GET /api/creative/payouts/mine`, `GET /api/creative/payouts` (finance), `POST /api/creative/payouts/{publicId}/approve`, `POST /api/creative/payouts/{publicId}/reject` |
| **Controller** | `Creative\PayoutController` |
| **Use case** | `RequestPayout`, `ApprovePayout`, `RejectPayout`, `DisbursePayout` |
| **Tables** | `creator_payouts` (NEW), `creator_payout_items` (NEW) |
| **Transaction** | **Separation of duties is mandatory:** the creator who requests must not be the approver. Enforce by comparing `requested_by` to `approved_by` inside the transaction, and by route permission. Payout amount must be **server-derived** from approved unreleased earnings — never accepted from the client. |
| **Authorization** | `CreativePayoutPolicy` + `permission:creative.payouts.request` / `.approve`. Never the same user. |
| **Event** | `CreatorPayoutRequested`, `CreatorPayoutApproved` (post-commit) |
| **Notification** | Finance on request; creator on approval/rejection/disbursement |
| **Payment** | `PaymentGatewayInterface::refund` is not the right primitive. **There is no payout primitive in this repository** — `CommissionService::markAsPaid` is an admin status change with optional metadata, not a disbursement. **Actual money movement to a creator is NEW WORK** and depends entirely on the payment provider's payout capability. |
| **Financial** | `screen_provider_settlements` is the structural precedent: period start/end, gross/platform/provider revenue, `reference_number` (unique), `generated_by`/`approved_by`/`paid_by` user FKs, `generated_at`/`approved_at`/`paid_at` timestamps, status `draft→generated→approved→paid|cancelled`. Creative payouts should mirror this. |

**Open decisions:** payout cadence (on request / weekly / monthly); minimum threshold; does approval trigger immediate disbursement or a batched run; is a settlement period needed at all if earnings release on completion; what payout destination and compliance data is required; who may see a creator's payout destination.

**The `FinanceService:53` hard-coded `0.70` platform margin is an advertising constant. It must never be copied into Creative.** Commission belongs in settings (`creative.commission_rate`), not in code.

---

## Flow-step classification summary

| # | Step | Classification | Blocking open decisions |
|---|---|---|---|
| 1 | Creator onboarding | TARGET | Role model (can a user be both client and creator?), KYC, self-service vs invite |
| 2 | Creator profile | TARGET | — |
| 3 | Portfolio | TARGET | — |
| 4 | Creative service listing | TARGET | Rate mandatory?, pricing unit, currency |
| 5 | Client discovery | TARGET | Search strategy (no fulltext exists) |
| 6 | Brief creation | TARGET | Brief vs service-bound, budget required?, visibility model |
| 7 | Opportunity delivery | TARGET | Notify all vs selected vs browse |
| 8 | Proposal | TARGET | Multi vs single proposal, amount trust boundary |
| 9 | Acceptance | TARGET | Multi vs single, auto-decline siblings |
| 10 | Project | TARGET | — |
| 11 | **Payment / funding** | TARGET | **Escrow vs upfront vs milestone; funding order; webhook or not; idempotency** |
| 12 | Work execution | TARGET | — |
| 13 | Revision | TARGET | Revision limit, over-limit pricing, delivery scoping |
| 14 | Delivery | TARGET | On rejection: supersede or revise in place |
| 15 | Review | TARGET | Required?, scope, public?, affects money? |
| 16 | Completion | TARGET | Automatic or manual |
| 17 | **Creator earnings** | TARGET | **Accrual trigger, dispute window, approval authority, commission basis, reversal rule** |
| 18 | **Commission / payout** | TARGET | **Cadence, threshold, disbursement mechanism, compliance, settlement period needed?** |

**18 steps. 0 are `EXISTING`. 18 are `TARGET`. 11 carry blocking product decisions. The three highest-risk steps — acceptance (9), funding (11) and earnings/payout (17, 18) — are exactly the ones with no existing precedent in this codebase.**
