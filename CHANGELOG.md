# Changelog

## [0.11.3] - 2026-11-12

### Changes

The laravel-tax constraint now allows 0.19 (`^0.17 || ^0.18 || ^0.19`). Tax 0.19 is additive only (new `RegisterData`, `DocumentDataset`, `DatasetConformance` and `DatasetComparison` contracts), so the engine runtime is unchanged from 0.11.2.

## [0.11.2] - 2026-11-07

### Changes

Accept the Tax 0.18 authenticated-register release while retaining the existing 0.17 family. Engine runtime is unchanged from public 0.11.1.

### Upgrade

Tax ^0.17 || ^0.18 supports both families. Hosts selecting Tax 0.18 must configure its server-side register integration token before compiling a store. Consumer applications must lock an explicitly approved dependency graph.
