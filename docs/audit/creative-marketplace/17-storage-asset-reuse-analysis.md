# 17 — Storage and Asset Reuse Analysis

**Generated:** 2026-09-26
**Status:** Read-only design analysis. **Nothing was implemented.** No file, class, migration, route, config entry or disk was created or modified. The subject — the Creative Marketplace bounded context — does not exist: 0 classes, 0 routes, 0 tables, 0 migrations, 0 tests.
**Scope:** How files are stored and served in `illuvision/core-backend` today, what of that is safe to reuse for Creative work, what must be extended, and what must be built from scratch.

---

## Classification scheme

| Tag | Meaning |
|---|---|
| `CURRENT_FACT` | Verified from source on 2026-09-26, cited as `file:line`. |
| `ARCHITECTURE_DOCUMENT` | Stated in `AGENTS.md` or `docs/architecture/ARCHITECTURE_RULES.md`. |
| `INFERENCE` | Reasonably inferred from the implementation or from relationships between facts. |
| `RECOMMENDATION` | Proposed target design. Not implemented. |
| `OPEN DECISION` | Cannot be determined from available evidence. What is missing is stated. |

**Precedence:** current source code wins over any existing `docs/audit/` artifact. Conflicts are recorded in §11.

---

## 1. Executive summary

The honest summary is this: **there is a file *upload* mechanism in this repository, and there is no file *delivery* mechanism at all.**

- Upload works. There is a `Storage` contract, a Laravel adapter, an image compression path, an extension/size policy object, and one genuine end-to-end precedent (`booking_media`).
- Delivery does not work, because it is not needed yet. **Every single file in this system is published to the world-readable `public` disk and served over plain HTTP from `APP_URL/storage`.** There are **zero** signed URLs, **zero** expiring URLs, **zero** per-file authorization on download, and **zero** centralized file records.
- For Creative, that gap is not an inconvenience. A brief attachment, an undelivered draft, a source file, or a creator's earnings-adjacent document are **commercially confidential material belonging to two identifiable parties**. Publishing any of them to `storage/app/public` is a security defect the moment it happens, and it is **not recoverable** — a file written to a public path is cached, indexed and mirrored by things outside the application's control.

Three consequences follow, and they shape the whole of this document:

1. **Reuse the upload mechanics; do not reuse the storage target.** `StorageContractInterface` is hard-pinned to one disk by a `private const`, and that disk is `public`. Reusing it for a brief attachment would publish the attachment.
2. **A Creative file-access contract and an authorization check are NEW work.** There is nothing to extend. This is the single largest *new* build in the storage area, and it is larger than the Creative domain model itself.
3. **A central file record is NEW work.** Files are bare path strings scattered across 16 columns in 14 tables. There is no `files` table, so there is nowhere to hang an owner, a visibility, a version, a checksum, or a lifecycle.

---

## 2. The current storage substrate

### 2.1 Disks — `CURRENT_FACT`

`config/filesystems.php:31-63` defines exactly three disks:

| Disk | Driver | Root | URL | Visibility | Referenced in `app/`? |
|---|---|---|---|---|---|
| `local` | `local` | `storage_path('app/private')` | — (`serve => true`) | — | **Never.** `grep -rn "'local'" app/` → 0 matches. The directory exists on disk but nothing writes to it. |
| `public` | `local` | `storage_path('app/public')` | `env('APP_URL').'/storage'` | `public` | **Yes — exclusively.** 11 `Storage::disk('public')` call sites plus the adapter's own `private const DISK = 'public'`. |
| `s3` | `s3` | bucket from env | `env('AWS_URL')` | — | **Never referenced in `app/`.** Configured, not used. |

`config/filesystems.php:76-78` symlinks `public/storage` → `storage/app/public`.

> **Operational note, `CURRENT_FACT`:** in this working checkout, `public/storage` is a symlink pointing at `/Users/apple/my-works/illuvision/backend/storage/app/public` — a **different repository path** (`illuvision/backend`, not `illuvision-ecosystem/core-backend`). Anything verified by fetching a `public` URL locally is reading the wrong directory. This is an environment artefact, not a code defect, but it will silently invalidate any manual file-access test.

Two consequences that matter for design:

- `'serve' => true` on the `local` disk is a Laravel 11 capability for serving files through the application. It is **not** an access-control mechanism — it makes a private file *reachable by a route*. It is only as safe as whatever authorises that route. Creative must not treat it as a substitute for authorization.
- The `s3` disk exists with `throw => false` and `report => false`, i.e. **failures are silent**. A private-file store on this configuration would swallow I/O errors. If Creative ever moves to S3 for private files, `throw` must become `true` for that disk. Out of scope for the initial release; recorded because it is a latent trap.

### 2.2 The storage contract and its single-disk pin — `CURRENT_FACT`

`App\Infrastructure\Storage\Contracts\StorageContractInterface` exposes six methods:

```php
storeAs(UploadedFile $file, string $directory, string $name): string
store(UploadedFile $file, string $directory): string
put(string $path, string $contents): string
exists(string $path): bool
delete(string $path): bool
url(string $path): string
```

`App\Infrastructure\Storage\LaravelStorageAdapter` implements it with:

```php
protected const DISK = 'public';
```

The contract's own docblock says this is "intentionally narrower than Laravel's Storage facade … pinning the disk here stops a caller from silently addressing a different one."

`CURRENT_FACT` — three facts that together decide the whole reuse question:

- There is **no** signed-URL method, **no** temporary-URL method, **no** visibility argument, **no** stream/chunked-write method, **no** copy/move method, and **no** disk-name argument.
- `url()` calls `Storage::url($path)`, which resolves against the **default** disk, not against `self::DISK`. With `config('filesystems.default') = env('FILESYSTEM_DISK', 'local')` (`config/filesystems.php:16`) the default in a fresh install is `local`, so `url()` is already subtly inconsistent with the disk everything is written to. This is a pre-existing bug; recorded, **not fixed** (`AGENTS.md` §18).
- Adapters bypass the contract entirely at **6 call sites in 6 controllers** plus one model. `grep -rl "Storage::" app/Http/Controllers/` → `AuthorController`, `GalleryController`, `JobApplicationController`, `ScreenController`, `ScreenProviderPortalController`, `SuccessStoryController`. Plus `app/Models/Screen.php:95`. That is **7 files bypassing the contract**, and only 1 adapter. (`INFERENCE`: the contract was extracted for the media layer and never became a repository-wide boundary.)

`RECOMMENDATION`: the contract is the right *shape* and the wrong *scope*. Creative should define its own port (see §4) rather than widen `StorageContractInterface`. Widening a shared interface is a change to code that the booking path depends on, and `AGENTS.md` §19 forbids opportunistic refactoring of shared infrastructure during an unrelated build.

### 2.3 How uploads are actually performed today — `CURRENT_FACT`

There are four distinct upload paths, of wildly different quality:

| # | Path | Validation | Disk | Directory | Risk |
|---|---|---|---|---|---|
| 1 | `BookingController::uploadMedia` — `app/Http/Controllers/BookingController.php:114-147` | **Yes.** `MediaService::batchValidationRules()` at `:120-121`; ownership check at `:116-118` (`$booking->user_id !== $request->user()->id` → 403) | `public` (via adapter) | `bookings/{id}` | Acceptable. Note the event fires at `:144` **outside** any transaction — see artifact 16. |
| 2 | `PartnerBookingController::uploadMedia` — `routes/api.php:461` | Delegated | `public` | — | Duplicated logic in a second controller. |
| 3 | `AdminManualBookingController::uploadMedia` — `routes/api.php:645` | Delegated | `public` | — | Third copy. |
| 4 | **`FileController::upload` / `FileController::videoupload`** — `app/Http/Controllers/FileController.php:9-50` | **NONE.** Raw `Illuminate\Http\Request`, no FormRequest, no `$request->validate()` anywhere in the file. | `public`, hard-coded string at `:29` and `:44` | **client-supplied `$request->path`** | **Highest risk in the repository.** |

`FileController` deserves a precise reading, because it is the single most dangerous existing upload path and a Creative developer who "just reuses the upload endpoint" inherits every one of these:

```php
// FileController.php:12-13
$file = $request->file('file');
$path = $request->path;                    // ← fully client-controlled
// FileController.php:28-29
$fileName = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
$file_path = $file->storeAs($path, $fileName, 'public');
```

- **No validation at all.** No `mimes:`, no `max:`, no count limit, no extension allow-list. `MediaConfig::allowedImageTypes()` and `allowedVideoTypes()` exist and are populated (`app/Support/Configs/MediaConfig.php:56-79`) but `MediaService::validate()` never uses them — it uses `allowedMediaExtensionsString()` instead, i.e. **validation is by file extension, not by MIME type**, which accepts a renamed executable.
- **Client-controlled destination directory.** `$request->path` is written verbatim as the storage directory. A caller can target any sub-path of `storage/app/public`, including one already used by another feature, and there is no per-user or per-tenant prefix.
- **Client-controlled extension preserved.** `getClientOriginalExtension()` is attacker-supplied by definition. Combined with `'serve' => true` on a sibling disk and the web-server's mime handling, this is the classic route to stored executable content.
- **No auth check in the method.** Authorization depends entirely on the route group. `routes/api.php:659-660` registers both endpoints; both return a stored path on success.
- **Route-level rate limiting: none.** The only `throttle:` in the entire route table is `routes/api.php:240` (`throttle:recommendations`), defined at `app/Providers/AppServiceProvider.php:126-133`. There is no global `api` limiter and no default throttle group.

`RECOMMENDATION` (firm): **Creative must not route a single byte through `FileController`.** Creative uploads require a FormRequest, MIME-level validation, a server-generated path, a server-generated extension derived from the detected type, a size cap, a per-context count cap, and a private disk.

### 2.4 Image processing — `CURRENT_FACT`

`App\Infrastructure\Media\Contracts\ImageProcessingContractInterface` has two methods:

```php
isAvailable(): bool
encodeWebp(string $binary, int $quality): ?string
```

`App\Infrastructure\Media\GdImageProcessor` is the only implementation (bound at `app/Providers/AppServiceProvider.php:111-114`). GD only — no Imagick, no Intervention Image.

`App\Helpers\MediaHelper::compressUploadedImage()` owns the *policy*: a 70 KB target and a quality ladder stepping 80 → 70 → … → 20, stopping at quality ≤ 30 or when the budget is met. It resolves the processor defensively — explicit instance, then container, then `new GdImageProcessor` — because it is a static helper called from unit tests without a booted app (`app/Helpers/MediaHelper.php:78-86`).

`INFERENCE` — what this means for Creative:

- **Resizing is not available.** The contract cannot change dimensions, so a Creative thumbnail/preview pipeline cannot be built on it. The options are (a) extend the shared contract (a shared-interface change, needs approval, and the contract is deliberately expressed "in terms of bytes rather than GD handles so that the contract does not leak the underlying image extension"), or (b) a Creative-local contract with its own GD usage. Artifact 05 recommends (b) for the same reason; this artifact agrees.
- **EXIF is not stripped.** `copyAsIs()` writes the original bytes untouched when the encoder is unavailable or the input is not an image (`app/Helpers/MediaHelper.php:132-149`). A portfolio image therefore retains GPS coordinates, camera serial and creator name by default.
- **Format is not preserved.** A non-compressible image silently becomes `.webp`; the returned `file_path` reflects it, but the caller's `original_name` still carries the old extension — a mismatch Creative would have to reconcile.
- **The 70 KB budget is a marketing-image budget, not a creative-asset budget.** Forcing a portfolio hero image to 70 KB is a quality regression for a paid creative deliverable. `RECOMMENDATION`: Creative defines its own target sizes and never inherits the 70 KB constant.

### 2.5 File-bearing columns, independently derived — `CURRENT_FACT`

`file_bearing_columns_without_a_central_file_record` in artifact 08 is re-derived here from scratch (a script over all 112 migrations matching column names containing `file|image|video|thumb|avatar|resume|banner|logo|photo|document|attach|cover|preview|path|url`). The derivation **confirms artifact 08's list exactly**. The script additionally surfaced two columns that are *not* files and are correctly excluded: `blogs.image_alt` (alt text) and `qr_codes.target_url` (a link).

| Table | Column(s) | Notes |
|---|---|---|
| `screens` | `thumbnail` | |
| `booking_media` | `file_path`, `file_type`, `file_size` | Only table with any file metadata |
| `galleries` | `image`, `video` | |
| `success_stories` | `image`, `video` | |
| `categories` | `image` | Blog categories |
| `testimonials` | `image` | |
| `achievements` | `image`, `video` | |
| `announcements` | `image` | |
| `blogs` | `banner_image`, `og_image` | |
| `blog_descriptions` | `image` | |
| `products` | `thumbnail` | |
| `users` | `avatar` | |
| `job_applications` | `resume` | **Applicant PII on the public disk** — already noted in artifact 08 |

The structural observation, restated because it is the one that constrains the Creative design:

> **There is no `files`, `media`, `documents`, `attachments` or `uploads` table.** Every file is a bare path string in a feature-specific column. There is therefore nowhere to store an owner, a visibility, a version, a checksum, a byte size, a detected MIME type, a retention class, or a deletion state — and no single place to enforce any of them.

`RECOMMENDATION`: `creative_files` is a **prerequisite**, not a nicety. Every Creative file — portfolio image, brief attachment, project file, revision file, delivery file, preview, source file — must be a row in it, referenced by foreign key from the owning aggregate. This is not a "central file service" ambition; it is the minimum needed to answer "who may read this?".

### 2.6 The `booking_media` precedent — `CURRENT_FACT`

`booking_media` is the **only** existing instance of "a client uploads files that someone else must approve". It is the closest structural precedent for Creative deliveries, and it is worth reading precisely because both its strengths and its weaknesses will be copied if it is used as a template.

**Strengths — the review pattern:**

```
review_status   pending (DB default) | approved | rejected
reviewed_by     -> users (null on delete)
reviewed_at     timestamp
rejection_reason  free text, required on reject
```

Enforced by `App\Models\BookingMedia` constants `REVIEW_PENDING/APPROVED/REJECTED` and scopes `scopePendingReview/scopeApproved/scopeRejected`. The admin workflow:

- `POST /api/bookings/{booking}/media/{media}/approve` → `routes/api.php:683`, `permission:bookings.approve`
- `POST /api/bookings/{booking}/media/{media}/reject` → `routes/api.php:684`, `permission:bookings.approve`
- `POST /api/bookings/{booking}/mark-media-review` → `routes/api.php:682`

`BookingController::approveMedia` / `::rejectMedia` both: (1) assert `$booking->id === $media->booking_id` and return 422 otherwise (`app/Http/Controllers/BookingController.php`, in each method), (2) update the four review fields, (3) write a `BookingActivity` row with metadata, (4) dispatch `MediaApproved` / `MediaRejected`.

**Weaknesses — do not copy:**

- **The parent/child binding check is advisory, not enforced.** The controller compares two integers in PHP. There is no scoped route binding, so a mismatched `{booking}/{media}` pair is a 422 rather than a 404 — and any *new* endpoint that forgets the check has no safety net. The database does not constrain it either.
- **`deleteMedia` has an authorization gap, `CURRENT_FACT`.** `BookingController::deleteMedia` (`:153-163`) performs the parent/child check and then deletes — but, unlike `uploadMedia` at `:116-118`, it **never checks that the caller owns the booking**. Route `routes/api.php:279` sits in the authenticated group; any authenticated user who knows a booking id and a media id can delete that media. This is a pre-existing defect. **Recorded, not fixed** — `AGENTS.md` §18 forbids opportunistic remediation, and §3 would require characterization tests first. It is listed in artifact 19 as a risk that Creative must not inherit.
- **Everything is on the `public` disk.** An *unapproved* ad creative is downloadable by URL before anyone has approved it. There is no separation between "uploaded" and "safe to serve".
- **No versioning.** `BookingMedia::delete()` removes the row and the file. There is no soft delete, no supersede, no history. For an ad asset that is tolerable; for a paid creative deliverable under revision it is not.
- **No checksum, no detected MIME, no size enforcement in the model.** Size and type exist as columns but are whatever the upload path happened to record.

`INFERENCE`: `booking_media` is a good template for the **review state machine** and a bad template for **file handling**. Artifact 06 makes the same split. Creative should take the four review columns and the activity-log discipline, and reject the storage target, the delete semantics, and the advisory parent/child check.

### 2.7 What does not exist — `CURRENT_FACT`

| Capability | State | Evidence |
|---|---|---|
| Signed URLs | **Absent** | `grep -rn "temporarySignedRoute\|signedRoute\|temporaryUrl\|hasValidSignature"` over `app/`, `routes/`, `config/`, `tests/` → **0 matches** |
| Central file record | **Absent** | No such table (§2.5) |
| File versioning / supersede | **Absent** | Only `booking_media` holds files, and it hard-deletes |
| Per-file ownership / ACL | **Absent** | No file-bearing column has an owner other than by inference through a parent row |
| Per-file authorization on download | **Absent** | Downloads are `APP_URL/storage/...` static file serving. There is no download controller anywhere in the route table. |
| Retention / legal hold / right-to-erasure | **Absent** | No column, no config key, no job. |
| File-content scanning (virus/malware) | **Absent** | No scanner, no `scan_status` column |
| Checksum / dedup by content | **Absent** | No `sha256`/`hash` column on any file-bearing table |
| EXIF stripping | **Absent** | `MediaHelper::copyAsIs` preserves original bytes |
| Quota / total-size enforcement | **Partially.** `MediaConfig::maxTotalUploadSizeMb()` (default 500) **exists but is never called** — `grep` finds no consumer. | Dead config. Recorded, not fixed. |

---

## 3. Reuse / extend / build, per Creative file kind

The decision table. `reuse_types` follow artifact 05's vocabulary so the two artifacts read as one set.

| Creative file kind | Reuse decision | Why |
|---|---|---|
| **Portfolio images** (public-facing creator work) | `DIRECT_REUSE` storage pattern + `NEW_IMPLEMENTATION` of the record | Public visibility is correct for these. Still needs a `creative_files` row for ownership, deletion and referential integrity — a bare `image` column cannot answer "which portfolio item is this?" |
| **Creator avatar / cover** | `REUSE_AS_PATTERN` | `users.avatar` exists (`app/Support/Configs` aside, the column is in the users migration). A creator's avatar belongs on the `creators` row via a `creative_files` reference, **not** as a new bare column. Do not add creative columns to `users` — artifact 05 and `AGENTS.md` §21 both forbid it. |
| **Brief attachments** (client → creator) | **`NEW_IMPLEMENTATION`** | Confidential. Must be private. No private delivery mechanism exists. |
| **Project files** (working files shared between the two parties) | **`NEW_IMPLEMENTATION`** | Confidential, two-party. |
| **Revision files** (round N of a change) | **`NEW_IMPLEMENTATION`** | Confidential, two-party, and needs round-scoped visibility. |
| **Delivery files** (creator → client) | **`NEW_IMPLEMENTATION`** | Confidential **until accepted**. The one place a "safe to serve" state transition matters. |
| **Preview / low-res previews of delivery files** | **`NEW_IMPLEMENTATION`** | Non-obvious: a preview is *also* confidential if the full delivery is. Do not assume previews are public. `OPEN DECISION`. |
| **Source files** (PSD, AI, Figma export, project archives) | **`NEW_IMPLEMENTATION`**, highest sensitivity | Frequently the client's most valuable IP. `OPEN DECISION` on whether a source file is ever downloadable by the client, or client-only-until-accepted-forever. |
| **Brief reference / mood-board imagery in a public listing** | `DIRECT_REUSE` for the storage pattern, **but only if a copy is made onto the public disk by an explicit, audited publish step** | Never let a private upload land on a public path merely because it was "shown" in a listing. The publish step must be a separate, authorized action that creates a distinct public artefact with a distinct record. |
| **Invoices / payout documents** | **`NEW_IMPLEMENTATION`** | Financial artefacts. Highest retention obligations. `OPEN DECISION` on retention. |
| **Upload validation policy (extension/mime/size/count)** | `EXTEND + WRAP` | Reuse the *pattern* of `MediaConfig`/`BookingConfig` (a typed config object reading `SettingsService`, so limits are admin-tunable without a deploy). Do **not** reuse `BookingConfig::maxFilesPerUpload()` (default 10, `app/Support/Configs/BookingConfig.php:113-116`) — it is a booking limit. `RECOMMENDATION`: a `creative.media.*` settings group plus a `CreativeConfig`, enforced in Creative FormRequests, never inside a service. |
| **Image compression** | `WRAP` — reuse the concept, not the constant | The 70 KB budget and the 80→20 ladder are marketing-image policy. Creative needs its own targets. The processor itself (GD, `encodeWebp`) is reusable. Resizing is not available at all (§2.4). |
| **File deletion** | **`NEW_IMPLEMENTATION`** | `booking_media`'s hard delete is wrong for a paid deliverable. Creative needs soft-delete plus a delayed physical purge, because a reversal dispute may need the artefact to still exist. |
| **Download authorization** | **`NEW_IMPLEMENTATION` — the largest single gap** | There is no download controller, no policy layer, and no private-file mechanism. See §4. |

**The blunt statement, which the task requires and which is `CURRENT_FACT`-backed:** *there is no reusable private-file delivery mechanism in this repository.* Any plan that says "reuse the storage layer for Creative attachments" is describing a security defect, not a reuse.

---

## 4. Proposed Creative file-access contract (documentation only)

`RECOMMENDATION`. **Nothing below is implemented.** This is a proposed interface shape for a later, separately authorised task.

```php
namespace App\Creative\Storage\Contracts;   // namespace is itself an OPEN DECISION (see §10)

interface CreativeFileAccessContract
{
    /** Persist an already-stored object as a Creative file record. Returns the record id. */
    public function register(StoredObject $object, FileContext $context): CreativeFileRecord;

    /**
     * Resolve an authorization-checked, time-limited access descriptor for a file.
     * MUST perform the authorization check itself (or be given an already-authorized
     * subject) and MUST NOT return a durable public URL for a private file.
     */
    public function issueAccess(CreativeFileRecord $file, AuthorizedSubject $subject, AccessIntent $intent): AccessDescriptor;

    /** Logical soft-delete. Physical purge is a separate, delayed operation. */
    public function retire(CreativeFileRecord $file, RetiredBy $actor, Reason $reason): void;

    /** Physical purge. Only callable by a retention policy, never by a request. */
    public function purge(CreativeFileRecord $file): void;
}
```

Design constraints that make this contract *not* a copy of `StorageContractInterface`:

1. **The disk is data, not a constant.** The Creative adapter is bound per-visibility, or the contract takes a `Visibility` argument. It must be impossible to store a `private` file onto a `public` disk by forgetting an argument — the disk choice is a consequence of the visibility, resolved centrally.
2. **Access is an outcome, not a URL.** `issueAccess()` returns an `AccessDescriptor` (expiry, subject, intent, audit reference). The HTTP layer turns that into a redirect to a **short-lived signed URL** or streams the bytes. The durable public URL is returned only for genuinely public files.
3. **Authorization is inside the boundary.** The contract takes an already-identified subject and a stated intent (`view_delivery`, `download_source`, `admin_audit`), and it is the contract's job to decide. This is what makes the file decision auditable and testable in one place instead of scattered across controllers.
4. **Write and read are asymmetric.** Upload does not require a read capability, and the two must not share a verb — the bug class in this repository is precisely a write path that is trivially reachable and a read path that is trivially public.
5. **The contract is the only thing Creative code is allowed to depend on.** No Creative class may import `Illuminate\Support\Facades\Storage`. This also satisfies `ARCHITECTURE_RULES.md` Rule 11 (inner layers never reference an outer layer's implementation).

`OPEN DECISION`: does the Creative private store sit on the existing `local` disk (`storage/app/private`, with `serve => true`), on a new dedicated local disk, or on the dormant `s3` disk? A new dedicated disk (e.g. `creative-private`) is the cleanest — it makes the isolation greppable and prevents an operator from pointing it at the wrong root — but it is a `config/` change and therefore a deployment concern, not a code concern.

---

## 5. Visibility rules

`RECOMMENDATION`, with each rule tied to the threat it prevents.

| Visibility | Disk | Delivery | Who may access |
|---|---|---|---|
| `public` | `public` | Static URL from `APP_URL/storage` | Anyone. Only for content that is *intended* to be public: a creator's published portfolio image, a published service cover, a published preview in a public listing. |
| `private` | dedicated private disk | Short-lived signed URL, or streamed through an authorized download action | Exactly the parties entitled to the owning aggregate, plus admin roles holding the matching `creative.*.view` permission. Never a bare URL. |
| `party` (a proposed third tier) | `private` | Same as `private`, but the subject must be a *named party to a specific engagement* rather than merely the record's owner | Client and creator of **that** project only. This is the tier that brief attachments, project files, revision files, deliveries and source files live in. `OPEN DECISION`: whether this is a real tier or just a `private` row with a stricter policy check. Recommendation: keep it one tier (`private`) and make the policy the discriminator, so the storage layer stays simple. |

Rules:

1. **Visibility is decided at registration, never at read time.** A row is created with its visibility, and changing visibility is an authorized, audited transition (`publish`), not a side effect of who happens to request the file.
2. **The public disk may only ever receive content that is already public.** A "preview" generated from a private delivery is still private. If a public preview is genuinely wanted, it must be a *new artefact* written to the public disk by an explicit publish step, with its own `creative_files` row and its own audit entry. This is the rule that prevents the "we'll just copy it to public so the client can see it" class of incident.
3. **A `private` file must never produce a response containing its storage path.** Paths leak: they reveal the disk layout, they permit enumeration against `unique(disk, path)`, and they survive in browser history and proxy logs. The API returns a `public_id`, never a path.
4. **Signed-URL TTL is per intent, and shortest-first.** `OPEN DECISION` on actual values. `INFERENCE` from the repository's own conventions: nothing in this codebase has a precedent, so the values are a product/security decision, not a derivable one. A download of a source file should have a materially shorter TTL than a page view of a portfolio image.
5. **Every `issueAccess()` on a `private` file writes an audit row.** The creator's and client's access to their own work is commercially sensitive in both directions (a client downloading source files, a creator downloading the client's brief).

---

## 6. Path and filename conventions

`RECOMMENDATION`, chosen to follow conventions that already exist rather than to invent a new dialect.

**Path.** Server-generated, never client-supplied. Contrast `FileController.php:13` (`$path = $request->path`) — that is the defect to avoid. Proposed shape:

```text
{disk}/{aggregate}/{aggregate_public_id}/{context}/{random}.{detected_extension}

examples (all hypothetical, nothing exists):
  public   portfolio/{creator_public_id}/{item_public_id}/{random}.webp
  private  briefs/{brief_public_id}/attachment/{random}.pdf
  private  projects/{project_public_id}/files/{random}.zip
  private  projects/{project_public_id}/revisions/{round}/{random}.psd
  private  projects/{project_public_id}/deliveries/{sequence}/{random}.png
```

Why the `public_id` and not the auto-increment id in the path: the path is stored in a column and may end up in a log line or an error message; a sequential id there is a free enumeration oracle for anyone who reads it. This is the same reasoning as artifact 09's `public_id` convention.

**Filename.**

- **Random, generated server-side.** Follow the existing convention exactly: `time() . '_' . Str::random(10) . '.' . $ext` — `app/Services/MediaService.php:35` and `app/Helpers/MediaHelper.php:71`. It is not cryptographically strong, and it does not need to be *unguessable* — it needs to be *unreferencable*, because access is authorized, not obscurity-based. `INFERENCE`: guessing resistance is a defence-in-depth bonus, not the control. Do not treat it as the control.
- **Extension derived from the detected type, never from the client.** `getClientOriginalExtension()` is attacker-controlled. Detect with the file's actual content (or PHP's fileinfo) and normalise the extension from the detected type against an allow-list. Note the existing `MediaHelper` bug class: `file_path` gets `.webp` while `original_name` keeps the original extension — Creative must store the two separately and deliberately, and never derive a served `Content-Type` from the client's filename.
- **The original filename is metadata, not a path component.** It is useful to humans and dangerous in a path (traversal, control characters, absurd length, misleading `Content-Disposition`). Store it in a column, sanitise it on read, and never interpolate it into storage or response headers without escaping.
- **No user-supplied segment anywhere in the path.** Not the creator id, not the project id, not the original filename.

---

## 7. Deletion and versioning policy

`RECOMMENDATION` for the mechanism; `OPEN DECISION` for every retention duration.

**Versioning.**

- Uploaded files are **immutable**. A new upload is a new `creative_files` row, and the row it supersedes gains a `superseded_by` pointer and a `superseded_at` timestamp. Nothing overwrites bytes.
- This matters commercially, not just technically: a delivered file that a client later disputes must remain retrievable exactly as delivered. `AGENTS.md` §10's "fix forward, never rewrite a stored fact" applies to the artefact as much as to the amount.
- The `booking_media` hard delete is the anti-pattern (`app/Http/Controllers/BookingController.php:159-160`: delete the bytes, then delete the row, no record either survives).

**Deletion — three distinct operations, never conflated:**

| Operation | Who | Effect | Retains bytes? |
|---|---|---|---|
| `retire` (soft delete) | The owner or an admin, per policy | Row marked retired; excluded from all lists and all access | **Yes** |
| `purge` (physical) | A retention policy only — never a request handler | Bytes removed | No |
| `revoke` (access withdrawal) | Admin, on an abuse or legal report | Access denied immediately; row and bytes intact | Yes |

`OPEN DECISION` on all of:

- How long bytes are retained after `retire`. No precedent exists anywhere in this repository — there is no retention column, no retention job, no legal-hold concept.
- Whether a creator deleting a portfolio item must also purge the bytes or whether the bytes are retained because they appear in a *delivered* file that a client legitimately holds. `INFERENCE`: these are different objects that happen to share a byte range in a naive design. A published portfolio image and a source file delivered under contract have different retention obligations. Recommendation: a `retention_class` on `creative_files`, decided per kind.
- Whether there is a right-to-erasure obligation and how it interacts with the immutability of delivered work. **This is a legal question, not an engineering one, and this audit does not answer it.**
- Whether a client deleting a project deletes the creator's work. Recommendation: **no** — the creator holds an independent interest in their delivered portfolio piece. This must be a product decision, and getting it wrong destroys a creator's asset.

---

## 8. The security defect to be recorded

Stated plainly, because the task requires it and because it will be proposed by someone during implementation.

> **Recorded risk:** this repository's only file storage path writes to `storage/app/public`, which is symlinked to `public/storage` and served at `APP_URL/storage`. Every file that passes through `FileController::upload`, `FileController::videoupload`, `MediaService::upload`, or `MediaHelper::compressUploadedImage` is **world-readable at a guessable URL**, with no authorization, no expiry, and no revocation. For marketing images this is merely untidy. For Creative — brief attachments, undelivered work, source files, earnings documents — it is a **P0 confidentiality defect**.

Mitigations, all `RECOMMENDATION`, all deferred to a later authorised task:

1. Creative file writes go to a **private** disk only. There is no code path by which a Creative upload can reach the `public` disk except an explicit, authorized `publish` action.
2. Private reads go through an **authorized, audited, expiring** descriptor (§4, §5).
3. Creative validation is **MIME-based, not extension-based**, in a **FormRequest**, with a size cap, a per-context count cap, and a server-generated path.
4. `FileController` is **not** on the Creative path. If Creative needs the two general-purpose endpoints for marketing content, that is a separate conversation about hardening `FileController` itself — and per `AGENTS.md` §3, hardening it is a *behaviour change* that requires characterization tests, a fix, a test, a document, and an explicit report. **This audit does none of that.**

---

## 9. What Creative must build from scratch

`RECOMMENDATION` — the honest new-build list, since "reuse" has been covered:

1. `creative_files` table (record: disk, path, visibility, owner aggregate, size, detected mime, checksum, `public_id`, `retention_class`, `superseded_by`, timestamps).
2. A private disk + a `CreativeFileAccessContract` adapter.
3. A download/stream action with authorization + audit, per file.
4. A publishing action (`public` visibility) that is explicit and audited.
5. MIME-level validation policies per file kind, in FormRequests.
6. Retire/purge/revoke semantics, with a retention policy that is `OPEN DECISION`.
7. A thumbnail/resize capability (the existing image contract cannot resize).
8. EXIF handling (strip for portfolio, decide for source files) — `OPEN DECISION` per kind.
9. Ownership enforcement at the file layer, so a file is not reachable by knowing only its id or path.

---

## 10. Open decisions

| id | Question | What is missing |
|---|---|---|
| `OD-ST-01` | Where do Creative private files physically live — the existing `local` disk, a new dedicated disk, or the dormant `s3` disk? | An operations/deployment decision. Note the `s3` disk has `throw => false`, so silent I/O failure is currently configured. |
| `OD-ST-02` | Are delivery **previews** public or private? | A product decision. A preview of confidential work is still confidential work. This audit recommends private-by-default. |
| `OD-ST-03` | May a client ever download a creator's **source files**, and if so for how long after acceptance? | A commercial/legal decision. No repository precedent exists. |
| `OD-ST-04` | How long are files retained after a soft delete, and is there a legal hold? | A legal/compliance decision. Nothing in this repository has any retention concept, so nothing can be inferred. |
| `OD-ST-05` | Does a creator deleting a portfolio item purge the bytes, or retain them because a client holds a delivered copy? | A product decision with a data-destruction consequence. |
| `OD-ST-06` | Is there a right-to-erasure obligation that conflicts with the immutability of delivered work? | A legal question. **Deliberately not answered here.** |
| `OD-ST-07` | Is file-content scanning (malware) in scope, and does it gate visibility? | An operations decision, and it determines whether a queue worker is mandatory — which ties to `OD-TC-18` in artifact 16. |
| `OD-ST-08` | Are EXIF metadata stripped, and is there a per-kind policy? | A privacy/product decision. Note portfolio images commonly carry GPS coordinates. |
| `OD-ST-09` | What are the signed-URL TTLs per intent? | A security decision. No precedent exists, so no value can be derived. |
| `OD-ST-10` | What is the namespace and location of the Creative file contract and adapter? | An architect decision (`AGENTS.md` §20). The approved target tree has `app/Infrastructure/Storage`; whether Creative-private is an adapter there or a Creative-local port is undecided and affects `ARCHITECTURE_RULES.md` Rule 11 compliance. |
| `OD-ST-11` | Do Creative uploads have a per-user storage quota, and is it enforced synchronously or by a job? | A product/operations decision. `MediaConfig::maxTotalUploadSizeMb()` exists but has no consumer, so there is no enforcement mechanism today. |
| `OD-ST-12` | Is a checksum required, and is it for dedup, integrity, or tamper-evidence? | A product/legal decision. Determines whether `creative_files` needs a unique index on `(checksum, owner)`. |

---

## 11. Discrepancies vs existing artifacts

`CURRENT_FACT`. Current source wins.

| Artifact / source | Claim | Source truth | Resolution |
|---|---|---|---|
| Artifact 05, `capabilities[File storage].current_implementation` | *"10 controllers call `Storage::` directly, bypassing the contract."* | **6 controllers** bypass it: `AuthorController`, `GalleryController`, `JobApplicationController`, `ScreenController`, `ScreenProviderPortalController`, `SuccessStoryController`. Adding `app/Models/Screen.php:95` gives **7 non-adapter files** in total. There are 11 `Storage::disk('public')` call sites and 5 `->storeAs(..., 'public')` sites, which is likely where the figure 10 came from — a call-site count reported as a file count. | **Artifact 05 overstates the file count.** The substance (the contract is bypassed, not enforced) is correct. Not corrected in place — `AGENTS.md` §21 requires the conflict to be reported, not silently resolved. |
| Artifact 05, `capabilities[File storage].current_implementation` | *"The 'local' disk (storage/app/private) exists but is never used."* | Confirmed. `grep -rn "'local'" app/` → 0 matches. | Agrees. |
| Artifact 05, `capabilities[File storage].risk` | *"There are zero signed URLs in the repository (0 matches for temporarySignedRoute/signedRoute/temporaryUrl)."* | Confirmed, and this audit widens the search to `hasValidSignature` and to `tests/` and `config/` — still 0 matches. | Agrees. |
| Artifact 05, `capabilities[Image processing]` | *"2 methods: isAvailable(), encodeWebp(). GD only. No resizing, no EXIF stripping, no format preservation."* | Confirmed. `INFERENCE` added: `MediaConfig::maxTotalUploadSizeMb()` exists with no consumer, and `Storage::url()` resolves against the *default* disk rather than `self::DISK`. | Agrees; two new observations recorded. |
| Artifact 08, `file_bearing_columns_without_a_central_file_record.columns` | 16 columns across 14 tables. | Re-derived independently from all 112 migrations: **exactly the same 16 columns**. Two additional non-file matches (`blogs.image_alt`, `qr_codes.target_url`) were correctly excluded by artifact 08. | **Agrees. No discrepancy.** Recorded because independent re-derivation was required. |
| Artifact 08, `tables[job_applications].comments` | *"resume path is stored on the PUBLIC disk — applicant PII is publicly fetchable."* | Confirmed: `app/Http/Controllers/JobApplicationController.php:65` deletes from `Storage::disk('public')`, so the write is also on the public disk. | Agrees. This is a live instance of the §8 defect, in production code, today. |
| Artifact 08, `counts.explicit_foreign_keys` | 91 | `AGENTS.md` §4 states 94. | **RESOLVED 2026-09-26 by re-measurement:** 96 foreign-key definitions = 94 `->constrained()` + 2 legacy `->foreign()`; the 91 was an undercount and 74 tables overstates by 2 duplicate `Schema::create` calls (`pricing_peak_rules`, `pricing_weekend_rules`). | Resolved; source wins. |
| `AGENTS.md` §8 | Declares `app/Domain/Creative` as a declared-but-empty capability directory. | **False on disk.** `app/Domain/Creative` does not exist, and neither do `app/Services/Creative`, `app/Repositories/Creative` or `app/Http/Controllers/Creative`. `find app -type d -empty` returns nothing. | Already recorded by `AGENTS.md` itself. Restated here because it is load-bearing: Creative storage is 100% greenfield, so nothing here is an "empty directory to fill". |
| Task brief baseline | *"`MediaHelper` compresses uploaded images via an `ImageProcessingContractInterface`"* | Confirmed. | Agrees. |
| Task brief baseline | *"`FileController::upload`/`videoupload` have no FormRequest validation"* | Confirmed. Raw `Request`, no `validate()` call, no FormRequest, plus client-controlled directory and client-controlled extension. | Agrees, with three additional defects found (client-controlled directory, preserved client extension, no rate limiting). |

---

## 12. Scope statement

This artifact is documentation. It created exactly one file — itself. It modified no application code, no migration, no route, no test, no config, and no existing audit artifact. No test suite was executed, because nothing executable changed; the brief's baseline (673 passed / 2 failed / 1 warning / 4 skipped, with the 2 failures being pre-existing message drift) is recorded as **reported**, not as re-verified. Per `AGENTS.md` §24, no architecture-conformance claim is made here, because `tests/Unit/Architecture/ArchitectureRuleTest.php` was not run.

**Cross-references:** artifact 16 (transaction boundaries and the concurrency requirements that constrain upload and delivery), artifact 18 (notification mechanics, including who gets told when a file is available), artifact 19 (the security risk register — `file access leakage` and `IDOR` are the categories this document feeds).
