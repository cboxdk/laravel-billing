# Changelog

## [0.11.2] - 2026-11-07

### Changes

Accept the Tax 0.18 authenticated-register release while retaining the existing 0.17 family. Engine runtime is unchanged from public 0.11.1.

### Upgrade

Tax ^0.17 || ^0.18 supports both families. Hosts selecting Tax 0.18 must configure its server-side register integration token before compiling a store. Consumer applications must lock an explicitly approved dependency graph.
