# Sprint 2 – Core Business Engines & Booking Platform Documentation

**Project:** Illuvision DOOH Asset Platform  
**Sprint:** Sprint 2  
**Date:** July 21, 2026  

---

## Architecture Overview

Sprint 2 implemented the core business engines powering booking state workflows, pricing preview calculations, availability & fleet capacity validation, smart asset recommendations, and centralized notification dispatching.

```
+-----------------------------------------------------------------------------------+
|                                 REST APIs (v2)                                    |
|   /v2/bookings   /v2/pricing/calculate   /v2/availability/check  /v2/recommendations|
+-----------------------------------------------------------------------------------+
       |                       |                          |                 |
       v                       v                          v                 v
+--------------+     +-------------------+     +------------------+  +---------------+
| BookingEngine|     |   PricingEngine   |     |AvailabilityEngine|  | Recommendation|
|  Controller  |     |    Controller     |     |   Controller     |  |  Controller   |
+--------------+     +-------------------+     +------------------+  +---------------+
       |                       |                          |                 |
       v                       v                          v                 v
+------------------+ +-------------------+    +-------------------+ +---------------+
| BookingWorkflow  | |   PricingEngine   |    | ConflictDetection | | Recommendation|
|     Service      | |      Service      |    |      Service      | |    Service    |
+------------------+ +-------------------+    +-------------------+ +---------------+
```

---

## Delivered Business Engines

### 1. Booking Engine (`BookingWorkflowService`)
- Manages complete lifecycle state transitions (`draft` $\rightarrow$ `pending_approval` $\rightarrow$ `approved` $\rightarrow$ `scheduled` $\rightarrow$ `running` $\rightarrow$ `completed` / `cancelled` / `rejected`).
- Validates invalid transitions via custom domain exception `InvalidBookingStateException`.
- Handles multi-asset line items per booking.

### 2. Availability Engine (`AvailabilityService` & `ConflictDetectionService`)
- Enforces fixed screen slot limits and mobile asset fleet capacity reservation locks across date ranges.
- Throws `FleetCapacityExceededException` when requested quantities exceed available fleet inventory over campaign dates.

### 3. Pricing Engine (`PricingEngine`)
- Multi-asset pricing calculator: `Subtotal = Base Price * Quantity * Duration + Peak Surcharges - Volume Discounts`.
- Provides breakdown of gross total, duration discounts, and final net amount.

### 4. Recommendation Engine (`RecommendationService`)
- Provides smart asset recommendations filtered by city, maximum budget, and asset type.

### 5. Notification Engine (`NotificationDispatcher`)
- Asynchronously logs and dispatches system notifications across booking lifecycle events.

---

## REST API Endpoints (v2)

| Method | Endpoint | Description |
| :--- | :--- | :--- |
| `GET` | `/api/v2/bookings` | Fetch paginated list of bookings with screen details |
| `POST` | `/api/v2/bookings` | Submit a multi-asset booking order with availability & pricing validation |
| `GET` | `/api/v2/bookings/{id}` | Get booking details |
| `POST` | `/api/v2/bookings/{id}/transition` | Transition booking state with reason support |
| `POST` | `/api/v2/pricing/calculate` | Compute instant multi-asset campaign pricing preview |
| `POST` | `/api/v2/availability/check` | Check real-time availability and fleet capacity |
| `GET` | `/api/v2/recommendations` | Get smart asset recommendations by budget/city |

---

## Verification & Test Results

- Executed database migrations cleanly (**Zero `migrate:fresh` or DB truncations used**).
- Added `booking_reference` column to `bookings` table.
- Test Suite Results:
  - `BookingEngineApiTest` $\rightarrow$ **PASS (1 test, 6 assertions)**
  - `PricingEngineApiTest` $\rightarrow$ **PASS (1 test, 8 assertions)**
  - `AvailabilityEngineApiTest` $\rightarrow$ **PASS (1 test, 3 assertions)**
  - `BookingWorkflowTest` $\rightarrow$ **PASS (2 tests, 2 assertions)**
