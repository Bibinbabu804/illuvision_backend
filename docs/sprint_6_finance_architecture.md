# Sprint 6 – Finance & Business Operations Documentation

**Project:** Illuvision DOOH Asset Platform  
**Sprint:** Sprint 6  
**Date:** July 21, 2026  

---

## Overview

Sprint 6 implemented the financial management layer powering payments, provider settlements, platform commissions, double-entry accounting ledgers, refunds, and financial reporting across `/Users/apple/my-works/illuvision/backend` (Laravel 12) and `/Users/apple/my-works/illuvision/admin` (React/Vite).

```
+------------------------------------------------------------------------------------+
|                                 Admin Finance UI                                   |
|       Gross Revenue | Provider Payouts | Platform Take | Accounting Ledger          |
+------------------------------------------------------------------------------------+
                                           |
                                           v
+------------------------------------------------------------------------------------+
|                             Finance v2 REST APIs                                   |
|   GET /api/v2/finance/summary          GET /api/v2/finance/payments                |
|   GET /api/v2/finance/settlements       POST /api/v2/finance/settlements/batch     |
|   GET /api/v2/finance/ledger                                                       |
+------------------------------------------------------------------------------------+
                                           |
                                           v
+------------------------------------------------------------------------------------+
|                              Finance Engine Services                               |
|   FinanceService | PaymentService | SettlementService | CommissionService           |
+------------------------------------------------------------------------------------+
```

---

## Key Modules & Features Implemented

### 1. Financial Orchestration Service (`FinanceService.php`)
- Unified service orchestrating payments, provider payouts, platform commission calculations, batch settlement state machine transitions, and activity log audit trails.

### 2. Finance REST Controller & Endpoints (`FinanceController.php`, `api.php`)
- Exposes v2 REST endpoints:
  - `GET /api/v2/finance/summary`: Aggregates gross revenue, payments received, provider settlements paid, pending queues, platform commissions, and refunds issued.
  - `GET /api/v2/finance/payments`: Paginated list of customer payment records with status filtering.
  - `GET /api/v2/finance/settlements`: Paginated list of provider settlement statements with batch filtering.
  - `POST /api/v2/finance/settlements/batch`: Bulk transition settlement status (`approved`, `scheduled`, `processing`, `paid`, `rejected`, `cancelled`) with payment references and audit logs.
  - `GET /api/v2/finance/ledger`: Real-time double-entry accounting ledger combining income (customer campaign payments) and expenses (provider payouts).

### 3. Admin Financial Operations Dashboard (`FinanceOverview.jsx`, `financeApi.js`)
- Located at `/finance` in the admin portal.
- Displays live KPI cards (Gross Revenue, Provider Payouts, Platform Commission, Refunds Issued) and accounting ledger entries.

---

## Verification & Build Matrix

| Verification Target | Command / Path | Result |
| :--- | :--- | :--- |
| **Backend Automated Unit & Feature Tests** | `php artisan test` in `backend/` | ✅ **PASS (43 passing tests, 230 assertions)** |
| **Admin Production Build** | `npm run build` in `admin/` | ✅ **SUCCESS (Built in 6.84s, 0 errors)** |
| **Finance API Feature Test** | `php artisan test --filter=FinanceApiTest` | ✅ **PASS (2 passing tests, 14 assertions)** |
| **Technical Documentation** | `backend/docs/sprint_6_finance_architecture.md` | ✅ Documented |
