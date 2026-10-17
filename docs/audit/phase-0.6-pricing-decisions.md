# Phase 0.6 — Pricing Architectural Decisions

## Overview

This Architecture Decision Record (ADR) documents the architectural, domain, and contract decisions established in Phase 0.6 for the Pricing Domain in the Illuvision Advertising Marketplace backend.

---

## Decision 1: Canonical Domain Pricing Boundary
* **Decision**: Establish `App\Domain\Pricing\CampaignPricingEngine` implementing `App\Domain\Pricing\Contracts\PricingEngineInterface` as the canonical pricing domain calculation engine.
* **Context**: Multiple legacy paths previously computed or orchestrated pricing. Moving calculation rules into a dedicated domain engine creates a single source of truth for commercial rate evaluations.
* **Status**: **ACCEPTED & IMPLEMENTED**

---

## Decision 2: Legacy V2 Pricing Preservation via Application Adapter
* **Decision**: Retain `App\Services\PricingEngine` for `/api/v2/pricing/calculate`, isolated behind `App\Application\Pricing\Adapters\LegacyPricingEngineAdapter`.
* **Context**: `PricingEngine` uses a divergent calculation model (simple monthly/weekly division, flat 10% volume discount for >= 30 days, no peak/weekend charges, no referral/loyalty support, different request/response schemas). Merging it into `CampaignPricingEngine` would break existing external contracts.
* **Status**: **ACCEPTED & IMPLEMENTED**

---

## Decision 3: Partner Pricing Domain Ownership
* **Decision**: Route Partner booking calculations (`POST /api/partner/bookings/calculate`) through the canonical `CampaignPricingEngine`, retaining partner-specific referral codes and commission attribution.
* **Context**: Partner pricing shares the exact mathematical base, duration, and peak/weekend rules as advertiser pricing, but automatically attaches the partner's referral code to calculate partner commissions.
* **Status**: **ACCEPTED & IMPLEMENTED**

---

## Decision 4: Loyalty Points Cycle Resolution
* **Decision**: Treat customer reward points as a pure pricing calculation input rather than having the pricing domain invoke container-bound loyalty mutations.
* **Context**: Phase 0.2 identified a soft cyclic dependency between `CampaignPricingService` and `LoyaltyService`. By accepting `redeem_points` as an input and performing read-only point value conversion, the domain engine avoids cyclic side-effects.
* **Status**: **ACCEPTED & IMPLEMENTED**

---

## Decision 5: Booking Breakdown Storage Postponement
* **Decision**: Defer extraction of `storePricingBreakdowns()` from `CampaignPricingService` to **Phase 0.8 (Booking Application Extraction)**.
* **Context**: Storing pricing snapshots into `booking_pricing_breakdowns` is a database write transaction tightly coupled with booking confirmation and inventory locking. It belongs to the Booking Domain, not the pure Pricing Domain.
* **Status**: **DEFERRED TO PHASE 0.8**

---

## Decision 6: Future V1/V2 Pricing Engine Unification
* **Status**: **DECISION REQUIRED (Product / Business Stakeholder Sign-Off)**
* **Details**: Unifying `CampaignPricingEngine` and `PricingEngine` into a single multi-tier engine would require deprecating the V2 payload (`items: [{asset_id, quantity}]`) and response keys (`pricing.gross_total`, `line_items`) in favor of canonical V1 formats, or providing a translation layer that changes commercial calculation outputs for V2 clients. Until product stakeholders authorize changing V2 pricing outputs, the legacy engine must remain isolated behind `LegacyPricingEngineAdapter`.
