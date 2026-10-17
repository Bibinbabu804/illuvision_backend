# Sprint 7 – Business Intelligence & Analytics Documentation

**Project:** Illuvision DOOH Asset Platform  
**Sprint:** Sprint 7  
**Date:** July 21, 2026  

---

## Overview

Sprint 7 established the enterprise Business Intelligence (BI) and Analytics module across `/Users/apple/my-works/illuvision/backend` (Laravel 12) and `/Users/apple/my-works/illuvision/admin` (React/Vite), transforming raw operational data into strategic decision dashboards.

```
+------------------------------------------------------------------------------------+
|                                Admin BI Hub UI                                     |
|  Executive Dashboard | Revenue | Bookings | Screens | Cities | Campaigns | Exporter    |
+------------------------------------------------------------------------------------+
                                           |
                                           v
+------------------------------------------------------------------------------------+
|                              Analytics REST APIs                                   |
|   GET /reports/overview   GET /reports/revenue    GET /reports/bookings            |
|   GET /reports/screens    GET /reports/cities     GET /reports/campaigns           |
|   GET /reports/screen-providers   GET /reports/categories  GET /reports/export       |
+------------------------------------------------------------------------------------+
                                           |
                                           v
+------------------------------------------------------------------------------------+
|                              Analytics Engine Layer                                |
|   ReportService | RevenueAnalyticsService | BookingAnalyticsService                 |
|   ScreenAnalyticsService | ClientAnalyticsService | PaymentAnalyticsService         |
+------------------------------------------------------------------------------------+
```

---

## Key Modules & Features Implemented

### 1. Unified Analytics Engine (`ReportService.php`)
- Aggregates operational datasets across all platform domains:
  - Executive KPIs (`total_revenue`, `revenue_growth`, `total_bookings`, `active_campaigns`, `pending_approvals`, `average_occupancy`).
  - Revenue trends, booking volume distributions, screen utilization metrics, and city revenue breakdowns.

### 2. Analytics REST Endpoints (`ReportController.php`, `api.php`)
- REST APIs under `/reports/*`:
  - `GET /reports/overview`: Executive dashboard KPIs & charts.
  - `GET /reports/revenue`: Daily/monthly revenue trends & referral impact.
  - `GET /reports/bookings`: Status distribution, approval metrics, and volume trends.
  - `GET /reports/screens`: Screen occupancy rates and performance rankings.
  - `GET /reports/cities`: Geographic revenue and per-screen yield.
  - `GET /reports/campaigns`: Active/completed campaign metrics and average durations.
  - `GET /reports/screen-providers`: Provider revenue splits and settlement status.
  - `GET /reports/categories`: Industry advertisement category distribution.
  - `GET /reports/referrals`: Partner referral code performance.
  - `GET /reports/rewards`: IlluPoints loyalty program liability and redemption rates.
  - `GET /reports/export`: CSV and JSON report exporter.

### 3. Admin Reports Hub (`admin/src/pages/Reports/`)
- 11 dedicated reporting & analytics views: `Overview.jsx`, `RevenueReports.jsx`, `BookingReports.jsx`, `CampaignAnalytics.jsx`, `ScreenPerformance.jsx`, `CityPerformance.jsx`, `ScreenProviderReport.jsx`, `AdCategoriesReport.jsx`, `ReferralPartnerReports.jsx`, `ExportCenter.jsx`.

---

## Verification & Build Matrix

| Verification Target | Command / Path | Result |
| :--- | :--- | :--- |
| **Backend Feature Tests** | `php artisan test --filter=ReportApiTest` | ✅ **PASS (4 passing tests, 8 assertions)** |
| **Full Backend Test Suite** | `php artisan test` in `backend/` | ✅ **PASS (45 passing tests, 238 assertions)** |
| **Admin Production Build** | `npm run build` in `admin/` | ✅ **SUCCESS (Built in 6.96s, 0 errors)** |
| **Technical Documentation** | `backend/docs/sprint_7_bi_analytics.md` | ✅ Documented |
