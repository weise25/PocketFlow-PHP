# Changelog

All notable changes to PocketFlow‑PHP will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.2.0] - 2026-04-30

### Added
- `SharedStore` class replacing `stdClass` for type‑safe shared data
- Comprehensive PHPDoc documentation on all public and protected methods
- `#[\AllowDynamicProperties]` attribute on `SharedStore` for PHP 8.2+ compatibility
- New tests: `testOverwriteSuccessorThrowsException`, `testRunWithSuccessorsThrowsException`, `testSyncFlowWithAsyncNodeThrowsException`

### Changed
- **Breaking:** All `stdClass` type hints replaced with `SharedStore` in all methods
- **Breaking:** Async methods renamed from `snake_case` to `camelCase` (PSR‑1 compliance):
  - `run_async()` → `runAsync()`
  - `prep_async()` → `prepAsync()`
  - `exec_async()` → `execAsync()`
  - `post_async()` → `postAsync()`
  - `exec_fallback_async()` → `execFallbackAsync()`
  - `_run_async()` → `_runAsync()`
  - `_exec_async()` → `_execAsync()`
  - `_orchestrate_async()` → `_orchestrateAsync()`
- $maxRetries and $wait promoted to `readonly` constructor properties
- Removed `files` array from Composer autoload; framework split into 14 PSR‑4 files

### Removed
- `trigger_error()` calls replaced with proper exceptions (`LogicException`, `RuntimeException`)

### Fixed
- `Flow` no longer attempts to `await()` async nodes (throws clear error instead)
- `@` error suppression removed from tests

## [0.1.0] - Initial release
- Core port: Nodes, Flows, Batch processing, Async Nodes, Async Flows
