# Calibration Management System --- Project Context

> **Document purpose:** This document is the primary context and
> development contract for AI coding agents (especially Cursor Agent)
> working on this project.
>
> **Last updated:** 2026-10-07
>
> **Important:** Read this document before making architectural,
> database, Laravel, or UI changes.

------------------------------------------------------------------------

# 1. Project Overview

This project is a **Factory Instrument Calibration Management System**.

The application will be used to manage calibration instruments,
calibration schedules, calibration results, certificates, and reporting.

The main goals are:

-   Monitor calibration status.
-   Store instrument identity/master data.
-   Store calibration history.
-   Record calibration scope/type.
-   Record test profiles and test points.
-   Record calibration test results and readings.
-   Record calibration environment.
-   Record calibration activities.
-   Record standards used during calibration.
-   Store certificate information.
-   Generate/view calibration certificates.
-   Print/export certificates to PDF.
-   Export relevant data to Excel.
-   Provide a dashboard for monitoring.

The existing PostgreSQL database is the current source of truth for the
application data.

The Laravel application must be built **on top of the existing
database**, not by recreating the database from scratch.

------------------------------------------------------------------------

# 2. Technology Stack

Current project stack:

-   Laravel 13.34.0
-   PHP 8.3+
-   PostgreSQL 18
-   Livewire 4.4.7
-   Tailwind CSS 4
-   Vite 8
-   Cursor IDE / Cursor Agent

The uploaded Laravel project is currently a relatively clean starter
project.

Current Laravel project state:

-   Default Laravel starter structure exists.
-   Default `User` model exists.
-   Default Laravel migrations exist.
-   `routes/web.php` is still essentially the starter route.
-   Application-specific models/controllers/pages have not yet been
    built.
-   The project currently uses SQLite in `.env` and must later be
    configured for PostgreSQL.
-   Do NOT run destructive/default migrations against the existing
    calibration database without explicit approval.

------------------------------------------------------------------------

# 3. CRITICAL AI AGENT RULES

These rules have priority over assumptions made by an AI coding agent.

## 3.1 Do not recreate the database

The PostgreSQL database already exists.

Database:

``` text
calibration_db
```

The database schema has already been designed and populated with
prototype data.

Do not:

-   Drop the database.
-   Drop existing tables.
-   Recreate all tables automatically.
-   Rename existing tables without explicit approval.
-   Remove columns without explicit approval.
-   Replace the existing schema with Laravel's default schema.
-   Run destructive migration commands.
-   Reset the database.

## 3.2 Do not modify the database schema automatically

Before changing PostgreSQL structure:

1.  Explain why the change is needed.
2.  Show the proposed change.
3.  Wait for user approval.
4.  Prefer a Laravel migration for approved application schema changes.
5.  Test the change before continuing.

The existing PostgreSQL schema is currently considered stable enough for
application development.

## 3.3 Do not invent business data

Do not invent:

-   Serial numbers.
-   Asset numbers.
-   Maximum capacity.
-   Resolution.
-   Calibration specifications.
-   Accreditation numbers.
-   Customer information.
-   Standard instrument information.
-   Authorized signatories.
-   Service report dates.
-   Calibration results.

Prototype values already in the database are illustrative/provisional.

## 3.4 Do not silently change the data model

Important decisions have already been made.

For example:

-   `serial_number` belongs to `instrument_type`, not `instrument`.
-   One instrument identity may have multiple instrument types.
-   One calibration may contain multiple calibration scopes.
-   One calibration has one certificate.
-   A physical standard instrument used during calibration is different
    from a test result's standard value.
-   Standard values may vary by calibration event.
-   Tolerance rules vary by calibration profile.
-   Not every profile can use the same tolerance formula.

If the Agent believes the schema should change, it must explain the
reason instead of silently changing it.

## 3.5 PostgreSQL is the source of truth

Excel and CSV files are source/import/export formats.

They are not the application's primary database.

The desired architecture is:

``` text
User
  ↓
Laravel / Livewire
  ↓
PostgreSQL 18
  ↓
PDF / Excel reporting
```

------------------------------------------------------------------------

# 4. Existing Database Concept

The database is relational and separates master data, calibration
transactions, test definitions, test results, and documents.

High-level structure:

``` text
MASTER DATA
├── instrument
├── instrument_type
├── location
├── detail_location
├── customer
├── calibration_provider
├── test_profile
└── test_point

TRANSACTION
└── calibration
    ├── calibration_scope
    ├── calibration_environment
    ├── calibration_activity
    ├── calibration_standard_usage
    ├── calibration_test_result
    └── calibration_reading

DOCUMENT
├── certificate
└── certificate_revision
```

Other supporting tables include:

-   `app_user`
-   `notification_log`
-   `staging_raw_data`

------------------------------------------------------------------------

# 5. Instrument Identity Concept

This is one of the most important business concepts.

The initial Excel file is considered an **instrument identity/core
identity** dataset.

It does not represent the complete calibration report.

An instrument identity can have multiple types.

Example:

``` text
Instrument Name: Sodiline

Types:
1. Weight
2. Diameter
3. PD
```

These are not automatically three separate instrument identities.

The relationship is:

``` text
instrument
    1
    |
    +---- N instrument_type
```

Therefore:

``` text
Sodiline
├── Weight
├── Diameter
└── PD
```

Another example:

``` text
Timbangan Digital
├── Mettler Toledo PB602-S
└── BSA822-CW
```

------------------------------------------------------------------------

# 6. Instrument Tables

## 6.1 instrument

Important columns:

``` text
instrument_id
instrument_name
detail_location_id
description
created_at
```

Purpose:

Stores the core identity of an instrument.

Examples:

-   Sodiline
-   Digital Caliper
-   Timbangan Digital

Do not put serial number directly into this table.

## 6.2 instrument_type

Important columns:

``` text
instrument_type_id
instrument_id
type_name
description
created_at
asset_number
serial_number
max_capacity
max_capacity_unit
resolution
resolution_unit
brand
model
```

Purpose:

Stores a specific type/configuration/identification belonging to an
instrument identity.

Important:

``` text
serial_number belongs here.
asset_number belongs here.
brand belongs here.
model belongs here.
```

`asset_number` can be NULL.

Do not assume every instrument has an asset number.

Do not invent max capacity or resolution when the source data does not
provide them.

------------------------------------------------------------------------

# 7. Location Structure

Location is split into:

``` text
location
    |
    +---- detail_location
              |
              +---- instrument
```

## location

Contains the higher-level location:

``` text
location_id
location_code
location_name
description
```

## detail_location

Contains the more specific location:

``` text
detail_location_id
location_id
detail_location_code
detail_location_name
description
created_at
```

An instrument references `detail_location`.

------------------------------------------------------------------------

# 8. Calibration Concept

A calibration is a transaction/event.

The main table is:

``` text
calibration
```

Important columns:

``` text
calibration_id
instrument_id
calibration_provider_id
calibration_number
calibration_date
calibration_due
calibration_method
calibration_result
certificate_number
remarks
created_at
updated_at
action_date
resume
customer_id
```

A calibration belongs to one core instrument identity.

A calibration can contain multiple scopes.

Example:

``` text
CAL-PROT-0001
Instrument: Sodiline

Scope 1 → Weight
Scope 2 → Diameter
Scope 3 → PD
```

------------------------------------------------------------------------

# 9. Calibration Scope

Table:

``` text
calibration_scope
```

Important columns:

``` text
calibration_scope_id
calibration_id
instrument_type_id
test_profile_id
scope_order
remarks
created_at
```

Purpose:

Defines which instrument type/profile is actually being calibrated
within a calibration event.

Important relationship:

``` text
calibration
    1
    |
    +---- N calibration_scope
```

Do not create duplicate scope orders for the same calibration.

There is a unique constraint on:

``` text
(calibration_id, scope_order)
```

------------------------------------------------------------------------

# 10. Test Profile and Test Point

## test_profile

Defines the calibration test structure for an instrument type.

Important columns:

``` text
test_profile_id
instrument_type_id
profile_name
description
```

Relationship:

``` text
instrument_type
    1
    |
    +---- N test_profile
```

## test_point

Defines the points in a profile.

Important columns:

``` text
test_point_id
test_profile_id
point_order
nominal_value
unit
tolerance_plus
tolerance_minus
description
created_at
```

Relationship:

``` text
test_profile
    1
    |
    +---- N test_point
```

Important:

`nominal_value` is a configured/profile test point.

It must NOT automatically be treated as the actual standard value used
in every calibration event.

Actual calibration event values are stored in
`calibration_test_result.standard_value`.

------------------------------------------------------------------------

# 11. Calibration Test Result

Table:

``` text
calibration_test_result
```

Important columns:

``` text
calibration_test_result_id
calibration_scope_id
test_point_id
result_status
remarks
created_at
standard_value
average_value
correction_value
```

The additional fields were intentionally added because certificate
reports repeatedly contain:

``` text
Standard Value
Standard Reading
Avg
Correction
```

`result_status` currently uses:

``` text
PASS
FAIL
NULL
```

Do not assume every profile uses the same tolerance calculation.

------------------------------------------------------------------------

# 12. Calibration Reading

Table:

``` text
calibration_reading
```

Important columns:

``` text
calibration_reading_id
calibration_test_result_id
reading_order
reference_value
instrument_value
error_value
uncertainty_value
unit
created_at
```

Purpose:

Stores individual readings.

This is intentionally separated from `calibration_test_result`.

Reason:

A future calibration may require multiple readings for a single test
point.

Therefore:

``` text
calibration_test_result
    1
    |
    +---- N calibration_reading
```

Do not remove this separation.

For the current prototype, most points have one reading.

------------------------------------------------------------------------

# 13. Standard Used vs Standard Value

This distinction is critical.

## Standard Used

Table:

``` text
calibration_standard_usage
```

This represents the physical/reference instrument used during
calibration.

Important columns:

``` text
calibration_standard_usage_id
calibration_id
standard_instrument_id
usage_order
usage_purpose
remarks
created_at
standard_range
standard_unit
```

A calibration can use multiple standards.

Example:

``` text
Calibration
  |
  +-- Standard ET4276
  +-- Standard ET4614
  +-- Standard ET4782
  +-- Standard ET4260
  +-- Standard ET4934
```

## Standard Value

This is stored in:

``` text
calibration_test_result.standard_value
```

It is the actual value used for a particular test point in a particular
calibration event.

These concepts must not be merged.

------------------------------------------------------------------------

# 14. Calibration Environment

Table:

``` text
calibration_environment
```

Important columns:

``` text
calibration_environment_id
calibration_id
temperature_value
temperature_unit
humidity_value
humidity_unit
pressure_value
pressure_unit
remarks
```

Currently certificate reports commonly show:

-   Temperature
-   Relative Humidity

Pressure is available but may be NULL.

Do not fabricate environmental values.

------------------------------------------------------------------------

# 15. Calibration Activity

Table:

``` text
calibration_activity
```

Important columns:

``` text
calibration_activity_id
calibration_id
activity_type
activity_description
activity_result
activity_order
created_at
```

Example prototype:

``` text
1. Cleaning
   Cleaning All Module

2. Verification
   Verification

3. Testing
   Measurement verification
```

Activity order must be preserved in reports.

------------------------------------------------------------------------

# 16. Customer

Table:

``` text
customer
```

Calibration references a customer through:

``` text
calibration.customer_id
```

Prototype customer:

``` text
PT. BENTOEL PRIMA
Jl. Raya Perusahaan, Karanglo, Singosari, Malang
```

This is prototype data and should not be treated as universal business
data.

------------------------------------------------------------------------

# 17. Calibration Provider

Table:

``` text
calibration_provider
```

Important columns:

``` text
calibration_provider_id
provider_code
provider_name
provider_type
address
contact_person
phone
email
accreditation_number
description
created_at
```

Provider type currently supports:

``` text
INTERNAL
EXTERNAL
```

Do not invent accreditation numbers.

------------------------------------------------------------------------

# 18. Certificate

Table:

``` text
certificate
```

Important columns:

``` text
certificate_id
calibration_id
certificate_number
certificate_type
issued_date
file_path
file_name
remarks
created_at
```

There is a unique constraint on:

``` text
calibration_id
```

Therefore the current business rule is:

``` text
1 calibration = maximum 1 certificate
```

Certificate type:

``` text
INTERNAL
EXTERNAL
```

------------------------------------------------------------------------

# 19. Certificate Revision

Table:

``` text
certificate_revision
```

Important columns:

``` text
certificate_revision_id
certificate_id
revision_number
revision_date
revision_reason
file_path
file_name
created_at
```

Purpose:

Stores certificate revision history without replacing the original
certificate record.

------------------------------------------------------------------------

# 20. Existing PostgreSQL Views

The database already contains views intended to support
certificate/report rendering.

Do not recreate these unnecessarily.

## v_certificate_header

Provides certificate header/general information:

-   Certificate
-   Customer
-   Address
-   Calibration number
-   Calibration dates
-   Method
-   Result
-   Resume
-   Instrument
-   Location
-   Provider
-   Accreditation

## v_certificate_environment

Provides environment information.

## v_certificate_activity

Provides calibration activities ordered by `activity_order`.

## v_certificate_standard

Provides standards used during calibration.

## v_certificate_test_result

Generic detailed test result view.

## v_certificate_instrument_identification

Provides instrument identification per calibration scope.

## v_certificate_preview_test_result

Provides a simplified test result structure.

## v_certificate_preview_test_result_display

Formats displayed numeric values for certificate preview.

Current special formatting includes PD values with one decimal place.

## v_certificate_test_result_report

Provides report-oriented test result output ordered by:

``` text
scope_order
point_order
```

------------------------------------------------------------------------

# 21. Existing Prototype Data

The database already contains prototype records.

## Instruments

### Sodiline

Instrument ID:

``` text
3
```

Location:

``` text
FMD / FM 05/06
```

Types:

``` text
Weight
Diameter
PD
```

### Digital Caliper

Instrument ID:

``` text
4
```

Location:

``` text
IMI / IMI
```

Type:

``` text
Mitutoyo
```

Serial:

``` text
A17139638
```

Asset:

``` text
ID12-1123100226-0
```

### Timbangan Digital

Instrument ID:

``` text
5
```

Location:

``` text
TPO / JEPARA
```

Types:

``` text
Mettler Toledo PB602-S
BSA822-CW
```

------------------------------------------------------------------------

# 22. Prototype Calibration Records

## Sodiline

Calibration:

``` text
CAL-PROT-0001
```

Date:

``` text
2026-06-12
```

Due:

``` text
2027-06-12
```

Method:

``` text
EXTERNAL
```

Scopes:

``` text
1. Weight
2. Diameter
3. PD
```

Resume:

``` text
Instrument working properly
```

Certificate:

``` text
CERT-PROT-0001
```

Test results currently include:

-   Weight: 3 points
-   Diameter: 3 points
-   PD: 5 points

Total:

``` text
11 test results
```

------------------------------------------------------------------------

## Digital Caliper

Calibration:

``` text
CAL-PROT-0002
```

Date:

``` text
2026-07-13
```

Due:

``` text
2027-07-13
```

Method:

``` text
EXTERNAL
```

Scope:

``` text
Mitutoyo
```

Environment:

``` text
24.5 °C
60.0 %
```

Activities:

``` text
Cleaning
Verification
Testing
```

Test points:

``` text
4
```

Certificate:

``` text
CERT-PROT-0002
```

This prototype certificate has been validated successfully.

------------------------------------------------------------------------

## Timbangan Digital

Calibration:

``` text
CAL-PROT-0003
```

Date:

``` text
2025-10-21
```

Due:

``` text
2026-10-21
```

Method:

``` text
EXTERNAL
```

Scopes:

``` text
1. Mettler Toledo PB602-S
2. BSA822-CW
```

Environment:

``` text
25.000 °C
65.000 %
```

Activities:

``` text
Cleaning
Verification
Testing
```

Test results:

``` text
6
```

Readings:

``` text
6
```

Certificate:

``` text
CERT-PROT-0003
```

The certificate test-result report was validated and returned exactly 6
rows without JOIN duplication.

------------------------------------------------------------------------

# 23. Prototype Test Values

The following values are prototype/illustrative data.

They must not be treated as official calibration specifications.

## Weight

Points:

``` text
100 g
500 g
1000 g
```

Tolerance:

``` text
±0.1 g
```

## Diameter

Points:

``` text
10 mm
20 mm
30 mm
```

Tolerance:

``` text
±0.01 mm
```

## Digital Caliper

Points:

``` text
0 mm
50 mm
100 mm
150 mm
```

Tolerance:

``` text
±0.02 mm
```

## Timbangan Digital

Mettler Toledo and BSA822-CW prototype points:

``` text
100 g
500 g
1000 g
```

Prototype tolerance:

``` text
±0.10 g
```

## Pressure Drop

Points:

``` text
99.0 mmWG
198.1 mmWG
293.5 mmWG
394.9 mmWG
487.0 mmWG
```

The current rule is represented as a description:

``` text
Correction tolerance ±3% from standard value
```

Do not convert this to an absolute tolerance column without confirming
the business rule.

------------------------------------------------------------------------

# 24. Certificate Structure

The target certificate/report structure is approximately:

``` text
CALIBRATION CERTIFICATE

1. GENERAL INFORMATION
   - Customer
   - Address
   - Issued Date

2. INSTRUMENT IDENTIFICATION
   - Instrument
   - Max Capacity
   - Brand/Type
   - Serial Number
   - Model
   - Location
   - Resolution
   - Action Date

3. CALIBRATION INFORMATION
   - Calibration Number
   - Calibration Date
   - Calibration Due
   - Method
   - Provider

4. ENVIRONMENT
   - Temperature
   - Relative Humidity
   - Pressure if applicable

5. ACTIVITY
   - Ordered activities

6. STANDARD(S) USED
   - Standard instrument
   - Range
   - Serial/ID

7. TEST RESULT
   - Standard Value
   - Standard Reading
   - Avg
   - Correction
   - Unit
   - Result

8. RESUME
   - Calibration conclusion

9. AUTHORIZED SIGNATORY
   - Signature area
```

The certificate should group test results by calibration scope/type.

------------------------------------------------------------------------

# 25. Items Not Yet Finalized

Do not implement these as firm requirements yet.

## Authorized Signatory

The source certificate contains an authorized signatory area, but the
database structure for signatory information has not yet been finalized.

Do not create arbitrary signatory tables/fields.

## Service Report Date

The source data appears to contain two date concepts related to
service/report information.

Their exact semantics have not yet been confirmed.

Do not add a new field or reinterpret existing dates without
confirmation.

## Max Capacity and Resolution

These exist as columns in `instrument_type`, but not all prototype
instruments have values.

Do not invent missing values.

## Tolerance Rules

Tolerance differs by calibration profile.

Examples include:

-   Weight
-   Diameter
-   Pressure Drop
-   Ventilation
-   Time
-   Temperature
-   Humidity

Do not implement one global tolerance formula.

------------------------------------------------------------------------

# 26. Calibration Status

The current dashboard logic is:

``` text
IF CURRENT_DATE > calibration_due
    THEN Overdue
ELSE
    On Track
```

This is a presentation/status calculation.

Do not store a permanently calculated status in the database unless
explicitly decided later.

Future status categories may be expanded, for example:

``` text
Overdue
Due Soon
On Track
```

but this has not yet been finalized.

------------------------------------------------------------------------

# 27. Dashboard Concept

The intended main dashboard contains:

## Summary cards

-   Total Instrument
-   On Track
-   Overdue
-   Certificate

## Filters

-   Search instrument
-   Location
-   Calibration status
-   Possibly calibration date range later

## Main table

Potential columns:

``` text
Instrument
Type
Serial Number
Asset Number
Location
Calibration Date
Calibration Due
Status
Certificate
Action
```

Actions may include:

``` text
View
Edit
Certificate
```

Do not implement unnecessary dashboard features before the basic flow
works.

------------------------------------------------------------------------

# 28. Intended Application Menu

The current conceptual menu:

``` text
MASTER DATA
├── Instrument
├── Instrument Type
├── Location
├── Customer
├── Calibration Provider
├── Test Profile
└── Test Point

TRANSACTION
└── Calibration
    ├── Scope
    ├── Environment
    ├── Activity
    ├── Standard Used
    ├── Test Result
    └── Reading

DOCUMENT
├── Certificate
└── Certificate Revision
```

Dashboard should be the primary landing page.

------------------------------------------------------------------------

# 29. Intended User Flow

Basic flow:

``` text
Login
  ↓
Dashboard
  ↓
Select Instrument
  ↓
Instrument Detail
  ↓
Calibration History
  ↓
Select Calibration
  ↓
Calibration Detail / Certificate
  ↓
View Test Result
  ↓
Print PDF / Export Excel
```

Data-entry flow:

``` text
Instrument
  ↓
Instrument Type
  ↓
Calibration
  ↓
Calibration Scope
  ↓
Test Profile
  ↓
Test Result
  ↓
Reading
  ↓
Environment / Activity / Standard
  ↓
Certificate
```

------------------------------------------------------------------------

# 30. Laravel Integration Strategy

The first Laravel milestone is NOT a full CRUD system.

First prove:

``` text
Laravel
   ↓
PostgreSQL
   ↓
Existing instrument table
   ↓
Display real instrument data
```

Recommended sequence:

### Phase 1 --- Connection

Configure `.env` for PostgreSQL.

Expected basic configuration:

``` env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=calibration_db
DB_USERNAME=postgres
DB_PASSWORD=YOUR_LOCAL_PASSWORD
```

Never commit the real password.

### Phase 2 --- Verify connection

Before building features:

``` bash
php artisan about
```

and/or a controlled database test.

Do not run destructive migrations.

### Phase 3 --- First model

Create/use an Eloquent model for:

``` text
instrument
```

Map it to the existing table.

Because the existing primary key is not Laravel's default `id`,
configure:

``` php
protected $primaryKey = 'instrument_id';
```

Do not assume all models can use Laravel's default conventions.

### Phase 4 --- First page

Create a simple Instrument page that reads:

``` text
instrument
instrument_type
location
detail_location
```

and displays real database records.

### Phase 5 --- Dashboard

Once the basic read path works, implement the dashboard.

------------------------------------------------------------------------

# 31. Laravel Model Convention

Existing database naming is based on explicit primary keys such as:

``` text
instrument_id
calibration_id
instrument_type_id
certificate_id
```

Laravel models must be configured correctly.

Do not blindly assume:

``` php
id
```

is the primary key.

Also inspect timestamp behavior before disabling or overriding it.

Most relevant tables contain:

``` text
created_at
updated_at
```

or:

``` text
created_at
```

Do not blindly generate migrations to replace these fields.

------------------------------------------------------------------------

# 32. Migrations

The existing PostgreSQL database was not originally created by the
current Laravel starter project.

Therefore:

-   Laravel migration history and actual PostgreSQL schema are currently
    separate concerns.
-   Do not run `php artisan migrate:fresh`.
-   Do not run `php artisan migrate:refresh`.
-   Do not run `php artisan db:wipe`.
-   Do not create duplicate versions of the existing calibration tables.

If Laravel migrations are later used to manage schema changes, first
establish a safe baseline strategy.

------------------------------------------------------------------------

# 33. Laravel Default Tables

The Laravel starter project contains default migration files for tables
such as:

``` text
users
cache
jobs
```

Do not automatically migrate these into `calibration_db` without
deciding whether they are required.

The application's authentication/session/cache/queue architecture must
be chosen deliberately.

The existing database already has:

``` text
app_user
```

so authentication must eventually be reconciled with the application's
existing user design rather than blindly creating another user system.

------------------------------------------------------------------------

# 34. Cursor Agent Development Rules

When working through Cursor Agent:

## Always inspect before changing

Before creating code:

1.  Inspect the existing Laravel project.
2.  Inspect the relevant database structure.
3.  Read this document.
4.  Identify existing models/routes/components before adding duplicates.
5.  Explain the intended change.

## Prefer small changes

Do not generate the entire application in one operation.

Recommended:

``` text
One feature
→ implement
→ test
→ inspect
→ continue
```

## Avoid speculative abstractions

Do not create:

-   unnecessary repositories,
-   services,
-   traits,
-   interfaces,
-   event systems,
-   APIs,
-   complex state management,

unless the actual requirement needs them.

Keep the first version understandable.

------------------------------------------------------------------------

# 35. Recommended Initial Laravel Architecture

For the first version:

``` text
Laravel
├── Routes
├── Controllers / Livewire components
├── Eloquent Models
├── Blade / Livewire Views
├── Tailwind CSS
└── PostgreSQL
```

Use Livewire where interactive forms/tables benefit from it.

Do not introduce a separate frontend framework unless explicitly
requested.

The initial UI should prioritize:

-   usability,
-   readability,
-   maintainability,
-   simple navigation,
-   responsive layout.

------------------------------------------------------------------------

# 36. PDF and Excel

The application eventually needs:

## PDF

Generate printable calibration certificates.

The PDF should use the same data structure already validated through
PostgreSQL views.

Do not duplicate business logic unnecessarily between SQL and PDF
generation.

## Excel

Excel should support:

-   import where required,
-   export/reporting,
-   potentially bulk updates later.

PostgreSQL remains the source of truth.

------------------------------------------------------------------------

# 37. Important JOIN Warning

The calibration schema contains several one-to-many relationships.

For example:

``` text
calibration
  ├── scopes
  ├── activities
  ├── environment
  ├── standards
  └── test results
```

Joining all of these tables together in one query can multiply rows.

Example:

``` text
3 scopes × 5 activities × 5 standards × 11 test results
```

can create incorrect duplicated output.

Therefore:

-   Prefer separate queries/views for separate certificate sections.
-   Use the existing certificate views.
-   Do not create giant JOIN queries unless row multiplication is
    explicitly controlled.

This is an important reporting rule.

------------------------------------------------------------------------

# 38. Existing Validation Results

The certificate data structure has already been validated.

## Sodiline

Validated sections:

-   Header
-   Instrument Identification
-   Environment
-   Activity
-   Standard Used
-   Test Result
-   Resume

Test result count:

``` text
11
```

## Digital Caliper

Validated counts:

``` text
Environment: 1
Activity: 3
Scope: 1
Test Result: 4
Reading: 4
Certificate: 1
```

## Timbangan Digital

Validated counts:

``` text
Environment: 1
Activity: 3
Scope: 2
Test Result: 6
Reading: 6
Certificate: 1
```

The Timbangan test result report returns exactly six rows with no
duplication.

These validations should be treated as regression expectations.

------------------------------------------------------------------------

# 39. Current Database Data Is Prototype Data

The current data was created for development/testing.

Examples:

``` text
CAL-PROT-0001
CAL-PROT-0002
CAL-PROT-0003

CERT-PROT-0001
CERT-PROT-0002
CERT-PROT-0003
```

Do not treat these as production records.

The actual source data will later be imported/cleaned.

------------------------------------------------------------------------

# 40. Original Source Data Context

The original source data includes an Excel/CSV identity dataset.

Important source columns include:

``` text
source_row_number
instrument_name
type_name
serial_number
asset_number
calibration_date
calibration_due
calibration_status
location_name
detail_location_name
remark
```

The source contained approximately 344 instrument rows.

Some source records contain invalid/missing values such as:

``` text
#VALUE!
```

especially around calibration due dates.

Do not blindly import malformed source data into production tables.

A staging/import process is preferable.

The existing table:

``` text
staging_raw_data
```

is intended to support this type of workflow.

------------------------------------------------------------------------

# 41. Data Import Philosophy

When actual data is ready:

``` text
Raw Excel/CSV
    ↓
Staging
    ↓
Validation / cleaning
    ↓
Master data
    ↓
Calibration transactions
    ↓
Certificate
```

Do not directly insert uncontrolled Excel data into normalized tables.

------------------------------------------------------------------------

# 42. Important Data Modeling Decisions

These decisions are already accepted unless new evidence requires
revision.

### Decision 1

One instrument identity can contain multiple instrument types.

### Decision 2

Serial number belongs to `instrument_type`.

### Decision 3

Asset number belongs to `instrument_type`.

### Decision 4

One calibration can contain multiple scopes.

### Decision 5

One scope points to one instrument type and optionally one test profile.

### Decision 6

One test profile has multiple test points.

### Decision 7

One test point can have multiple readings through `calibration_reading`.

### Decision 8

One calibration can use multiple physical standards.

### Decision 9

Actual standard values belong to calibration test results.

### Decision 10

One calibration has one certificate under the current business rule.

### Decision 11

Certificate revisions are stored separately.

### Decision 12

Certificate sections should be rendered independently to avoid JOIN
multiplication.

------------------------------------------------------------------------

# 43. What the AI Agent Should Ask Before Making a Major Change

If a requested feature requires a change to:

-   database schema,
-   relationships,
-   business rules,
-   certificate structure,
-   authentication architecture,
-   import architecture,

the Agent should explain the impact and ask for confirmation before
implementing it.

For small UI/code changes that do not affect architecture, normal
implementation can proceed.

------------------------------------------------------------------------

# 44. Current Priority

The current priority is:

## Priority 1

Connect Laravel to the existing PostgreSQL database safely.

## Priority 2

Read/display existing `instrument` data.

## Priority 3

Create instrument detail and calibration history.

## Priority 4

Create dashboard.

## Priority 5

Create calibration detail.

## Priority 6

Create certificate preview.

## Priority 7

Create PDF certificate.

## Priority 8

Create Excel import/export.

## Priority 9

Authentication, permissions, notifications, and advanced features.

Do not jump directly to advanced features.

------------------------------------------------------------------------

# 45. First Task for Cursor Agent

When beginning application development, the first task should be:

> Read `PROJECT_CONTEXT.md`, inspect the existing Laravel project,
> inspect the existing PostgreSQL schema, and prepare the Laravel
> application to connect safely to the existing `calibration_db`. Do not
> run destructive migrations and do not modify the database schema.
> After the connection is configured, create only the minimum code
> necessary to verify that Laravel can read the existing `instrument`
> table.

Expected proof of success:

``` text
Sodiline
Digital Caliper
Timbangan Digital
```

should be readable from PostgreSQL through Laravel.

Only after that should application UI development proceed.

------------------------------------------------------------------------

# 46. Final Instruction to AI Agent

Treat this document as the current project context.

Do not assume that an empty Laravel project means the database is empty.

The PostgreSQL calibration database is already designed and contains
prototype data.

The job of Laravel is to become the application layer over that existing
relational model.

When uncertain:

1.  Inspect first.
2.  Explain.
3.  Propose.
4.  Ask before changing architecture/schema.
5.  Implement a small change.
6.  Test.
7.  Continue.

**Never destroy or recreate the existing calibration database as a
shortcut.**
