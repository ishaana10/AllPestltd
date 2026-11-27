# All Pest Fumigation Application Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the All Pest fumigation application: public requests → treatments with a rules-checked ROF → signed per-container certificates → invoices in Nuvis ERPX, with a full audit trail.

**Architecture:** `ishaana10/AllPestltd` is a snapshot copy of Nuvis StudioX (the "one install per customer" model). All Pest code lives in its own directories (`core/allpest/`, `api/allpest/`, `modules/allpest/`, `tests/Unit/AllPest/`, class prefix `Ap`) so StudioX files are touched as little as possible. A pure-PHP rules engine (Tasks 2–6) is tested against the published methodology tables; services and screens sit on top. **Deviation from spec §4:** StudioX's `NuPluginManager` is never called by anything and expects a class the sample plugin does not define, so the plugin route is unusable; this plan uses the spec's stated fallback, a module inside the repo.

**Tech Stack:** PHP 8.1+ (strict types), MySQL (SQLite in unit tests, as StudioX already does), vanilla JS for tablet screens, GD for image compression, Open-Meteo for forecasts, PHPUnit 10.5 (project standard).

**Spec:** `docs/superpowers/specs/2026-11-27-allpest-fumigation-design.md`

## Global Constraints

- PHP 8.1+, MySQL, A2 Hosting shared hosting; no Redis; no hosting upgrade; cron available.
- User ids are UUID strings, never cast to int. All new tables use `CHAR(36)` UUID primary keys (`ApId::uuid()`).
- Exports ship with blank email configuration.
- PDFs (ROF, certificate, invoice) are generated on demand and never stored on the server; the retained record is the data, signature images and a SHA-256 of each issued certificate's content.
- Prices come only from ERPX `selling_price`; All Pest keeps a read-only cache.
- The supervising officer has no account; the officer's signature is drawn on the technician's tablet.
- Uploads are compressed: images to a long edge of 1600 px at JPEG quality 75; EXIF is lost, so upload time, user, device and SHA-256 are recorded by the server.
- Store UTC; display Fiji time (UTC+12, `Pacific/Fiji`).
- Records, photos and signatures are kept at least 5 years and never purged automatically.
- Methodology v3.0 constants: equilibrium ≤ 15 %; TLV 5 ppm; chloropicrin supply dose ÷ 0.98; dose compensation +8 g/m³ per 5 °C or part thereof below 21 °C (only if the schedule allows); ambient treatment blocked when forecast minimum ≤ 10 °C; monitoring locations: general < 30 m³ one, ≥ 30 m³ three, each container in a sheeted stack at least three; perishables < 5 m³ one, ≥ 5 m³ three; end-of-exposure top-up only once, minimum 4 h extension.
- One certificate per **passing container**; failed containers get none but are still invoiced. Invoice release mode per install: `auto` or `reviewed` (default `reviewed`).
- A signed ROF is read-only; corrections are new versions that keep the original.
- Treatment schedules are entered manually by the admin; standards tables are built in.
- AFAS portal upload stays manual.

## Review Focus

1. **Bad reading entries** (`>4`, `4 ppm`, blank, negative, `4,3`): rejected with a clear message, never stored as a number. Tests in Tasks 4 and 13.
2. **Tablet loses signal mid-entry, then resubmits** the same reading set: no duplicate, no lost data. Tests in Tasks 13 and 21.
3. **Hostile or oversized public requests** (bot spam, 50 MB file, 500 containers, a renamed `.exe`): refused, nothing stored. Tests in Tasks 8 and 11.
4. **Impossible physical inputs** (zero or negative dimensions, absurd volume, forecast of −60 °C, exposure of 0 h): rejected before they reach dose or standard calculations. Tests in Tasks 3, 5 and 13.
5. **Retries and double clicks create duplicates** (two certificates for one container, two invoices after an ERPX timeout, two admins releasing at once): exactly one results. Tests in Tasks 15, 16 and 17.

## File Structure

- `core/allpest/` — `ApId`, `ApStandards`, `ApDose`, `ApReadings`, `ApTemperature`, `ApForecastClient`, `ApResult`, `ApSchema`, `ApUploads`, `ApTreatments`, `ApSigning`, `ApCertificates`, `ApErpxClient`, `ApInvoices`, `ApAuditPack`, `ApRetention`, `ApReminders`, `ApAccess`, plus small repositories (`ApTechnicians`, `ApEquipment`, `ApSchedules`, `ApStock`, `ApRequests`).
- `api/allpest/*.php` — thin JSON endpoints, one per area, each calling a service.
- `modules/allpest/*.php` + `assets/js/allpest/*.js` — admin and tablet screens.
- `public_allpest_request.php` — public intake page (repo root, beside `public_form.php`).
- `cron/allpest_worker.php` — queue retry and reminders.
- `tests/Unit/AllPest/*Test.php`; `tests/AllPest/run.php` — dependency-free runner for this sandbox.

---

### Task 0: Seed the repository from StudioX

**Files:** Create everything from `ishaana10/nuvisstudiox` @ `ce87d3248432354edca392ad38242d7ada4be8fa`; Create `UPSTREAM.md`; copy `docs/superpowers/` (spec and this plan already in the clone at `/home/claude/allpestltd`).

**Interfaces:** Produces the repo layout every later task assumes.

- [ ] **Step 1:** Copy the StudioX tree (without `.git`, `vendor/`, `uploads/`, `*.sqlite`) into `/home/claude/allpestltd`; write `UPSTREAM.md` giving the source repo, the sha above, and the rule "All Pest code lives only in `*/allpest/` directories; bring StudioX updates in with `git diff <old>..<new> | git apply`".
- [ ] **Step 2:** Verify: `php -l index.php config.php core/Auth.php api/updater.php` prints no errors; `git status` shows no `config.local.php`.
- [ ] **Step 3:** Read `api/updater.php`; record in `UPSTREAM.md` whether its git pull would overwrite or delete the `*/allpest/` directories (it should not; expected: it runs git operations only).
- [ ] **Step 4:** Commit as `chore: import Nuvis StudioX ce87d32` plus a second commit `docs: add All Pest design and plan`; push to `origin main`.

### Task 1: Dependency-free test runner

**Files:** Create `tests/AllPest/run.php`, `tests/Unit/AllPest/SmokeTest.php`. `composer install` is blocked here (packagist unreachable), so real PHPUnit cannot run in this sandbox; it stays the standard on a normal machine.

**Interfaces:** Produces `php tests/AllPest/run.php <file|dir>`: loads test classes, runs `test*` methods, prints `OK (n tests)` or each failure, exits 1 on failure. When `PHPUnit\Framework\TestCase` is not defined it defines a minimal one with `assertSame`, `assertEquals`, `assertEqualsWithDelta`, `assertTrue`, `assertFalse`, `assertNull`, `assertCount`, `expectException`. Tests extend `PHPUnit\Framework\TestCase` unchanged, so they also run under real PHPUnit.

- [ ] **Step 1:** Write `SmokeTest` with `testPasses` (`assertSame(2, 1 + 1)`) and `testFailsOnPurpose` guarded by an env var `AP_SMOKE_FAIL=1`.
- [ ] **Step 2:** Run `AP_SMOKE_FAIL=1 php tests/AllPest/run.php tests/Unit/AllPest/SmokeTest.php`; expect exit 1 and one failure line.
- [ ] **Step 3:** Implement the runner per Interfaces.
- [ ] **Step 4:** Run without the env var; expect `OK (2 tests)` (the guarded test passes when unset).
- [ ] **Step 5:** Commit `test: add dependency-free runner`.

### Task 2: Standards tables

**Files:** Create `core/allpest/ApStandards.php`; Test `tests/Unit/AllPest/ApStandardsTest.php`.

**Interfaces:** Produces `ApStandards` (static):
- `standardAt(float $initialDose, float $hoursSinceStartPoint, bool $wholeGram = false): float` — dose × Table 4 retention for the greatest listed hour ≤ the reading time (hours 0, 0.5, 1, 2…12, 16, 20, 24, 28…48); 2 decimals; `$wholeGram` rounds up.
- `topUpBand(float $initialDose, float $standard): array{min: float, max: float}` — ±5 for doses 32–56, ±8 for 64–152.
- `startPointStandard(float $initialDose, float $minutesSinceInjection, bool $eightyClass = false): ?float` — 85 % (15–30 min), 75 % (30–60), 70 % (> 60); `null` under 15 min; the 80 % class returns 0.8 × dose from 15 min.

- [ ] **Step 1: Failing tests** with the Table 6/Table 4 values: `standardAt(48,24)=14.4`, band `[9.4,19.4]`; `standardAt(64,2)=38.4`, band `[30.4,46.4]`; `standardAt(32,48)=8.0`, band `[3.0,13.0]`; `standardAt(128,4)=64.0`, band `[56.0,72.0]`; `standardAt(32,3)=17.54` and `18.0` with `$wholeGram`; `standardAt(48,2.5)=28.8` (uses the 2 h row); `standardAt(48,30)=13.99` (28 h row); `startPointStandard(48,20)=40.8`, `(48,45)=36.0`, `(48,90)=33.6`, `(48,10)=null`, `(48,90,true)=38.4`. Note: the published Table 3 shows 15.1 for dose 40 at 48 h maximum top-up while the formula and Table 6 give 15.0; assert 15.0.
- [ ] **Step 2:** Run `php tests/AllPest/run.php tests/Unit/AllPest/ApStandardsTest.php`; expect failures (class missing).
- [ ] **Step 3:** Implement the three methods; store the Table 4 retention percentages as one constant array.
- [ ] **Step 4:** Re-run; expect all pass.
- [ ] **Step 5:** Commit `feat(allpest): methodology standards tables`.

### Task 3: Dose calculations

**Files:** Create `core/allpest/ApDose.php`; Test `tests/Unit/AllPest/ApDoseTest.php`.

**Interfaces:** Produces `ApDose` (static; every method throws `InvalidArgumentException` for non-positive volume, dose rate or increment):
- `volume(float $heightM, float $depthM, float $lengthM): float`
- `compensatedDoseRate(float $doseRate, float $forecastMinC, float $referenceC, bool $allowed): float`
- `doseGrams(float $volumeM3, float $doseRateUsed, float $mbFraction = 1.0): float`
- `roundUpToIncrement(float $grams, float $increment): float`
- `topUpGrams(float $volumeM3, float $maxTopUp, float $lowestReading, float $mbFraction = 1.0): float`
- `additionalDoseBeforeStart(float $volumeM3, float $initialDoseRate, float $lowestReading, float $mbFraction = 1.0): float`

- [ ] **Step 1: Failing tests:** `volume(2.5,2.5,12.0)=75.0`; `doseGrams(75,48)=3600.0`; `doseGrams(75,48,0.98)≈3673.47` (delta 0.01); `roundUpToIncrement(3673.47,100)=3700.0` and `(3673.47,680)=4080.0`; `compensatedDoseRate(48,15,21,true)=64.0`, `(48,16,21,true)=56.0`, `(48,20.5,21,true)=56.0`, `(48,21,21,true)=48.0`, `(48,15,21,false)=48.0`; `topUpGrams(40,19.4,12.0)=296.0`; `additionalDoseBeforeStart(40,48,36)=480.0`; zero or negative volume, `volume(0,2,2)`, and `roundUpToIncrement(10,0)` all throw.
- [ ] **Step 2:** Run the file; expect failures.
- [ ] **Step 3:** Implement per Interfaces; compensation steps are `ceil(shortfall / 5)` where shortfall is `referenceC - forecastMinC`.
- [ ] **Step 4:** Re-run; expect pass.
- [ ] **Step 5:** Commit `feat(allpest): dose calculations`.

### Task 4: Reading checks

**Files:** Create `core/allpest/ApReadings.php`; Test `tests/Unit/AllPest/ApReadingsTest.php`.

**Interfaces:** Produces `ApReadings` (static):
- `parseReading(string $raw): float` — plain non-negative decimal only; throws `InvalidArgumentException` on `""`, `">4"`, `"4 ppm"`, `"-1"`, `"4,3"`.
- `equilibriumPercent(array $values): float`; `isEquilibrium(array $values): bool` (≤ 15.0).
- `evaluateSet(array $readingsByLocation, float $standard, float $minTopUp): array{allMeet: bool, belowLocations: array, lowest: float, topUpPossible: bool}`
- `requiredLocations(string $type, float $volumeM3, int $packagingTypes = 1, bool $inSheetedStack = false): int` (`$type` is `'general'` or `'perishable'`).
- `readingsDue(float $exposureHours): array` — hours at which sets are due.
- `tlvOk(float $ppm, float $limit = 5.0): bool` (≤ limit).

- [ ] **Step 1: Failing tests:** `parseReading('4.3')=4.3` and the five rejects above; `equilibriumPercent([46,40])=15.0`, `isEquilibrium` true; `[46.1,40]` false; `evaluateSet(['A'=>14.4,'B'=>15.0,'C'=>14.0],14.4,9.4)` → `allMeet=false`, `belowLocations=['C']`, `lowest=14.0`, `topUpPossible=true`; with `C=>9.0` → `topUpPossible=false`; `requiredLocations('general',29.9)=1`, `(general,30)=3`, `(perishable,4.9)=1`, `(perishable,5)=3`, `(perishable,8,4)=4`, `(general,10,1,true)=3`; `readingsDue(24)=[0,24]`, `(48)=[0,24,48]`, `(30)=[0,24,30]`; `tlvOk(5.0)` true, `tlvOk(5.1)` false.
- [ ] **Step 2:** Run; expect failures.
- [ ] **Step 3:** Implement per Interfaces.
- [ ] **Step 4:** Re-run; expect pass.
- [ ] **Step 5:** Commit `feat(allpest): reading checks`.

### Task 5: Temperature and forecast

**Files:** Create `core/allpest/ApTemperature.php`, `core/allpest/ApForecastClient.php`; Test `tests/Unit/AllPest/ApTemperatureTest.php`.

**Interfaces:** Produces:
- `ApTemperature::exposureWindow(DateTimeImmutable $doseTime, float $exposureHours, int $marginHours = 2): array{from: DateTimeImmutable, to: DateTimeImmutable}`
- `ApTemperature::lowestForecast(array $hourly, DateTimeImmutable $from, DateTimeImmutable $to): float` — `$hourly` is a list of `['time' => ISO 8601, 'temp' => float]`; throws if no hours fall in range.
- `ApTemperature::ambientAllowed(float $lowestMinC): bool` — false at ≤ 10.0.
- `ApTemperature::forecastIsFresh(DateTimeImmutable $retrievedAt, DateTimeImmutable $exposureStart): bool` — true from 00:00 Fiji time on the day before the exposure start.
- `ApTemperature::coreTempOk(float $coreC, float $scheduleMinC): bool`.
- `new ApForecastClient(callable $http)` where `$http(string $url): string`; `fetch(float $lat, float $lng, DateTimeImmutable $from, DateTimeImmutable $to): array{source: string, retrieved_at: string, hourly: array}` using Open-Meteo `hourly=temperature_2m&timezone=Pacific/Fiji`; throws `ApForecastUnavailable` on HTTP failure or bad JSON. Validate: any temperature outside −30…60 °C throws.

- [ ] **Step 1: Failing tests:** window for dose `2026-12-01 18:00 +12:00`, 24 h → from `18:00` to `2026-12-02 20:00`; `lowestForecast` over a 26-point fake list returns the known minimum and ignores points outside the window; `ambientAllowed(10.0)` false, `(10.1)` true; `forecastIsFresh` true for retrieval `2026-11-30 00:00 +12:00` and false for `2026-11-29 23:59 +12:00` with exposure start `2026-12-01 18:00 +12:00`; `coreTempOk(5,5)` true, `(4.9,5)` false; fake `$http` returning Open-Meteo-shaped JSON gives parsed hourly points and a `source` containing `open-meteo`; throwing `$http` raises `ApForecastUnavailable`; a −60 °C value raises an error.
- [ ] **Step 2:** Run; expect failures.
- [ ] **Step 3:** Implement; times are parsed with their offsets and compared in UTC.
- [ ] **Step 4:** Re-run; expect pass.
- [ ] **Step 5:** Commit `feat(allpest): forecast and temperature rules`.

### Task 6: Container results

**Files:** Create `core/allpest/ApResult.php`; Test `tests/Unit/AllPest/ApResultTest.php`.

**Interfaces:** Produces `ApResult` (static):
- `evaluate(array $containers, bool $topUpStillPossible, bool $targetInsideContainers): array{overall: string, containers: array}` — `$containers` is a list of `['id' => string, 'meetsStandard' => bool]` (after any top-up); `overall` is `'pass'`, `'partial'` or `'fail'`; `containers` maps id → `'pass'|'fail'`. Exactly one failing container with `!$topUpStillPossible && $targetInsideContainers` gives `partial`; any other failure fails all containers.
- `endOfExposureTopUp(bool $schedulePermits, bool $alreadyDone, float $lowest, float $standard, float $minTopUp, ?float $scheduleExtensionHours = null): array{allowed: bool, extensionHours: float}` — default extension 4.0.

- [ ] **Step 1: Failing tests:** five containers, c3 fails, `topUpStillPossible=false`, target inside → `partial`, c3 `fail`, others `pass`; c2 and c3 fail → `fail`, all `fail`; c3 fails but `topUpStillPossible=true` → `fail`; target not inside → `fail`; all meet → `pass`; end top-up: lowest 12, standard 14.4, min 9.4 → `allowed=true`, `extensionHours=4.0`; lowest 9.0 → not allowed; `alreadyDone=true` → not allowed; schedule prohibits → not allowed; `scheduleExtensionHours=6` → 6.0.
- [ ] **Step 2:** Run; expect failures.
- [ ] **Step 3:** Implement per Interfaces.
- [ ] **Step 4:** Re-run; expect pass.
- [ ] **Step 5:** Commit `feat(allpest): per-container results and end top-up rule`.

### Task 7: Schema and ids

**Files:** Create `core/allpest/ApId.php`, `core/allpest/ApSchema.php`; Test `tests/Unit/AllPest/ApSchemaTest.php`.

**Interfaces:** Produces `ApId::uuid(): string` (v4); `ApSchema::ensure(NuDatabase $db): void`, idempotent and self-healing like `NuCustomerRequests::ensureSchema`. Tables (all prefixed `ap_`, `CHAR(36)` ids, UTC `DATETIME`): `technicians`, `officers`, `equipment`, `equipment_log`, `schedules`, `stock_lots`, `stock_movements`, `requests` (with `job_type`, default `'fumigation'`), `consignments`, `treatments`, `treatment_containers`, `reading_sets` (unique `client_uuid`), `readings`, `files` (hash, uploader, device, time), `signatures`, `certificates` (unique `container_id`), `certificate_numbers` (unique `number`), `invoice_links` (unique `request_id`), `settings`, `intake_throttle`. Column details are the builder's to decide from spec §5.3.

- [ ] **Step 1: Failing tests** (SQLite as in `tests/bootstrap.php`): `ensure` called twice does not throw; every table above exists; inserting two `certificate_numbers` rows with the same `number` throws; inserting two `certificates` rows for the same `container_id` throws; `ApId::uuid()` matches `/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/`; `requests.job_type` defaults to `fumigation`.
- [ ] **Step 2:** Run; expect failures.
- [ ] **Step 3:** Implement with portable DDL (`CREATE TABLE IF NOT EXISTS`), no MySQL-only syntax in the shared path.
- [ ] **Step 4:** Re-run; expect pass.
- [ ] **Step 5:** Commit `feat(allpest): schema and ids`.

### Task 8: Upload compression

**Files:** Create `core/allpest/ApUploads.php`, `api/allpest/upload.php`; Test `tests/Unit/AllPest/ApUploadsTest.php`.

**Interfaces:** Produces `ApUploads::store(array $phpFile, string $purpose, string $userOrIp, string $device): array{file_id: string, bytes: int, sha256: string}`. Accepts JPEG/PNG/WebP/PDF only (checked by content, not extension). Images are re-encoded to JPEG, long edge ≤ 1600 px, quality 75. Limits: image input ≤ 15 MB, PDF ≤ 5 MB, otherwise `ApUploadRejected`. Files are written under the protected uploads directory (reuse `NuFileStore::protectDir`) and metadata (hash, size, uploader, device, UTC time) goes to `ap_files`. A file is only ever served through the authenticated file endpoint.

- [ ] **Step 1: Failing tests:** a generated 4000×3000 JPEG is stored with long edge 1600 and fewer bytes; a PNG with transparency is stored as JPEG; a text file renamed `.jpg` raises `ApUploadRejected`; a 6 MB PDF raises `ApUploadRejected`; a 16 MB image raises `ApUploadRejected`; stored `sha256` equals the hash of the stored bytes; a test-only helper confirms EXIF GPS data is absent from the output.
- [ ] **Step 2:** Run; expect failures.
- [ ] **Step 3:** Implement with GD (`imagecreatefromstring`, `imagescale`, `imagejpeg`); honour EXIF orientation before stripping it.
- [ ] **Step 4:** Re-run; expect pass.
- [ ] **Step 5:** Commit `feat(allpest): compressed uploads`.

### Task 9: Technicians, officers, equipment

**Files:** Create `core/allpest/ApTechnicians.php`, `core/allpest/ApEquipment.php`, `api/allpest/technicians.php`, `api/allpest/equipment.php`; Test `tests/Unit/AllPest/ApMastersTest.php`.

**Interfaces:** Produces:
- `ApTechnicians::canSign(string $technicianId, DateTimeImmutable $now): bool` (active, registration not expired); `ApTechnicians::expiringWithin(int $days, DateTimeImmutable $now): array`.
- `ApEquipment::selectable(DateTimeImmutable $now): array` — only `in_service` items whose audit/calibration is not overdue and (for stain tubes) not expired; `ApEquipment::logEvent(string $equipmentId, string $kind, string $note, string $userId): void` with kinds `audit|discard|replace|repair`; `ApEquipment::serialLast4(string $equipmentId): string`.
- Officers are a simple list (`name`, `designation`) the admin maintains; they have no login.

- [ ] **Step 1: Failing tests:** expired registration → `canSign` false; registration expiring in 29 days appears in `expiringWithin(30)`, one expiring in 31 does not; a discarded item, an overdue-calibration item and an expired stain tube are absent from `selectable`; `logEvent('discard')` sets status `discarded` and keeps the log row; `serialLast4('ABC-12345')` is `2345`.
- [ ] **Step 2:** Run; expect failures.
- [ ] **Step 3:** Implement with PDO/`NuDatabase` queries; certificates and signature image are stored through `ApUploads` (signature images are kept as small PNGs).
- [ ] **Step 4:** Re-run; expect pass.
- [ ] **Step 5:** Commit `feat(allpest): technicians, officers, equipment`.

### Task 10: Schedules and gas stock

**Files:** Create `core/allpest/ApSchedules.php`, `core/allpest/ApStock.php`, `api/allpest/schedules.php`, `api/allpest/stock.php`; Test `tests/Unit/AllPest/ApStockTest.php`.

**Interfaces:** Produces:
- `ApSchedules::save(array $row): string` — validates name, type (`general|perishable`), dose rate > 0, exposure hours > 0, minimum temperature, flags (`compensation_allowed`, `topups_allowed`), retention class (`standard|eighty`); `ApSchedules::all(): array`.
- `ApStock::receive(string $lotId, float $grams, string $userId): void`; `ApStock::consume(string $lotId, string $treatmentId, float $grams, string $userId): void`; `ApStock::adjust(string $lotId, float $grams, string $reason, string $userId): void` (reason required); `ApStock::balance(string $lotId): float`; `ApStock::lowStock(float $thresholdGrams): array`; `ApStock::usageReport(DateTimeImmutable $from, DateTimeImmutable $to): array`.
- A consume that exceeds the balance is still recorded and flagged `discrepancy=true`; gas used must never be lost.

- [ ] **Step 1: Failing tests:** `save` rejects dose rate 0 and exposure −1; balance after `receive(10000)`, `consume(3600)`, `consume(296)` is `6104.0`; `consume` beyond balance records and flags `discrepancy`; `adjust` without a reason throws; `lowStock(7000)` lists the lot; `usageReport` sums consumption within the date range only.
- [ ] **Step 2:** Run; expect failures.
- [ ] **Step 3:** Implement; movements are append-only and the balance is the sum.
- [ ] **Step 4:** Re-run; expect pass.
- [ ] **Step 5:** Commit `feat(allpest): schedules and gas stock`.

### Task 11: Public request intake

**Files:** Create `public_allpest_request.php`, `api/allpest/request_public.php`, `core/allpest/ApRequests.php`; Test `tests/Unit/AllPest/ApIntakeTest.php`.

**Interfaces:** Produces `ApRequests::submitPublic(array $input, array $files, string $ip): array{request_id: string, reference: string}`. Input: customer (name, email, phone, optional TIN, address), site address, requested date, and 1–50 consignments (container number, seal number, commodity, origin, destination, quantity, BOL file). Insert-only; the public visitor can never read data. Controls: hidden honeypot field; per-IP limit of 5 submissions per hour (`ap_intake_throttle`); at most 50 consignments and 10 files per request; files go through `ApUploads`; emails sent to the staff address and a confirmation to the customer through `EmailService` (logging skipped sends when email is not configured). Reference format `AP-YYYYMM-NNNN`.

- [ ] **Step 1: Failing tests:** a valid 5-container request returns a reference and stores 5 consignments; the filled honeypot is rejected; the sixth submission from one IP within an hour is rejected; 51 consignments is rejected; a non-image BOL is rejected and no request row remains; an invalid email is rejected; submitting twice with the same client token does not duplicate.
- [ ] **Step 2:** Run; expect failures.
- [ ] **Step 3:** Implement; the page itself is a plain, mobile-friendly form with add/remove container rows and browser-side image compression before upload.
- [ ] **Step 4:** Re-run; expect pass. Then load the page in a browser against a dev install and submit one request (record the reference).
- [ ] **Step 5:** Commit `feat(allpest): public request intake`.

### Task 12: Request review and scheduling

**Files:** Create `api/allpest/requests.php`; Modify `core/allpest/ApRequests.php`; Test `tests/Unit/AllPest/ApRequestFlowTest.php`.

**Interfaces:** Produces `ApRequests::confirm(string $requestId, array $plan, string $adminId): void` and `ApRequests::createTreatment(string $requestId, array $containerIds, array $plan): string` where `$plan` holds scheduled start (UTC), site latitude/longitude, technician id, officer id, schedule id, enclosure type, and fumigation type (`ambient|controlled`). Statuses: `new → confirmed → scheduled → in_progress → completed`; a request may have several treatments. A job type other than `fumigation` is accepted by the backbone but has no treatment screens yet.

- [ ] **Step 1: Failing tests:** confirm then schedule moves statuses in order; scheduling with an expired technician (`canSign` false) is rejected; scheduling with a schedule of another type than the treatment type is rejected; one request split into two treatments keeps each container in exactly one treatment; a container cannot be placed in two treatments; an illegal status jump throws.
- [ ] **Step 2:** Run; expect failures.
- [ ] **Step 3:** Implement per Interfaces.
- [ ] **Step 4:** Re-run; expect pass.
- [ ] **Step 5:** Commit `feat(allpest): request review and scheduling`.

### Task 13: Treatment (ROF) service

**Files:** Create `core/allpest/ApTreatments.php`, `api/allpest/treatments.php`; Test `tests/Unit/AllPest/ApTreatmentsTest.php`.

**Interfaces:** Produces `ApTreatments`:
- `preCheck(string $treatmentId, array $in, DateTimeImmutable $now): array` — `$in` has dimensions, equipment ids, mix (chloropicrin yes/no), increment, forecast (from `ApForecastClient` or a manual override with source note); returns volume, dose rate used, dose, rounded dose, blocked reasons (ambient ≤ 10 °C, expired technician, non-selectable equipment, stale forecast).
- `recordDosing(string $treatmentId, DateTimeImmutable $injectedAt, float $gramsApplied, string $lotId): void` (calls `ApStock::consume`).
- `recordReadingSet(string $treatmentId, string $clientUuid, string $stage, DateTimeImmutable $at, array $valuesByContainerLocation, array $fileIds, string $initials): array` — returns per-location verdicts against the right standard (start-point vs exposure), equilibrium result, top-up permission and amount; idempotent on `$clientUuid`; requires at least one photo; rejects readings earlier than the injection time or in the future; location counts must match `ApReadings::requiredLocations` per container.
- `recordTopUp`, `recordVentilation(string $treatmentId, DateTimeImmutable $startedAt)`, `recordTlv(string $treatmentId, string $containerId, float $ppm, DateTimeImmutable $at)`.
- `close(string $treatmentId): array` — runs `ApResult::evaluate`, requires TLV ≤ 5 ppm for every container, stores per-container result.
- Perishable ROFs additionally require core temperatures (`ApTemperature::coreTempOk`) before dosing.

- [ ] **Step 1: Failing tests:** `preCheck` for 75 m³, dose rate 48, chloropicrin, increment 100 → rounded dose `3700.0`; ambient forecast 10.0 → blocked; forecast retrieved the day before the day before → blocked as stale; resubmitting the same `clientUuid` returns the stored result and creates no second set (Review Focus 2); a reading `">4"` raises the `ApReadings::parseReading` error and stores nothing (Review Focus 1); a dimension of 0 or a volume over 1000 m³ is rejected (Review Focus 4); a reading set without a photo is rejected; start point reached only when all readings meet the standard and equilibrium ≤ 15 %; a top-up at the 24 h stage records time and amount and removes the equilibrium requirement; `close` fails a container with TLV 5.1 ppm; five containers with one failing and no top-up possible close as `partial`.
- [ ] **Step 2:** Run; expect failures.
- [ ] **Step 3:** Implement as an orchestration over Tasks 2–6, 9, 10; every write goes through `ApAudit` (Task 18) once it exists, otherwise through `NuAudit::event` directly.
- [ ] **Step 4:** Re-run; expect pass.
- [ ] **Step 5:** Commit `feat(allpest): treatment service`.

### Task 14: Signing, lock and corrections

**Files:** Create `core/allpest/ApSigning.php`, `api/allpest/sign.php`; Test `tests/Unit/AllPest/ApSigningTest.php`.

**Interfaces:** Produces `new ApSigning(callable $verifyCredential)` where `$verifyCredential(string $userId, string $password, ?string $mfaCode): bool`:
- `fumigatorSign(string $treatmentId, string $userId, string $password, ?string $mfaCode, DateTimeImmutable $now): void` — only the named fumigator-in-charge, with `canSign`.
- `officerSign(string $treatmentId, string $name, string $designation, string $pngDataUrl, string $technicianUserId, DateTimeImmutable $now): void` — name and designation required; a blank signature image (fewer than 30 non-transparent pixels) rejected; stores timestamp, technician session user and device.
- `isLocked(string $treatmentId): bool` — true from the first signature; `ApTreatments` writes then throw `ApLockedException`.
- `correct(string $treatmentId, array $changes, string $reason, string $userId): int` — creates version N+1, keeps version N readable, requires a reason; clears signatures and certificate eligibility until re-signed.

- [ ] **Step 1: Failing tests:** wrong password refuses; a technician other than the fumigator-in-charge refuses; expired registration refuses; blank PNG refuses; officer without a designation refuses; after `fumigatorSign` a reading write throws `ApLockedException`; `correct` without a reason throws and with a reason returns version 2 while version 1 stays retrievable and signatures are cleared.
- [ ] **Step 2:** Run; expect failures.
- [ ] **Step 3:** Implement; counting pixels uses GD on the decoded PNG.
- [ ] **Step 4:** Re-run; expect pass.
- [ ] **Step 5:** Commit `feat(allpest): signing, lock and corrections`.

### Task 15: Certificates and release

**Files:** Create `core/allpest/ApCertificates.php`, `core/allpest/ApCertificateRenderer.php`, `api/allpest/certificates.php`; Test `tests/Unit/AllPest/ApCertificatesTest.php`.

**Interfaces:** Produces:
- `ApCertificates::releasable(string $containerId, bool $adminVerifyRequired): array{ok: bool, missing: array}` — `missing` lists any of `pass`, `fumigator_signature`, `officer_signature`, `admin_verification`.
- `ApCertificates::issueEligible(string $treatmentId, DateTimeImmutable $now): array` — for each releasable container, allocates a unique number from `ap_certificate_numbers` (format `AP-YYYY-NNNNN`), stores `sha256` of the canonical certificate content, and emails the customer a PDF attachment generated on the fly via `ApCertificateRenderer::render(array $data): string`; idempotent per container.
- `ApCertificates::verify(string $containerId, string $adminId): void` (optional admin verification, audit-logged).
- Certificate fields per spec §5.5.

- [ ] **Step 1: Failing tests:** `releasable` truth table: pass + both signatures → ok; missing officer → `missing=['officer_signature']`; failed container → `missing` contains `pass`; with `adminVerifyRequired` and no verification → `admin_verification` missing; `issueEligible` twice yields one certificate and one number per container (Review Focus 5); numbers are strictly increasing and never reused after a failed send; two containers passing and one failing produce two certificates; the stored hash equals the hash of re-rendered canonical content; an email failure leaves the certificate issued and flagged `email_pending`.
- [ ] **Step 2:** Run; expect failures.
- [ ] **Step 3:** Implement; reuse `PdfGenerator`/`EmailService` attachments (verify the exact attachment option format in `core/EmailService.php`).
- [ ] **Step 4:** Re-run; expect pass.
- [ ] **Step 5:** Commit `feat(allpest): certificates and release`.

### Task 16: ERPX changes (repo `ishaana10/nuviserpx`, separate PR)

**Files:** Create `api/v1/parties.php`, `database/migrations/057_invoice_external_ref.sql`, `tests/test_api_parties_and_invoice_ref.php`; Modify `api/v1/invoices.php`, `src/Support/ApiKey.php` (scopes). Follow ERPX's own test style (plain PHP test scripts under `tests/`).

**Interfaces:** Produces: `GET /api/v1/parties.php?email=` or `?tin=` (scope `read_parties`); `POST /api/v1/parties.php` find-or-create by lower-cased email, else TIN, else creates; returns `{id, party_code, created}` (scope `write_parties`); `POST /api/v1/invoices.php` accepts `external_ref` (unique per tenant) and `status` of `draft|unpaid` (default `unpaid`); a repeat of the same `external_ref` returns the existing invoice with `duplicate: true` and creates nothing; `PATCH /api/v1/invoices.php?id=` with `{"status":"unpaid"}` releases a draft.

- [ ] **Step 1: Failing tests:** creating a party twice with `Alice@X.com` and `alice@x.com` returns one id; two simultaneous POSTs with one `external_ref` create one invoice (unique index); a `draft` invoice is absent from the portal invoice query and visible after release; invalid status is rejected; a key without `write_parties` gets 403.
- [ ] **Step 2:** Run the new test file; expect failures.
- [ ] **Step 3:** Implement, keeping `invoice_no` generation unchanged; add the migration with a unique key on `(tenant_id, external_ref)`.
- [ ] **Step 4:** Re-run; expect pass; run `tests/test_api_engine.php` to confirm no regression.
- [ ] **Step 5:** Commit in the ERPX repo `feat(api): parties endpoint, invoice external_ref and draft release`.

### Task 17: ERPX client and invoicing

**Files:** Create `core/allpest/ApErpxClient.php`, `core/allpest/ApInvoices.php`, `api/allpest/invoices.php`, `cron/allpest_worker.php`; Test `tests/Unit/AllPest/ApInvoicesTest.php`.

**Interfaces:** Produces:
- `new ApErpxClient(string $baseUrl, string $apiKey, callable $http)` with `findOrCreateParty(array $customer): int`, `productPrice(string $productCode): string` (decimal string), `createInvoice(array $payload): array{id: int, invoice_no: string, duplicate: bool}`, `releaseInvoice(int $id): void`.
- `ApInvoices::raiseForRequest(string $requestId): void` — runs when every treatment of the request is closed; one line per container (failed containers included) using the product code mapped to the treatment type in `ap_settings`; money handled as integer cents; `external_ref` = request reference; status `draft` in `reviewed` mode, `unpaid` in `auto`.
- `ApInvoices::release(string $requestId, string $adminId): void` (reviewed mode); `ApInvoices::retryPending(): int` (used by cron, exponential backoff).
- Settings: `erpx.base_url`, `erpx.api_key`, `erpx.product.general`, `erpx.product.perishable`, `erpx.tax_rate` (default 0), `invoice.mode`.

- [ ] **Step 1: Failing tests (fake `$http`):** five containers at price `120.00`, tax 15 % → subtotal `600.00`, tax `90.00`, total `690.00`, five lines; a failed container is still billed; `reviewed` creates a draft and `release` calls the release endpoint once; transport timeout leaves a `pending` link and no exception to the caller; a retry after a timeout-that-succeeded gets `duplicate: true` and ends with one invoice link (Review Focus 5); `release` called twice concurrently releases once; price read at invoice time, never stored as an editable price.
- [ ] **Step 2:** Run; expect failures.
- [ ] **Step 3:** Implement; the worker script runs `ApInvoices::retryPending()`.
- [ ] **Step 4:** Re-run; expect pass.
- [ ] **Step 5:** Commit `feat(allpest): ERPX invoicing`.

### Task 18: Audit trail and audit pack

**Files:** Create `core/allpest/ApAudit.php`, `core/allpest/ApAuditPack.php`, `api/allpest/audit_pack.php`; Test `tests/Unit/AllPest/ApAuditPackTest.php`.

**Interfaces:** Produces `ApAudit::event(string $action, string $treatmentId, array $details): void` (wraps `NuAudit::event`, always including treatment id, user, device); `ApAuditPack::stream(string $treatmentId): void` — builds a ZIP in memory/temp (deleted after sending) containing `manifest.json`, the ROF data with every version, readings with the standards applied, forecast record, equipment and technician details, gas used, photos and signature images, certificate numbers with hashes, activity log, and the audit-chain integrity result.

- [ ] **Step 1: Failing tests:** every `ApTreatments` write action produces an audit row carrying the treatment id; the manifest lists each photo with its SHA-256; a pack for treatment A contains nothing from treatment B; a tampered audit row makes the manifest report `integrity: failed`; the pack lists all ROF versions after a correction.
- [ ] **Step 2:** Run; expect failures.
- [ ] **Step 3:** Implement with `ZipArchive`; use the existing audit integrity-check method (find it in `core/Audit.php`/`modules/audit`).
- [ ] **Step 4:** Re-run; expect pass.
- [ ] **Step 5:** Commit `feat(allpest): audit pack`.

### Task 19: Retention and reminders

**Files:** Create `core/allpest/ApRetention.php`, `core/allpest/ApReminders.php`; Modify `cron/allpest_worker.php`; Test `tests/Unit/AllPest/ApRetentionTest.php`.

**Interfaces:** Produces `ApRetention::canPurge(DateTimeImmutable $closedAt, DateTimeImmutable $now, int $years = 5): bool` (an admin action only, never automatic); `ApReminders::due(DateTimeImmutable $now): array` of `['kind','subject','due']` for registration expiring within 30 days, equipment audit/calibration due, stock below threshold, and treatments awaiting an officer or fumigator signature for more than 24 h; the worker emails the admin.

- [ ] **Step 1: Failing tests:** `canPurge` is false one day short of five years and true exactly at five; `years` below 5 is treated as 5; reminders include each of the four kinds in a seeded scenario and exclude items outside their windows.
- [ ] **Step 2:** Run; expect failures.
- [ ] **Step 3:** Implement per Interfaces.
- [ ] **Step 4:** Re-run; expect pass.
- [ ] **Step 5:** Commit `feat(allpest): retention and reminders`.

### Task 20: Access rules and menus

**Files:** Create `core/allpest/ApAccess.php`; Modify `core/MenuRenderer.php` only if no other hook exists; Test `tests/Unit/AllPest/ApAccessTest.php`.

**Interfaces:** Produces `ApAccess::can(string $userId, string $action, ?string $treatmentId = null): bool` for actions `admin.*`, `treatment.view`, `treatment.record`, `treatment.sign`. Roles `ap_admin` and `ap_technician` are created through the StudioX roles tables. A technician acts only on treatments assigned to them. Prefer inserting rows into `nu_menus` (as `core/MenuRenderer.php` already does for its demo menus) over editing `views/sidebar.php`, so StudioX updates merge cleanly.

- [ ] **Step 1: Failing tests:** a technician can record on their own treatment and not on another's; a technician cannot call `admin.*`; the API endpoint tests in this task return 403 for an anonymous caller on every `api/allpest/*.php` except `request_public.php`; the CSRF gate is not bypassed (state-changing calls without the token return 403).
- [ ] **Step 2:** Run; expect failures.
- [ ] **Step 3:** Implement and call `ApAccess` at the top of each endpoint.
- [ ] **Step 4:** Re-run; expect pass.
- [ ] **Step 5:** Commit `feat(allpest): access rules and menus`.

### Task 21: Tablet and admin screens

**Files:** Create `modules/allpest/*.php` (dashboard, requests, treatment/ROF, signing, technicians, equipment, schedules, stock, settings) and `assets/js/allpest/{rof,signpad,compress,queue}.js`.

**Interfaces:** Consumes the endpoints from Tasks 8–20. The ROF screen shows, per container, the required number of reading fields, the live standard and a red alert for any reading below standard with the top-up amount when allowed; large touch targets; each reading set gets a client UUID on creation. `queue.js` keeps unsent reading sets in `localStorage` and resubmits them in order when the connection returns (server idempotency from Task 13 makes this safe). `signpad.js` draws on a canvas and returns a PNG data URL; `compress.js` resizes photos in the browser (long edge 1600, quality 0.75) before upload.

- [ ] **Step 1:** Build the screens in the order: settings → masters → requests → treatment pre-check → readings → close → signing → certificates view.
- [ ] **Step 2:** Manual acceptance on a real tablet against a dev install: enter a reading below standard and see the alert and top-up amount; turn off the network, enter a reading set, turn it on, and confirm exactly one set is stored; draw an officer signature and a blank one (blank refused); take a photo and confirm the stored file is smaller than the original.
- [ ] **Step 3:** Run the whole unit suite (`php tests/AllPest/run.php tests/Unit/AllPest/`); expect all pass.
- [ ] **Step 4:** Commit `feat(allpest): admin and tablet screens`.

### Task 22: End-to-end acceptance

**Files:** Create `tests/Unit/AllPest/ApEndToEndTest.php`.

**Interfaces:** Consumes everything above, with a fake ERPX `$http` and a fake forecast `$http`.

- [ ] **Step 1: Failing test `testFiveContainerRequestWithOneFailure`:** submit a 5-container public request; confirm and schedule one treatment; run pre-check, dosing, start-point, a 24 h set where container 3 is below standard and not top-up-eligible, TLV for all, close → `partial`; sign as fumigator and as officer; expect 4 certificates (numbers unique), none for container 3, an invoice with 5 lines in `draft`, release → status `unpaid`; the audit pack lists all of it with `integrity: ok`.
- [ ] **Step 2:** Run; expect failure, then fix wiring gaps until it passes.
- [ ] **Step 3:** Run the whole suite and `php -l` on every file under `*/allpest/`; expect no errors.
- [ ] **Step 4:** Commit `test(allpest): end-to-end acceptance`.
