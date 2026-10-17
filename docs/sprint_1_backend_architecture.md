# Sprint 1 – Backend Architecture & Domain Foundation Documentation

**Project:** Illuvision DOOH Asset Platform  
**Sprint:** Sprint 1  
**Date:** July 21, 2026  

---

## Architecture Summary

In Sprint 1, the Illuvision backend was transformed into an extensible, multi-asset DOOH platform without breaking legacy fixed-screen functionality or database state.

```
                                  +-----------------------+
                                  |     REST APIs (v2)    |
                                  +-----------------------+
                                              |
                                              v
                                  +-----------------------+
                                  |   AssetController     |
                                  +-----------------------+
                                              |
                                              v
                                  +-----------------------+
                                  |     AssetService      |
                                  +-----------------------+
                                              |
                                              v
                                  +-----------------------+
                                  |   AssetRepository     |
                                  +-----------------------+
                                              |
                                              v
                                  +-----------------------+
                                  |    Screen (Asset)     |
                                  |    CoverageCity       |
                                  +-----------------------+
```

---

## Delivered Components

### 1. Database Schema Extension
- **`screens` Table**: Extended with `asset_type`, `pricing_unit`, `fleet_size`, `working_hours_pd`, `travel_distance_km_pd`, `display_specs`, `asset_metadata`. Made `city_id`, `latitude`, `longitude` nullable for mobile assets.
- **`asset_coverage_cities` Table**: Pivot table mapping assets to multiple primary and secondary coverage cities.
- **`booking_screens` Table**: Added `quantity`, `unit_price`, `line_subtotal`, `provider_commission`, `platform_commission`.

### 2. Domain Enums
- `AssetType`: `fixed`, `mobile_van`, `mobile_truck`, `backpack_team`, `walking_promo`, `event_display`.
- `PricingUnit`: `per_screen`, `per_vehicle`, `per_team`, `per_event`.
- `BookingStatus`: `draft`, `pending_approval`, `approved`, `rejected`, `scheduled`, `running`, `completed`, `cancelled`.
- `AssetStatus`, `ApprovalStatus`, `ProviderStatus`, `SettlementStatus`, `NotificationType`, `MediaType`, `PaymentStatus`, `UserRole`.

### 3. Core Domain Models & Scopes
- `Screen` model extended with `coverageCities()`, `bookingItems()`, `is_mobile` accessor, `scopeFixed()`, `scopeMobile()`, `scopeForCity()`.
- `CoverageCity` model created.
- `BookingScreen` model updated for multi-unit quantities and pricing snapshots.

### 4. Repository & Service Layer
- `AssetRepositoryInterface` / `AssetRepository`
- `BookingRepositoryInterface` / `BookingRepository`
- `AssetService`: Handles Asset lifecycle and coverage city synchronization.
- `CoverageService`: Manages city coverage queries.
- `InventoryService`: Handles mobile fleet reservation calculation and availability validation across date ranges.

### 5. DTOs & Validation
- `AssetDTO`, `CreateBookingDTO`.
- Form Requests: `CreateAssetRequest`, `UpdateAssetRequest`.
- Policy: `AssetPolicy`.

### 6. REST API Endpoints (v2)
- `GET /api/v2/assets` – Filterable paginated list of assets.
- `POST /api/v2/assets` – Create a new asset with coverage cities.
- `GET /api/v2/assets/{id}` – Asset detail response with coverage cities.
- `PUT /api/v2/assets/{id}` – Update asset specifications.
- `DELETE /api/v2/assets/{id}` – Delete asset.
- `POST /api/v2/assets/{id}/coverage` – Sync multi-city coverage.
- `GET /api/v2/coverage/cities/{cityId}` – Fetch assets covering a target city.

### 7. Events, Observers & Seeders
- `AssetCreated` event & `AssetObserver` auto-generating asset codes (`AST-XXXXX`).
- `AssetSeeder`: Seeds sample LED Van fleets and Backpack Advertising teams.
- `AssetFactory`: Enables fluent testing setup.

---

## Verification & Test Results

- Executed migrations safely (**Zero `migrate:fresh` or DB truncations used**).
- `AssetSeeder` executed successfully.
- `AssetApiTest` (Feature) $\rightarrow$ **2 Passed (79 assertions)**.
- `InventoryServiceTest` (Unit) $\rightarrow$ **1 Passed**.
