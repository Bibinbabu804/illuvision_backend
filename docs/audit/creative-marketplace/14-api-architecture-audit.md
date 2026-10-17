# 14 — Creative Marketplace API Architecture Audit

**Generated:** 2026-09-26
**Scope:** READ-ONLY audit of the HTTP/API conventions a proposed "Creative Marketplace" bounded context would have to follow, followed by a recommended API structure. **No Creative code exists** — 0 classes, 0 routes, 0 tables, 0 migrations, 0 tests. **No route, controller, request, resource or middleware was created.** The endpoint table in §10 is a proposal only.
**Method:** direct source inspection of `routes/`, `app/Http/`, `bootstrap/app.php`, `config/`, cross-checked with `php artisan route:list --json`. Prior `docs/audit/*` artifacts were treated as evidence, not truth.

## Legend

| Tag | Meaning |
|---|---|
| `[CURRENT FACT]` | Verified from source on 2026-09-26, with `file:line` or command evidence. |
| `[ARCHITECTURE DOCUMENT]` | Normative text from `AGENTS.md` or `docs/architecture/ARCHITECTURE_RULES.md`. |
| `[INFERENCE]` | Reasonably inferred from the implementation or from relationships between artefacts. |
| `[RECOMMENDATION]` | Proposed target design. Not implemented. No route was written. |
| `[OPEN DECISION]` | Cannot be determined from available evidence. The missing information is stated exactly. |

---

## 1. Executive summary

`[CURRENT FACT]` The API is a **single Laravel `api` middleware group, 380 of 385 routes under `/api/`, with no version prefix on 95% of it**, authorization applied as `permission:*` middleware groups on 240 routes and `auth:sanctum` on 300, response envelopes built inline by four helpers in an abstract base controller, validation inline in 57 controllers, and **zero** API Resources on most surfaces.

`[CURRENT FACT]` Five properties of the current API matter enormously to a Creative design, and none of them is visible from a single file:

1. **Versioning is the exception, not the rule.** 19 routes carry `/v2`; **0** carry `/v1`; 366 carry nothing.
2. **Routes are keyed by integer primary key.** 384 of 385 route parameters are model-bound integer ids or an `id`. There is no public identifier anywhere except one `slug` on a QR redirect.
3. **Four different response envelopes are in simultaneous production use** (§6), not one.
4. **Validation is inline** — 134 `validate(` calls across 57 controllers against only 5 FormRequests.
5. **Rate limiting is one named limiter on one route** (§10.4), and **idempotency does not exist** (§10.5).

`[RECOMMENDATION]` Do **not** create `/api/v1/creative/...`. §9 sets out the justification against the observed conventions, states every point of deviation explicitly, and recommends `/api/creative/...` — which deviates from the `v2` minority in a way that is deliberate and stated.

---

## 2. URL structure

### 2.1 The measured shape of all 385 routes

`[CURRENT FACT]` From `php artisan route:list --json` (total: **385**):

```text
first path segment
  api .......................... 380
  (empty) ......................   1
  qr ...........................   1     routes/web.php:9   /qr/{slug}
  sanctum ......................   1     (framework)
  storage ......................   1     (framework)
  up ...........................   1     (framework health)
```

`[CURRENT FACT]` Second-level segments under `/api` (top 30 of 84 distinct):

```text
/admin ................ 73      /partner .............. 19      /blogs ............ 9
/bookings ............. 28      /settings .............. 19      /profile .......... 9
/v2 ................... 19      /partners .............. 15      /referral-analytics  9
                                              /screen-provider .... 14      /clients ......... 8
                                              /reports ............. 11      /payments ........ 8
```

`[INFERENCE]` The dominant pattern is **flat, unversioned, resource-named**: `/api/bookings`, `/api/screens`, `/api/payments`. There is exactly one aggregation segment (`/api/admin`, 73 routes) that signals "operational back office" purely by URL, and two portal segments (`/api/partner`, 19; `/api/screen-provider`, 14) that signal "a specific actor's self-service surface". **That is the closest existing precedent for a Creative surface**: `/api/creative` is a portal segment for a specific actor class, exactly like `/api/partner`.

### 2.2 Version prefixes

`[CURRENT FACT]` Counted from the route table:

```text
routes containing a /vN/ segment .................... 19  ( 4.9% of 385 )
routes containing /v1/ ..............................  0
routes containing /v2/ .............................. 19
   of which /v2/finance/ ...........................  5
routes with NO version segment ..................... 366  ( 95.1% )
```

`[CURRENT FACT]` The 19 versioned routes, in full:

```text
GET|HEAD   api/v2/assets                     POST  api/v2/assets/{id}/coverage
POST       api/v2/assets                     GET   api/v2/availability/check
GET|HEAD   api/v2/assets/{id}                GET   api/v2/bookings
PUT        api/v2/assets/{id}                GET   api/v2/bookings/{id}
DELETE     api/v2/assets/{id}                POST  api/v2/bookings/{id}/transition
GET|HEAD   api/v2/coverage/cities/{cityId}   GET   api/v2/recommendations
GET|HEAD   api/v2/bookings                   POST  api/v2/pricing/calculate
GET|HEAD   api/v2/finance/ledger             POST  api/v2/finance/settlements/batch
GET|HEAD   api/v2/finance/payments           GET   api/v2/finance/summary
GET|HEAD   api/v2/finance/settlements
```

`[ARCHITECTURE DOCUMENT]` `docs/architecture/ARCHITECTURE_RULES.md:35` (Rule 10) refers to *"legacy V2 multi-asset endpoints"* being isolated behind `app/Application/*/Adapters`, and `AGENTS.md` §19 records that these legacy engines are "held back on purpose". `[INFERENCE]` **`/v2` is not a versioning programme in this repository — it is the label of one specific legacy multi-asset surface.** `Rule 10` uses the word "legacy" explicitly. Adopting `/v2` or `/v1` for a brand-new context would collide with that vocabulary.

### 2.3 Controllers and route-to-controller mapping

`[CURRENT FACT]` `app/Http/Controllers` holds **50** controller classes flat at the root, **18** under `Controllers/Api/`, and **3** under `Controllers/Auth/`.

`[CURRENT FACT]` Both routing styles are in use and they are not equivalent:

| Style | Example | Behaviour |
|---|---|---|
| Implicit route-model binding | `api/admin/manual-bookings/{booking}` → `AdminManualBookingController` | Parameter is the model's route key (the integer id). A missing row yields Laravel's default 404 HTML/JSON. |
| Explicit `int $id` | `api/v2/bookings/{id}` → `Api\BookingEngineController::show(int $id)` (`app/Http/Controllers/Api/BookingEngineController.php:114`) | Controller calls `findOrFail($id)` itself. |

`[CURRENT FACT]` `Route::apiResource(...)` / `Route::resource(...)` appears **15 times** in `routes/api.php`. `[INFERENCE]` `apiResource` is used mostly for back-office/content CRUD (`gallery`, `qr-codes`), not for the transactional surfaces, which use explicit `Route::post/get` pairs with hand-written controller methods (bookings, payments, settlements, partners). `[RECOMMENDATION]` Creative follows the explicit style for anything transactional or stateful, because a Creative transition is a business action, not a CRUD update — and because `apiResource`'s `DELETE` maps to a destructive semantic that no Creative lifecycle should expose generically.

### 2.4 Public vs authenticated surfaces

`[CURRENT FACT]` **300 of 385** routes sit behind `auth:sanctum` or the `web` group. **85 do not.** Of those 85:

- **16 carry a `permission:*` middleware but no auth middleware** — every one is `/api/v2/*`. `[INFERENCE]` On the v2 surface the permission middleware *is* the authentication gate, which is why `PermissionMiddleware` has to emit its own 401 (`app/Http/Middleware/PermissionMiddleware.php:20-25`).
- **69 carry neither.** These are the genuinely public endpoints: auth (`/api/login`, `/api/register`, `/api/auth/google`, `/api/forgot-password`, `/api/reset-password`, `/api/partner/setup-password`, `/api/screen-provider/setup-password`), catalogue reads (`/api/screens`, `/api/screen-types`, `/api/products`, `/api/pricing-plans`, `/api/countries`, `/api/currencies`, `/api/currency/*`, `/api/blogs`, `/api/faqs`, `/api/jobs`, `/api/testimonials`, `/api/announcements`, `/api/achievements`, `/api/gallery`, `/api/advertisement-categories`, `/api/categories`, `/api/screen-categories`, `/api/settings/public-booking-config`), quote calculators (`/api/campaigns/calculate`, `/api/campaigns/validate`, `/api/pricing-plans/preview`, `/api/recommendations/calculate`, `/api/v2/pricing/calculate`, `/api/v2/availability/check`), and public lead capture (`/api/enquiries`, `/api/contact-enquiries`, `/api/product-enquiries`, `/api/partner-applications`, `/api/screen-provider-enquiries`, `/api/job-applications`, `/api/referral-codes/validate`).

`[CURRENT FACT]` **Several of those 69 are unauthenticated writes**, which is a pre-existing security exposure and out of scope here: `POST api/gallery` / `PUT|PATCH api/gallery/{gallery}` / `DELETE api/gallery/{gallery}` (declared bare at `routes/api.php:703`, outside every auth group), `POST api/blogs`, `POST api/screen-categories` (`routes/api.php:217`), `POST api/admin/screen-categories` (`routes/api.php:218`), and three admin asset-import endpoints (`api/admin/assets/import/{confirm,template,validate}`).

`[INFERENCE]` Recorded because it is the reason a Creative reviewer must read the middleware of every proposed route rather than assume it: in this codebase "the route is under `/api`" carries no authorization meaning at all.

### 2.5 Public identifiers

`[CURRENT FACT]` Every route parameter name, with counts:

```text
id ............................... 29     booking ......... 26     screenProvider ... 12
partner ......................... 12     screenProviderEnquiry 7   role ............. 6
contactEnquiry ..................  5     settlement ......  5     user ............. 5
screen .........................  5     blog ............  4     media ............  4
country ........................  4     pricingPlan .....  4     advertisement_category 3
announcement ...................  3     author ..........  3     category ......... 3
faq ............................  3     gallery .........  3     jobPost .......... 3
qr_code ........................  3     referral_code ...  3     success_story .... 3
testimonial ....................  3     blog_tag ........  2     enquiry ..........  2
jobApplication .................  2     bookingId .......  1     client ...........  1
state .........................  1     cityId ..........  1     slug .............  1
path ...........................  1
```

`[CURRENT FACT]` **384 of 385 parameters are integer primary keys or the model's default route key.** The single exception is `slug`, and it is on the only non-API, non-authenticated application route in the system: `routes/web.php:9`, `Route::get('/qr/{slug}', [QrCodeController::class, 'redirect'])->name('qr.redirect')`.

`[INFERENCE]` **There is no public-identifier convention in this API.** No UUID, no ULID, no prefixed reference is used in any route.

`[CURRENT FACT]` `docs/audit/creative-marketplace/09-creative-database-target-schema-blueprint.json:16` proposes `public_id` `char(26)` (ULID) or `char(36)` (UUID), UNIQUE and INDEXED, *"used in all API routes"*, while explicitly labelling itself a `NEW CONVENTION for Creative`. `[CURRENT FACT]` That is a genuine, deliberately-marked deviation from the current convention, not a claim about the current convention. See §9.3 and D-API-02.

---

## 3. Authentication

`[CURRENT FACT]` `bootstrap/app.php:11-16` registers a single routing surface: `web: routes/web.php`, `api: routes/api.php`, `commands: routes/console.php`, `health: /up`. `[CURRENT FACT]` `routes/api.php` applies `auth:sanctum` **6 times** as an explicit `Route::middleware('auth:sanctum')->group(...)` — the largest group opens at `routes/api.php:257` and encloses the great majority of the back-office surface.

`[CURRENT FACT]` The `api` middleware group itself is **not** customised: `bootstrap/app.php:17-21` contains only the `permission` alias (`'permission' => PermissionMiddleware::class`) and no `appendToGroup`, no `$middleware->api(...)`, no `throttle` addition. `[INFERENCE]` So the `api` group carries only Laravel 11's stock `SubstituteBindings` + `throttle:api` behaviour from the framework default... except that `[CURRENT FACT]` no `throttle` middleware string appears anywhere in `routes/api.php` except the one named limiter at `routes/api.php:240`, and `bootstrap/app.php` does not re-declare the api group. In Laravel 11 the `api` group is defined by the framework as `[]` (bindings only) unless the application appends to it, so **no default API throttle is in effect**. This is confirmed empirically in §10.4.

`[CURRENT FACT]` Token behaviour: `config/sanctum.php:36` `'guard' => ['web']`; `config/sanctum.php:50` `'expiration' => null`; `config/sanctum.php:16-20` stateful domains include `localhost:3000` (SPA cookie auth) — and `config/auth.php:39` defines only the `web` session guard, with no `sanctum` key. `[INFERENCE]` Both a browser session cookie and a long-lived bearer token are valid on the same route.

---

## 4. Authorization middleware

`[CURRENT FACT]` Full middleware census over 385 routes:

```text
auth:sanctum (or web) ................................. 300
permission:* (any) .................................... 240
permission:* WITHOUT auth ..............................  16   (all /api/v2/*)
neither ...............................................  69
distinct permission strings used ......................  62
```

`[CURRENT FACT]` `app/Http/Middleware/` contains exactly one class. `permission` is its only alias (`bootstrap/app.php:18-20`). The middleware semantics — 401 body, 403 body, the super-admin bypass, and the conjunctive "all listed permissions required" loop — are documented in full in artifact 13 §2.6 and are not repeated here.

`[CURRENT FACT]` Grouping style: `Route::middleware('permission:X')->group(function () { ... })` is used **~35 times**, wrapping one or more explicit routes or a single `apiResource`. `[INFERENCE]` Permission is declared per group, not per route, and the group is the unit of reuse. `[CURRENT FACT]` One route uses an **unregistered** `admin` alias: `routes/api.php:717`, `Route::middleware(['auth:sanctum', 'admin'])`; `app("admin")` throws `BindingResolutionException` (verified). Recorded in artifact 13 §6.9 and D12; not fixed here.

`[CURRENT FACT]` 24 seeded permissions are referenced by **no** route, and 3 route-referenced permissions (`settlements.view` / `.approve` / `.pay`) are granted to **no** role. Full analysis in artifact 13 §4.

---

## 5. Validation and Resources

### 5.1 FormRequests: 5

`[CURRENT FACT]` `app/Http/Requests/` contains exactly five classes:

```text
CreateAssetRequest.php    PricingPlanRequest.php    RoleRequest.php
SettingsRequest.php       UpdateAssetRequest.php
```

`[CURRENT FACT]` Only **3** of the 5 are actually used by a controller — `PricingPlanController.php:47,74` and `Api/RoleController.php:32`. `[CURRENT FACT]` `CreateAssetRequest` and `UpdateAssetRequest` have **no** controller reference outside `app/Http/Requests` itself.

`[CURRENT FACT]` Meanwhile there are **134** `->validate(` calls across **57** controller files. `[INFERENCE]` Roughly 97% of endpoint validation is declared inline inside a controller method body, using raw rule strings.

`[CURRENT FACT]` Rules are written as inline arrays with a few named conventions visible across the surface: `exists:bookings,id` (`PaymentController.php:42`), `Rule::in(Role::pluck('slug')->toArray())` (`Api/AdminUserController.php:61,105`), `Rule::unique('partners','phone')->whereNot('status','rejected')` (`PartnerApprovalController.php:84`), and `max:255` appearing 154 times. `[INFERENCE]` There is no shared rule vocabulary object; the conventions are per-controller folklore.

### 5.2 Resources: 7

`[CURRENT FACT]` `app/Http/Resources/` contains exactly seven classes:

```text
AdminUserResource.php   AssetResource.php      MapAssetResource.php
PricingPlanResource.php RoleResource.php       SettingsResource.php
UserResource.php
```

`[CURRENT FACT]` **70 of 71 controller classes return none of them.** `[CURRENT FACT]` The controllers that do use a Resource are the ones under `Controllers/Api/` that mirror a FormRequest: `Api\RoleController` (`RoleResource::collection($roles)` at `:21`, `new RoleResource($role)` at `:26,32,52,58`), plus `Api\AdminUserController` and `Api\AssetController`.

`[INFERENCE]` **The dominant convention is: the controller returns the Eloquent model, the `toArray()` of the paginator, or a hand-assembled array, directly.** `[CURRENT FACT]` `Api\BookingEngineController.php:36` returns a raw `LengthAwarePaginator`; `:101-104` returns the model; `:118-120` returns `['data' => $booking]`; `:132-135` returns `['message' => ..., 'data' => $updated]`. `[INFERENCE]` This means **response field names are currently dictated by `protected $fillable` and `protected $casts` on each model.** A Creative model whose `$fillable` changes would change an API contract without any HTTP-layer edit — a direct hazard for `AGENTS.md` §13 (API contract preservation).

`[RECOMMENDATION]` Creative should use FormRequest + JsonResource for every endpoint. This is a deliberate deviation from the dominant convention and it is stated as such in §9.2 — justified because Creative exposes commercially sensitive records and because the `$fillable`-driven contract is not a contract at all.

---

## 6. Response envelopes — the actual shapes

`[CURRENT FACT]` `app/Http/Controllers/Controller.php` is an abstract base class (69 lines) with four protected helpers, all producing `Illuminate\Http\JsonResponse`:

| Helper | Lines | Emitted body | Status |
|---|---|---|---|
| `successResponse($data, $message, $status, $extra)` | `:10-23` | `{"status": true, "message": ..., "data": ...}` (+ `array_merge`d `$extra`) | caller-supplied, default 200 |
| `paginatedResponse($paginator, $message, $status, $extra)` | `:25-46` | `{"status": true, "message": ..., "data": [items], "meta": {total, per_page, current_page, last_page, from, to}}` | default 200 |
| `errorResponse($message, $status, $errors)` | `:48-63` | `{"status": false, "message": ...}` plus `"errors"` **only when `$errors !== null`** | caller-supplied, default 400 |
| `noContentResponse()` | `:65-68` | `null` | 204 |

### 6.1 Sampled controllers across features

Six features sampled, plus the two non-controller envelope sources. **Four distinct envelopes are in live production use.**

| # | Controller | Endpoint | Line | Actual body shape | Envelope |
|---|---|---|---|---|---|
| 1 | `BookingController` | `POST /api/bookings` | `:107` | `successResponse($booking, 'Campaign booking created. Proceed to payment.', 201)` | A `{status,message,data}` |
| 2 | `BookingController` | `GET /api/bookings` | `:433` | `paginatedResponse($bookings, 'Bookings fetched successfully')` | A `{status,message,data,meta}` |
| 3 | `BookingController` | `POST /api/bookings/{booking}/media` | `:146` | `successResponse($uploaded, 'Media uploaded successfully', 201)` where each item is `$media->toArray()` merged with `['url' => ...]` | A, model-array data |
| 4 | `BookingController` | errors | `:156,315,321,455` | `errorResponse('Media does not belong to this booking.', 422)`, `errorResponse('Booking is not in a reviewable status.', 422)`, `errorResponse('Screen not found', 404)` | A `errorResponse` |
| 5 | `BookingController` | **ad-hoc ownership** | `:116-118` | `if ($booking->user_id !== $request->user()->id) return $this->errorResponse('Unauthorized', 403);` | A, **403 for an ownership failure** |
| 6 | `PaymentController` | `POST /api/payments/...` | `:63,97,117,150` | `successResponse([...])`; failure at `:154` is `errorResponse('Refund failed: '.$e->getMessage(), 500)` — **raw exception message leaked to the client** | A |
| 7 | `PartnerPortalController` | `GET /api/partner/...` | `:29,63,82,99,124,203,248` | `successResponse(...)` throughout; `:159` `errorResponse('Partner not found or access denied.', 403)`; `:145` `errorResponse('Invalid or expired token.', 400)` | A |
| 8 | `GalleryController` | `api/gallery` | `:21,48,53,66,78` | `paginatedResponse($galleries, ...)`; `successResponse($createdItems, 'Gallery created successfully', 201)`; delete returns `successResponse($gallery, 'Gallery deleted successfully')` — **200 with a body, not 204** | A |
| 9 | `ReportController` | 11 report endpoints | `:27,40,53,66,79,92,122,141,154,167,206` | `successResponse($data, '<X> report fetched successfully')` — identical envelope, 11× | A |
| 10 | `Api\BookingEngineController` | `GET /api/v2/bookings` | `:36` | `response()->json($bookings)` — **raw Laravel paginator**, i.e. `{data, links:{first,last,prev,next}, meta:{current_page,from,last_page,path,per_page,to,total}}` | **C** |
| 11 | `Api\BookingEngineController` | `POST /api/v2/bookings` | `:108-111` | `{"message": "Booking created successfully.", "data": {...}}` — **no `status` key** | **B** |
| 12 | `Api\BookingEngineController` | `GET /api/v2/bookings/{id}` | `:118-120` | `{"data": {...}}` — **no `status`, no `message`** | **B** |
| 13 | `Api\BookingEngineController` | `POST /api/v2/bookings/{id}/transition` | `:132-135` | `{"message": "Booking status updated to {$updated->status->value}.", "data": {...}}` | **B** |
| 14 | `App\Domain\Booking\Exceptions\InvalidBookingStateException::render()` | `:11-18` | `{"error": "Invalid Booking State Transition", "message": ..., "status": 422}` | **D** — a fourth shape, from a domain exception |
| 15 | `PermissionMiddleware` | unauthenticated / forbidden | `:21-24, :34-37` | `{"status": false, "message": "Unauthenticated."}` / `{"status": false, "message": "Forbidden. You do not have the required permission: X"}` | A `errorResponse`-shaped |

`[CURRENT FACT]` **Envelope D (item 14) is confirmed at runtime.** `bootstrap/app.php:22-24` registers an **empty** `withExceptions` closure, so Laravel's default handler is in force. Rendering an `Illuminate\Validation\ValidationException` through the framework handler for a JSON request produces exactly:

```json
{"message":"The a field is required.","errors":{"a":["The a field is required."]}}
```

with HTTP **422** and **no `status` key**. `[CURRENT FACT]` This is corroborated by 56 `assertJsonValidationErrors` / `assertStatus(422)` assertions in `tests/`.

**Summary of envelopes in production on 2026-09-26:**

```text
A  {"status":true|false, "message", ["data"], ["meta"], ["errors"]}   majority — base controller + middleware
B  {"message", ["data"]}                                                the /api/v2 surface
C  raw Eloquent model / raw LengthAwarePaginator                        /api/v2 reads
D  {"error", "message", "status"}                                       InvalidBookingStateException
E  {"message", "errors"}  (422)                                         ALL framework validation failures
```

`[INFERENCE]` Five shapes, not one. `[RECOMMENDATION]` Creative must pick **exactly one** and hold it across every endpoint, including its own validation failures. §9.1 states which one and why.

---

## 7. Error and status-code conventions

`[CURRENT FACT]` `errorResponse(...)` explicit status codes across `app/Http/Controllers`:

```text
422  35 occurrences      403  31      500  19      404  16      400  8
```

`[CURRENT FACT]` `successResponse(..., 201)` — **38 occurrences** across 36 controller files. `[CURRENT FACT]` `noContentResponse()` (204) is defined at `Controller.php:65-68` and used by `[INFERENCE]` at most a handful of places; `GalleryController.php:78` returns 200 with a body on delete instead.

`[CURRENT FACT]` There is **no** 409 (conflict) anywhere. `[CURRENT FACT]` There is **no** 401 emitted by any controller — 401 comes only from `PermissionMiddleware.php:24`.

`[CURRENT FACT]` **19 sites return HTTP 500 with a concatenated exception message**, e.g. `errorResponse('Failed to create payment order: ' . $e->getMessage(), 500)` (`PaymentController.php:71`) and `errorResponse('Refund failed: ' . $e->getMessage(), 500)` (`:154`). `[INFERENCE]` Internal error detail is disclosed to API clients on this surface.

`[INFERENCE]` The de-facto status grammar is therefore:

```text
200  read / update / delete-with-body
201  anything that created a row (38 sites, consistent)
204  defined but rarely used
400  malformed or business-rule precondition failed (e.g. "Invalid or expired token.")
403  authenticated but not permitted — INCLUDING ownership failures and super-admin-bypass denials
404  resource not found
422  business rule violated (wrong state, does not belong, not in reviewable status)
500  unexpected error, message included
```

`[ARCHITECTURE DOCUMENT]` `AGENTS.md` §12: *"Unauthenticated → 401; Authenticated, unauthorized → 403"* — and this must be reported separately. `[INFERENCE]` The current code honours this in the middleware but **conflates 403 with 404 in spirit** (e.g. `PartnerPortalController.php:159` returns 403 for "Partner not found or access denied", which is deliberately ambiguous).

`[RECOMMENDATION]` Creative follows the same grammar, with one addition: Creative should return **404 for a resource the actor may not see** and **403 for a resource the actor may see but may not act on**, rather than the current 403-for-everything. Justified by `09-...json:16`, which records that there is no policy layer to catch IDOR on sequential ids. Stated as a deviation in §9.2.

---

## 8. Pagination conventions

`[CURRENT FACT]` Every list endpoint uses `LengthAwarePaginator`. `[CURRENT FACT]` **Zero** uses of `simplePaginate()` or `cursorPaginate()` anywhere in `app/`.

`[CURRENT FACT]` The query-parameter name is **not** consistent. Occurrences in `app/Http/Controllers/`:

```text
12 ×  $perPage = $request->input('per_page', …)      1 ×  $request->query('per_page', …)
11 ×  $perPage = $request->per_page ?? 10             1 ×  $request->integer('per_page', …)
11 ×  $perPage = $request->input('perPage', …)       1 ×  $request->per_page ?? 12
 7 ×  $perPage = (int) …
 2 ×  $perPage = min((int) …                        (explicit upper clamp)
```

`[CURRENT FACT]` **Three spellings coexist: `per_page`, `perPage`, and a default-only form.** `[CURRENT FACT]` Defaults in use: 10 (×7), 15 (×11), 20, 25, 500, 12. `[INFERENCE]` `per_page` is the plurality spelling and matches the `meta.per_page` key emitted by `Controller.php:33`.

`[CURRENT FACT]` 53 call sites of `->paginate($perPage)`; 2 of `paginate($request->input('per_page', 15))`; 1 of `paginate($request->get('per_page', 15))`; 1 of `paginate($perPage, ['*'], 'page', $page)`; 1 of `paginate($filters['per_page'] ?? 15)`.

`[INFERENCE]` `page` is the universal page-parameter name. `[CURRENT FACT]` But the **response** metadata key is not universal either: `Controller.php:32-37` emits `current_page`, while Laravel's raw paginator emits `current_page` too — so those agree. What does not agree is the presence of the whole `meta` block (absent on the raw-paginator `/api/v2` surface, item 10 above).

`[RECOMMENDATION]` Creative uses `per_page` (plurality spelling, matches `meta.per_page`), default 15 (modal default in the current code), a hard clamp, and the `Controller::paginatedResponse` `meta` block so the Creative response is a strict subset of envelope A.

---

## 9. Recommended Creative API structure

### 9.1 Path prefix: `/api/creative/...`, not `/api/v1/creative/...`

`[RECOMMENDATION]` **Use `/api/creative/...`.** No version segment.

Justification against the observed conventions:

1. `[CURRENT FACT]` **95.1% (366/385) of routes carry no version segment.** A new context that is the only `/v1` in the repository makes the version prefix mean "the Creative context" rather than "version 1 of the contract", which is a semantic collision a future reader will get wrong.
2. `[ARCHITECTURE DOCUMENT]` `ARCHITECTURE_RULES.md:35` (Rule 10) calls the existing `/v2` surface *"legacy V2 multi-asset endpoints"* and requires it to be *retired only after* product authorization and consumer migration. `[INFERENCE]` `/v2` is a legacy label with a retirement plan attached. Reusing a version vocabulary for a greenfield context attaches Creative to that plan by accident.
3. `[CURRENT FACT]` The closest structural precedent for a Creative surface is not a versioned asset API — it is a **portal segment**: `/api/partner` (19 routes) and `/api/screen-provider` (14 routes), each of which is a per-actor self-service surface grouped by a single `Route::prefix(...)` (`routes/api.php:442`, `:469`). `/api/creative` is exactly that shape, and it also happens to be a top-level segment that sorts next to `/api/partner` and `/api/screen-provider`.
4. `[INFERENCE]` Versioning is a *contract-stability* mechanism, and `AGENTS.md` §13 forbids changing an existing contract. The correct response to "Creative's contract will change" is additive change control inside the Creative context (policies, deprecation notices, changelog discipline), not a URL prefix that falsely signals the rest of the API is also versioned — it is not.
5. `[OPEN DECISION]` **If the organisation later decides to version the entire API**, `/api/creative` can be mounted under a version group without a breaking change, because no existing client depends on it. A `/api/v1/creative` published today, in a repository whose only versioned surface is explicitly legacy, cannot be un-published cheaply.

**Where this deviates, stated explicitly:**

| Deviation | Direction | Justification |
|---|---|---|
| No `/v1` segment while 19 routes carry `/v2` | deviates from the versioned minority | §9.1 points 1-5 above; `/v2` is legacy per Rule 10 |
| No `/v2` segment | deviates from the versioned minority | same |
| `creative` is a new top-level segment, not nested under `/api/admin` or `/api/partner` | consistent with both portal precedents | a Creative actor is not an Illuvision staff member and not a partner; nesting it under either would misstate ownership |

### 9.2 Deviations from the dominant convention, stated explicitly

Every Creative recommendation below deviates from the current majority. Each is stated here rather than buried.

| # | Creative recommendation | Current convention it departs from | Why the deviation is correct |
|---|---|---|---|
| D-1 | Single envelope A: `{"status", "message", "data", "meta"}` for **all** responses, including validation failures | 5 envelopes coexist (§6) | A Creative client cannot be written against 5 shapes. Creative is greenfield, so it can pick one. `status` + `message` + `data` is the majority shape (`Controller.php:16-20`). |
| D-2 | **Custom validation-error rendering** so a 422 is `{"status":false,"message","errors"}` rather than Laravel's `{"message","errors"}` (envelope E) | Laravel default (envelope E, 56 test assertions) | Envelope E has no `status` key, so a Creative client branching on `status` breaks on exactly the requests most likely to fail. The change is **Creative-scoped** — registered only for Creative request classes or a Creative route group — and touches no existing test. |
| D-3 | FormRequest + JsonResource on every endpoint | 134 inline `validate(` and 70/71 controllers returning no Resource | Creative exposes negotiated amounts, source files and earnings. Today those field names are dictated by model `$fillable`/`$casts`, so a model edit silently changes an API contract — unacceptable for a financial-adjacent surface. |
| D-4 | `public_id` (opaque, non-sequential) in every Creative path, not the integer `id` | 384/385 routes use integer ids | `09-...json:16` already commits to this as a `NEW CONVENTION for Creative` and gives the reason: sequential ids permit enumeration of other clients' work **and there is currently no policy layer to catch the resulting IDOR** (corroborated by artifact 13 §2.7: zero policies, zero gates). Creative can adopt a safe convention; the existing API cannot change its routes without breaking contracts (`AGENTS.md` §13). |
| D-5 | 404 for "exists but not visible to you", 403 for "visible but not permitted" | 403 for both (e.g. `PartnerPortalController.php:159`) | 403 on a non-existent id confirms existence and leaks the resource graph. The current ambiguity may be deliberate for partners; Creative should not inherit it silently. |
| D-6 | Explicit `Route::post/get/...`, no `apiResource` | 15 `apiResource` uses | A Creative transition (`submit`, `accept`, `request_changes`, `approve`, `pay`) is a business action, not a CRUD update. `apiResource` also auto-generates a `DELETE` mapping that no Creative lifecycle should expose generically. |
| D-7 | Rate limiting on every Creative write endpoint | one named limiter on one route (§10.4) | Brief submission, proposal submission and delivery upload are the expensive/spammable operations. Deviation justified by the cost profile, not by taste. |
| D-8 | Idempotency key accepted on every money-touching write | none exists (§10.5) | See §10.5. `[OPEN DECISION]` §11. |

### 9.3 Response contract for Creative

`[RECOMMENDATION]` Single envelope, all endpoints:

```json
// success (single resource) — 200
{ "status": true, "message": "<human sentence>", "data": { … }, "meta": null }

// success (collection) — 200
{ "status": true, "message": "…", "data": [ … ],
  "meta": { "total": 0, "per_page": 15, "current_page": 1, "last_page": 1, "from": null, "to": null } }

// created — 201
{ "status": true, "message": "…", "data": { … }, "meta": null }

// business-rule violation — 422
{ "status": false, "message": "…", "errors": { "field": ["…"] } }   // D-2

// unauthenticated — 401 (from PermissionMiddleware, unchanged)
{ "status": false, "message": "Unauthenticated." }

// forbidden — 403
{ "status": false, "message": "Forbidden. You do not have the required permission: X" }

// not found / not visible — 404
{ "status": false, "message": "Not found." }
```

`[INFERENCE]` This is envelope A verbatim, so a Creative client shares a parser with 300+ existing endpoints, plus the one D-2 fix and the D-5 404 rule.

### 9.4 Pagination, filtering, sorting

`[RECOMMENDATION]` `?page=N` (universal today), `?per_page=N` default **15**, hard clamp to 100. `[RECOMMENDATION]` `?sort=-created_at` allow-listed per endpoint; `?filter[status]=…` for the state sets in artifact 15. `[OPEN DECISION]` Whether Creative supports cursor pagination for the append-only ledgers (earnings, revisions, deliveries) — missing information: expected volume and whether clients need "resume where I left off" over a growing set. `simplePaginate`/`cursorPaginate` are used **zero** times today, so there is no precedent to copy.

---

## 10. What the current API does **not** have

### 10.1 Idempotency — none

`[CURRENT FACT]` `grep -rni "idempotenc" app/ database/ routes/` returns **one** hit, and it is a code comment in a seeder: `database/seeders/PricingPlanSeeder.php:365` — *"Re-seed Screen Rules to keep it simple, clean, and idempotent"*. `[CURRENT FACT]` No `Idempotency-Key` header is read anywhere. `[CURRENT FACT]` The only idempotency-ish mechanisms in the codebase are 10 uses of `firstOrCreate`/`updateOrCreate` in `app/` (e.g. `RolesAndPermissionsSeeder.php:131`) and ~30 `unique(...)` constraints across 112 migrations.

`[INFERENCE]` **There is no request-level idempotency.** A retried `POST /api/bookings` creates a second booking. `[RECOMMENDATION]` Creative must accept an optional `Idempotency-Key` on every money-touching write (`POST /api/creative/fundings`, `.../payouts/{id}/pay`) and enforce it with a unique index — the same technique `09-...json:563` already recommends for earnings (`UNIQUE(project_id, earning_type) — MANDATORY idempotency backstop against double-credit`). `[OPEN DECISION]` §11.

### 10.2 Rate limiting — one named limiter, on one route

`[CURRENT FACT]` Exactly one `RateLimiter::for` definition exists in the entire application: `app/Providers/AppServiceProvider.php:126-134`, named `recommendations`, returning a per-minute limit keyed by `recommendations:{ip}` and a per-hour limit, both read from `config('app.recommendations_rate_per_minute'|'…_per_hour')`. `[CURRENT FACT]` It is applied at exactly one route: `routes/api.php:240`, `->middleware('throttle:recommendations')` on `POST api/recommendations/calculate`, under the comment at `routes/api.php:238`.

`[CURRENT FACT]` `grep -n "throttle" routes/*.php bootstrap/app.php app/Providers/*.php` returns only those two hits plus the comment at `AppServiceProvider.php:125`. `[CURRENT FACT]` `bootstrap/app.php:17-21` customises middleware with **only** the `permission` alias — it does not append `throttle:api` to the `api` group. `[CURRENT FACT]` `php artisan route:list --json` confirms the string `ThrottleRequests` appears on exactly **1** of 385 routes.

`[INFERENCE]` **384 of 385 routes are unthrottled**, including every authenticated write, every login endpoint, and the Google-auth endpoint. `[CURRENT FACT]` Login is at `routes/api.php` under the public block (`POST api/login`, `POST api/auth/google`) with no throttle and no lockout. `[RECOMMENDATION]` Creative must define its own named limiters and apply them to the Creative write surface. Deviation D-7.

### 10.3 File upload — ad hoc, `public` disk, no signed URLs

`[CURRENT FACT]` 15 `hasFile`/`->file(` call sites in `app/Http/Controllers/`. `[CURRENT FACT]` Validation is inline and per-controller. Observed mime rules:

```text
3 ×  mimes:jpeg,png,jpg,gif,webp        (image)
1 ×  mimes:jpeg,png,mp4,mov,avi         (media)
1 ×  mimes:pdf,doc,docx                (document)
1 ×  mimes:xlsx,xls,csv                (spreadsheet import)
```

`[CURRENT FACT]` Size rules are scattered — `max:255` ×154, `max:50` ×29, `max:20` ×19, `max:100` ×18, `max:1000` ×12, `max:500` ×10, `max:5000` ×4, `max:2048` ×3 — with **no single upload size convention**.

`[CURRENT FACT]` Storage goes through `App\Infrastructure\Storage\LaravelStorageAdapter`, which **hard-codes** `protected const DISK = 'public';` at line 17 and uses it in `storeAs` (`:24`), `store` (`:32`), `put` (`:40`), `exists` (`:50`) and `delete` (`:58`). Its own docblock (`:9-13`) states the disk is fixed rather than passed in, precisely so callers cannot choose. `[CURRENT FACT]` `config/filesystems.php:16` sets `'default' => env('FILESYSTEM_DISK', 'local')` and `:41-42` defines the `public` disk. `[CURRENT FACT]` `Storage::disk('public')` is also called **directly** at 11 sites in `app/`, bypassing the adapter.

`[INFERENCE]` **Every uploaded file in this system is world-readable from the `public` disk. There are no signed URLs and no per-file visibility anywhere in `app/`.** `[CURRENT FACT]` `09-...json:187` already records this as a verified security gap and requires every Creative file to be `visibility = 'private'` and served through an authorised, expiring download.

`[CURRENT FACT]` Two different upload **shapes** exist:

- **Multipart file upload** — `BookingController.php:123-141`: loops `$request->file('files')`, delegates to `MediaService::upload($file, 'bookings/'.$booking->id)`, writes a `booking_media` row per file with `file_path, file_type, file_size, mime_type, original_name, sort_order`, then `event(new MediaUploaded($booking, $uploaded))` (`:144`) and returns **201** with an added `url` key merged into each item.
- **Path-based reference** — `GalleryController.php:26-30`: the client uploads out of band and POSTs `items.*.file_path` as a **string**; the server never touches the file.

`[INFERENCE]` The second shape means the server cannot validate that the referenced file exists, is owned by the caller, or has an acceptable type. `[RECOMMENDATION]` Creative uses **multipart upload only** (the `BookingController` shape), never the `GalleryController` path-reference shape, and stores everything private.

### 10.4 No request-signature verification, no API versioning headers

`[CURRENT FACT]` No `Accept-Version`/`X-API-Version` handling exists. `[CURRENT FACT]` `api/requirements.txt`… n/a. `[CURRENT FACT]` No CORS configuration appears in `bootstrap/app.php` or `config/cors.php` (the file does not exist); stateful Sanctum domains are set instead at `config/sanctum.php:16-20`.

---

## 11. Proposed endpoint table

`[RECOMMENDATION]` **PROPOSAL ONLY. No route file was modified. `routes/api.php` is unchanged at 720 lines and the route count is unchanged at 385.** Permission strings are those proposed in artifact 13 §5; **every one of them must be seeded in the same change** (artifact 13 §4, D-API-05).

**Legend.** `Auth` = `auth:sanctum`. `Perm` = `permission:*` group (enforced by `PermissionMiddleware`, which also emits the 401). `Public` = no auth middleware, matching the 69 existing public routes. `Actor` = the role expected to call it. `Own` = requires an instance-level policy check (Creative-specific; see D-5 and artifact 13 §6.6).

### 11.1 Public surface (no authentication)

| Method | Path | Auth | Perm | Actor | Notes |
|---|---|---|---|---|---|
| GET | `/api/creative/services` | Public | — | anyone | Published creator service listings. Matches existing public catalogue reads (`/api/screens`, `/api/products`). Returns only `status = published` rows. |
| GET | `/api/creative/services/{publicId}` | Public | — | anyone | Single published service. 404 if unpublished. |
| GET | `/api/creative/creators/{publicId}` | Public | — | anyone | Public creator profile + public portfolio only. `[OPEN DECISION]` §12.1 — whether the profile exposes a rating average before any review exists. |
| GET | `/api/creative/creators/{publicId}/portfolio` | Public | — | anyone | Public portfolio items only (`is_public = true`, per `09-...json:196`). |
| POST | `/api/creative/creators/register` | Public | — | authenticated-or-new user | **Creates the creator relationship, not a `users.role`.** `[OPEN DECISION]` §12.2 — the dual-role question (OD-AUTH-01) is BLOCKING; this endpoint cannot be designed until it is answered. Rate limited (D-7). |
| GET | `/api/creative/briefs/{publicId}` | Public | — | anyone | `[OPEN DECISION]` §12.3 — whether briefs are ever public. Existing precedent is mixed: `/api/pricing-plans` is public, `/api/campaigns/calculate` is public, `/api/bookings` is not. |
| POST | `/api/creative/briefs/{publicId}/applications` | Public | — | anyone | Public "apply to this brief" lead capture, mirroring `/api/partner-applications` and `/api/screen-provider-enquiries`. `[OPEN DECISION]` §12.4 — is an unauthenticated application in scope, or must the applicant be a registered creator? |

### 11.2 Authenticated — buyer / client

| Method | Path | Auth | Perm | Actor | Notes |
|---|---|---|---|---|---|
| GET | `/api/creative/briefs` | Auth | `creative.briefs.view` | buyer | Own briefs only. `Own` policy required. |
| POST | `/api/creative/briefs` | Auth | `creative.briefs.create` | buyer | 201. Throttled (D-7). |
| GET | `/api/creative/briefs/{publicId}` | Auth | `creative.briefs.view` | buyer | `Own`. 404 if not the owner (D-5). |
| PATCH | `/api/creative/briefs/{publicId}` | Auth | `creative.briefs.edit` | buyer | `[OPEN DECISION]` §12.5 — which states are editable. |
| POST | `/api/creative/briefs/{publicId}/close` | Auth | `creative.briefs.close` | buyer | Action endpoint, not PATCH. `[OPEN DECISION]` §12.6 — cancellation/close consequences. |
| GET | `/api/creative/briefs/{publicId}/proposals` | Auth | `creative.proposals.view` | buyer | Proposals against own brief. |
| POST | `/api/creative/proposals/{publicId}/accept` | Auth | `creative.proposals.accept` | buyer | **[OPEN DECISION] §12.7 — acceptance is a financial act; its effects are undefined.** |
| POST | `/api/creative/proposals/{publicId}/reject` | Auth | `creative.proposals.reject` | buyer | Reason required. |
| GET | `/api/creative/projects` | Auth | `creative.projects.view` | buyer | Own projects. |
| GET | `/api/creative/projects/{publicId}` | Auth | `creative.projects.view` | buyer | `Own`. |
| GET | `/api/creative/projects/{publicId}/deliveries` | Auth | `creative.deliveries.view` | buyer | `Own`. |
| POST | `/api/creative/deliveries/{publicId}/accept` | Auth | `creative.deliveries.accept` | buyer | **[OPEN DECISION] §12.8 — acceptance window unknown.** |
| POST | `/api/creative/deliveries/{publicId}/request-changes` | Auth | `creative.deliveries.request_changes` | buyer | Creates a revision round per artifact 15. |
| POST | `/api/creative/projects/{publicId}/reviews` | Auth | `creative.reviews.create` | buyer | One review per project, per reviewer (`09-...json:527` UNIQUE constraint). |
| POST | `/api/creative/fundings` | Auth | `creative.fundings.create` | buyer | **Idempotency-Key required** (§10.1). `[OPEN DECISION]` §12.9 — escrow in scope at all? |

### 11.3 Authenticated — creator

| Method | Path | Auth | Perm | Actor | Notes |
|---|---|---|---|---|---|
| GET | `/api/creative/creator/services` | Auth | `creative.services.view` | creator | Own services **including drafts** — a separate path from the public listing, mirroring the `/api/partner` portal precedent. |
| POST | `/api/creative/creator/services` | Auth | `creative.services.create` | creator | 201. |
| PATCH | `/api/creative/creator/services/{publicId}` | Auth | `creative.services.edit` | creator | `Own`. |
| POST | `/api/creative/creator/services/{publicId}/publish` | Auth | `creative.services.publish` | creator | Action endpoint — publish is not a boolean PATCH, because it has a side effect on the public surface. |
| POST | `/api/creative/creator/portfolio` | Auth | `creative.portfolio.manage` | creator | Multipart upload, private visibility (§10.3). |
| GET | `/api/creative/briefs/open` | Auth | `creative.briefs.view_open` | creator | Open briefs in the creator's categories. |
| POST | `/api/creative/briefs/{publicId}/proposals` | Auth | `creative.proposals.submit` | creator | 201. Throttled. `[OPEN DECISION]` §12.10 — one proposal per creator per brief (`09-...json:286` UNIQUE is itself OPEN). |
| PATCH | `/api/creative/proposals/{publicId}` | Auth | `creative.proposals.edit` | creator | Pre-submit only. |
| POST | `/api/creative/proposals/{publicId}/withdraw` | Auth | `creative.proposals.withdraw` | creator | |
| GET | `/api/creative/creator/projects` | Auth | `creative.projects.view` | creator | Own projects. |
| POST | `/api/creative/creator/projects/{publicId}/deliveries` | Auth | `creative.deliveries.submit` | creator | Multipart upload, private. The single most important write in the context. |
| POST | `/api/creative/revisions/{publicId}/respond` | Auth | `creative.revisions.create` | creator | `[OPEN DECISION]` §12.11 — revision limits unknown. |
| GET | `/api/creative/creator/earnings` | Auth | `creative.earnings.view` | creator | Own earnings, **append-only ledger, never a balance field**. |
| POST | `/api/creative/creator/payouts` | Auth | `creative.payouts.request` | creator | Idempotency-Key. `[OPEN DECISION]` §12.12 — minimum, cadence. |
| PATCH | `/api/creative/creator/profile` | Auth | `creative.profile.edit` | creator | |
| GET | `/api/creative/creator/notifications` | Auth | — | creator | See §11.8. |

### 11.4 Authenticated — reviewer

| Method | Path | Auth | Perm | Actor | Notes |
|---|---|---|---|---|---|
| GET | `/api/creative/review/queue` | Auth | `creative.review.queue.view` | reviewer | `[OPEN DECISION]` §12.13 — is the reviewer an internal `manager`/`staff` or a new role (OD-AUTH-03)? The path is the same either way; only the seeded grants differ. |
| GET | `/api/creative/review/deliveries/{publicId}` | Auth | `creative.review.queue.view` | reviewer | Private file download, **expiring and authorised** — see §10.3 and D-API-06. |
| POST | `/api/creative/review/deliveries/{publicId}/approve` | Auth | `creative.review.approve` | reviewer | 201/`200`. `[OPEN DECISION]` §12.14 — separation of duties vs the creator. |
| POST | `/api/creative/review/deliveries/{publicId}/reject` | Auth | `creative.review.reject` | reviewer | Reason required. |
| POST | `/api/creative/reviews/{publicId}/moderate` | Auth | `creative.reviews.moderate` | reviewer | `[OPEN DECISION]` §12.15 — whether public reviews are moderated at all. |

### 11.5 Authenticated — project manager

| Method | Path | Auth | Perm | Actor | Notes |
|---|---|---|---|---|---|
| GET | `/api/creative/manage/briefs` | Auth | `creative.briefs.moderate` | project manager | `[OPEN DECISION]` §12.16 — `manager` role or a new `project_manager` row (OD-AUTH-04). |
| GET | `/api/creative/manage/briefs/{publicId}` | Auth | `creative.briefs.moderate` | project manager | |
| POST | `/api/creative/manage/briefs/{publicId}/extend` | Auth | `creative.briefs.extend` | project manager | `[OPEN DECISION]` §12.17 — how many times, by how much. |
| GET | `/api/creative/manage/projects` | Auth | `creative.projects.view_all` | project manager | Cross-client view. |
| GET | `/api/creative/manage/projects/{publicId}` | Auth | `creative.projects.view_all` | project manager | |
| POST | `/api/creative/manage/projects/{publicId}/assign` | Auth | `creative.projects.assign` | project manager | |
| POST | `/api/creative/manage/projects/{publicId}/transition` | Auth | `creative.projects.transition` | project manager | **Mirrors the existing `POST api/v2/bookings/{id}/transition` shape** (`app/Http/Controllers/Api/BookingEngineController.php:124-136`) — the closest existing precedent for a guarded state transition exposed as one endpoint. Body shape per artifact 15. |
| POST | `/api/creative/manage/projects/{publicId}/revisions` | Auth | `creative.revisions.request` | project manager | |
| GET | `/api/creative/manage/disputes` | Auth | `creative.disputes.resolve` | project manager | `[OPEN DECISION]` §12.18 — dispute model does not exist. |
| POST | `/api/creative/manage/disputes/{publicId}/resolve` | Auth | `creative.disputes.resolve` | project manager | `[OPEN DECISION]` §12.18. |

### 11.6 Authenticated — finance / settlement

| Method | Path | Auth | Perm | Actor | Notes |
|---|---|---|---|---|---|
| GET | `/api/creative/finance/earnings` | Auth | `creative.earnings.view_all` | finance | `/api/creative/finance/...` deliberately mirrors the existing `/api/v2/finance/...` grouping so the shape is familiar. |
| POST | `/api/creative/finance/earnings/{publicId}/approve` | Auth | `creative.earnings.approve` | finance | Append-only accrual. |
| POST | `/api/creative/finance/earnings/{publicId}/reverse` | Auth | `creative.earnings.reverse` | finance | **Compensating record, never an UPDATE or DELETE** (`AGENTS.md` §10, `09-...json:574`). |
| GET | `/api/creative/finance/payouts` | Auth | `creative.payouts.view_all` | finance | |
| POST | `/api/creative/finance/payouts/{publicId}/approve` | Auth | `creative.payouts.approve` | finance | Separation of duties vs `creative.payouts.request` (`09-...json:611`). `[OPEN DECISION]` §12.19 — OD-AUTH-05, and note that with no `finance` role both permissions land on the same role, which is not separation of duties. |
| POST | `/api/creative/finance/payouts/{publicId}/pay` | Auth | `creative.payouts.pay` | finance | Idempotency-Key. Mirrors the existing `settlements.pay` string. |
| GET | `/api/creative/finance/settlements` | Auth | `creative.settlements.view` | finance | Must be **seeded** — the existing `settlements.*` family is not (artifact 13 §4). |

### 11.7 Authenticated — admin

| Method | Path | Auth | Perm | Actor | Notes |
|---|---|---|---|---|---|
| GET | `/api/creative/admin/*` | Auth | `creative.*` | admin | Extends the existing `/api/admin` (73 routes) convention with a `creative` sub-segment rather than a new top-level admin prefix. |
| POST | `/api/creative/admin/creators/{publicId}/suspend` | Auth | `creative.profile.edit` | admin | `[OPEN DECISION]` §12.20 — is suspension via a Creative status field or via the existing, currently-unread `users.status` (artifact 13 D8)? |
| POST | `/api/creative/admin/earnings/{publicId}/reverse` | Auth | `creative.earnings.reverse` | admin | |

### 11.8 Cross-cutting

`[RECOMMENDATION]` Three non-resource endpoints:

| Method | Path | Auth | Perm | Actor | Notes |
|---|---|---|---|---|---|
| GET | `/api/creative/me` | Auth | — | any | Resolves the caller's capabilities: role slugs, seeded permission strings, creator-row presence, buyer-project count. `[RECOMMENDATION]` Ship this **before** the other endpoints — it is the one endpoint that makes the §11.2-§11.7 permission model client-discoverable, and it directly mitigates OD-AUTH-01 by letting a client learn its actual identity rather than assuming `users.role`. |
| GET | `/api/creative/notifications` | Auth | — | any | `[OPEN DECISION]` §12.21 — Creative notifications need a recipient type that is not the existing `notification_logs` shape; `10-...json:2178` proposes a new `recipient_type` value `'creator'`. |
| GET | `/api/creative/health` | Public | — | ops | Not required. Listed only because the repo already exposes `/up` (`bootstrap/app.php:15`). |

---

## 12. Open decisions specific to the API surface

`[OPEN DECISION]` Each states exactly what is missing. None was guessed.

| ID | Question | Exactly what is missing |
|---|---|---|
| §12.1 | Does a public creator profile expose a rating before any review exists? | A product decision. There is no review data and no rule about zero-review display. |
| §12.2 | `POST /api/creative/creators/register` — is creator-ness a `users.role` value or a derived relationship? | **Blocking.** This is OD-AUTH-01 in artifact 13. A `users.role` value can only hold one thing per user and would revoke the user's buyer permissions; a derived relationship needs no role row. |
| §12.3 | Are briefs ever publicly readable? | A product decision. Existing precedent is genuinely mixed. |
| §12.4 | Must a brief applicant already be a registered creator? | A product decision. Existing public applications (`/api/partner-applications`, `/api/screen-provider-enquiries`) accept unauthenticated leads, but a Creative brief is commercially sensitive. |
| §12.5 | Which brief states are editable? | The state set itself is undecided (artifact 15). |
| §12.6 | What are the consequences of closing a brief with live proposals or a funded project? | A business rule. No evidence anywhere. |
| §12.7 | What does accepting a proposal do financially? | A business rule. Whether it funds, reserves, or merely records intent; whether any amount is computed; whether any earning accrues. No evidence. |
| §12.8 | Is there a delivery acceptance window? | A business rule. Length, and the behaviour on expiry. No evidence. |
| §12.9 | Is escrow/funding in Creative scope? | A product decision. `09-...json` includes `creative_fundings`, but nothing states it is required for v1. |
| §12.10 | May a creator submit more than one proposal per brief? | `09-...json:286` marks `unique(brief_id, creator_id)` as OPEN. |
| §12.11 | Is there a revision limit? | A business rule. No evidence. |
| §12.12 | Payout minimum and cadence. | A business rule. No evidence. |
| §12.13 | Is "reviewer" a new role or `manager`/`staff`? | A staffing decision (OD-AUTH-03). |
| §12.14 | May a creator review their own delivery, or the requester approve their own project? | An internal-control decision. |
| §12.15 | Are public reviews moderated? | A product decision. |
| §12.16 | `manager` role or a new `project_manager` row? | A staffing decision (OD-AUTH-04). |
| §12.17 | How many times, and by how much, may a brief deadline be extended? | A business rule. No evidence. |
| §12.18 | Does a dispute model exist at all? | A product decision. No dispute entity is defined in any artifact. |
| §12.19 | Who holds Creative financial authority? | An org decision (OD-AUTH-05). Until answered, separation of duties is unenforceable. |
| §12.20 | Is a creator suspended via a Creative status field or `users.status`? | A decision plus implementation. `users.status` exists (`database/migrations/2026_08_01_000001_add_status_and_last_login_to_users_table.php:12`) and nothing reads it. |
| §12.21 | What is the Creative notification recipient model? | A design decision. The existing `notification_logs` row shape assumes a user recipient. |
| §12.22 | Cursor pagination for the append-only Creative ledgers? | A volume/usage decision. No precedent exists (`simplePaginate`/`cursorPaginate` used zero times). |
| §12.23 | What is the Creative token lifetime and revocation policy? | A security decision (OD-AUTH-07). A global `config/sanctum.php` change would alter every existing token, which `AGENTS.md` §13 forbids. |

---

## 13. Discrepancies vs existing artifacts

Source wins. Items where an existing `docs/audit/*` artifact is incomplete, wrong, or in conflict with the code.

| # | Existing artifact | Claim | Source reality | Classification |
|---|---|---|---|---|
| D-API-01 | `09-creative-database-target-schema-blueprint.json:16` | `public_id` *"used in all API routes"* | Correct **as a proposal**, and correctly labelled a `NEW CONVENTION`. But no prior artifact records the current convention it departs from: **384 of 385 route parameters are integer primary keys, and the only non-API identifier in the system is one `slug` on `routes/web.php:9`.** The deviation is larger than the blueprint implies and needs the explicit justification in §9.2 D-4. | Incomplete, not wrong. |
| D-API-02 | `09-...json:16` | `public_id` is `char(26)` (ULID) **or** `char(36)` (UUID), with `CRJ-2026-000123` as a third option | Still three-way undecided. Additionally, `09-...json:16` notes the prefixed option "would match the existing `ILL-` booking_reference convention" — `[CURRENT FACT]` that convention exists in the schema but is **not used in any route**; it is a stored column only. So the "match existing convention" argument is weaker than stated. | Weakened rationale. |
| D-API-03 | `09-...json:16` | *"sequential ids permit enumeration of other clients' work, and there is currently no policy layer to catch the resulting IDOR"* | **Verified and stronger than stated.** `app/Policies` does not exist; `grep "Gate::"` → 0; `grep "authorize("` over controllers → 0; 0 controllers declare constructor middleware. Authorization is route-level plus hand-written `id === user->id` checks (`BookingController.php:116`, `PaymentController.php:48`). | Confirmed. |
| D-API-04 | `09-...json:563` | `UNIQUE(project_id, earning_type)` as a *"MANDATORY idempotency backstop against double-credit"* | Sound, and it is the **only** idempotency mechanism anywhere: `grep -rni idempotenc app/ database/ routes/` returns exactly one hit, a comment in `database/seeders/PricingPlanSeeder.php:365`. There is no request-level idempotency key on any endpoint. | Confirmed, and broader than stated. |
| D-API-05 | `09-...json:657`, `10-...json:117` | Add `creative.*` permission rows | Sound in intent, but silent on the fact that **3 route-referenced permissions (`settlements.view`/`.approve`/`.pay`) are granted to no role at all** across 12 live routes, and 24 seeded permissions are used on no route. Creative must not repeat the pattern. | Incomplete. Full analysis in artifact 13 §4. |
| D-API-06 | `09-...json:187` | *"this repository has ZERO signed URLs, and `LaravelStorageAdapter` hard-codes `DISK = 'public'`"* | **Verified exactly.** `app/Infrastructure/Storage/LaravelStorageAdapter.php:17` `protected const DISK = 'public';`, used at `:24, :32, :40, :50, :58`. Zero signed-URL code. Additionally: 11 sites bypass the adapter with a direct `Storage::disk('public')` call, and `GalleryController.php:26-30` accepts a client-supplied `file_path` string with no existence, ownership or type check. | Confirmed, plus two additions. |
| D-API-07 | `10-...json:1237` | `settings` → add a `creative.*` group for `creative.revision_limit`, `creative.dispute_window_days`, `creative.payout_minimum` | Cannot be implemented as stated: the values themselves are undefined. This is the API surface of the same gap recorded as §12.8, §12.11, §12.12, §12.17 and artifact 13 OD-AUTH-10. Naming a setting key presupposes the rule behind it. | **Unimplementable as written.** Flagged, not corrected. |
| D-API-08 | `09-...json:186` | owner_type includes `'brief'`, `'project'`, `'delivery'`, `'review'` | Consistent with the file inventory in artifact 14 §10.3 — but the 09 blueprint has no endpoint table, and no prior artifact maps a single Creative route to a method, a path, a permission and an actor. This artifact is the first. | Gap filled. |
| D-API-09 | — | Not recorded anywhere | **384 of 385 routes are unthrottled.** `bootstrap/app.php:17-21` customises middleware with only the `permission` alias and does not append `throttle:api`; exactly one named limiter exists (`AppServiceProvider.php:126`) applied to exactly one route (`routes/api.php:240`). This includes `POST /api/login` and `POST /api/auth/google`. | New finding. |
| D-API-10 | — | Not recorded anywhere | **Five distinct response envelopes** are in simultaneous production use (§6), including a fourth emitted from a *domain* exception (`app/Domain/Booking/Exceptions/InvalidBookingStateException.php:11-18`) and a fifth from Laravel's own validation handler because `bootstrap/app.php:22-24` registers an empty `withExceptions` closure. | New finding. |
| D-API-11 | — | Not recorded anywhere | The `per_page` / `perPage` query-parameter split: 12 + 1 sites use `per_page`, 11 + 1 use `perPage`, with defaults 10 / 12 / 15 / 20 / 25 / 500 all in use. No prior artifact records that pagination has **three** spellings. | New finding. |
| D-API-12 | — | Not recorded anywhere | 16 routes carry a `permission:*` middleware with **no** `auth:sanctum` (all `/api/v2/*`), which is the reason `PermissionMiddleware` emits its own 401. A new surface that copies the v2 pattern will emit `{"status":false,"message":"Unauthenticated."}` rather than Laravel's default. | New finding. |
| D-API-13 | — | Not recorded anywhere | `routes/api.php:717` applies an unregistered `admin` middleware alias; `app("admin")` throws `BindingResolutionException`. `GET api/admin/currency-rates` and `POST api/admin/currency-rates/sync` are unreachable and untested. | New finding. (Same defect as artifact 13 D12.) |
| D-API-14 | — | Not recorded anywhere | **69 of 385 routes have no auth and no permission middleware, and several are unauthenticated writes** — `api/gallery` POST/PUT/DELETE declared bare at `routes/api.php:703`, `POST api/blogs`, `POST api/screen-categories` (`:217`), `POST api/admin/screen-categories` (`:218`), and three `api/admin/assets/import/*` endpoints. | New finding. Out of scope to fix (`AGENTS.md` §18). |
| D-API-15 | — | Not recorded anywhere | Response field names on most of the API are dictated by model `$fillable`/`$casts` because 70 of 71 controllers return no JsonResource. A model edit can change an API response without any HTTP-layer edit — a latent `AGENTS.md` §13 hazard for every surface, Creative included. | New finding. |
| D-API-16 | `02-current-architecture-assessment.md` §6 and `01-current-backend-inventory.json` | Implies a single response convention | No prior artifact records that the `/api/v2` surface deliberately (or accidentally) uses a **different** envelope — `{"message","data"}` with no `status` key (`Api/BookingEngineController.php:108,118,132`) and raw paginator/model on reads (`:36`). | Contradicted by source. |
| D-API-17 | Root `AGENTS.md` §4, `01-current-backend-inventory.json` | routes 385, api.php 720 lines | Verified exactly on 2026-09-26: 385 routes; `routes/api.php` 720 lines; `routes/web.php` 11; `routes/console.php` 36. No discrepancy. | No conflict. |

---

## 14. Verification performed

`[CURRENT FACT]` Read-only. **No route was added. `routes/api.php` is unchanged at 720 lines and the route count is unchanged at 385.** No controller, request, resource, middleware, config or test was created or modified. The endpoint table in §11 is a proposal.

```text
Read:    AGENTS.md; docs/architecture/ARCHITECTURE_RULES.md;
         docs/audit/creative-marketplace/{01,09,10};
         routes/api.php (720 lines), routes/web.php, routes/console.php;
         bootstrap/app.php; config/{auth,sanctum,filesystems}.php;
         app/Http/Controllers/Controller.php;
         app/Http/Controllers/{BookingController,PaymentController,PartnerPortalController,
                               GalleryController,ReportController}.php;
         app/Http/Controllers/Api/{BookingEngineController,RoleController,AdminUserController}.php;
         app/Http/Middleware/PermissionMiddleware.php;
         app/Http/Requests/*, app/Http/Resources/*;
         app/Domain/Booking/Exceptions/InvalidBookingStateException.php;
         app/Infrastructure/Storage/LaravelStorageAdapter.php;
         app/Providers/AppServiceProvider.php;
         database/seeders/PricingPlanSeeder.php
Commands: php artisan route:list --json  (385 routes, full middleware + URI census)
          ValidationException rendered through the framework exception handler to
          confirm envelope E (read-only, no state change)
          app("admin") container probe (read-only)
          wc -l routes/*.php
Grep:    zero-result searches recorded as fact: app/Policies absent; Gate:: zero;
         authorize( zero; controller-constructor middleware zero; simplePaginate/cursorPaginate zero;
         Idempotency-Key zero; config/cors.php absent; settlements. in database/ zero;
         admin/currency-rates in tests/ zero
```

`[CURRENT FACT]` No test suite was run and `ArchitectureRuleTest` was not run, because **no code was changed**; `AGENTS.md` §17 does not require them for a documentation-only artifact.
