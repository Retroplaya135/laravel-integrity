<div align="center">
  <img src="docs/cover.png" alt="Laravel Integrity" width="100%">
  <h3>Reachability checks for Laravel codebases.</h3>
  <p>
    <img alt="Status: experimental" src="https://img.shields.io/badge/status-experimental-6d28d9">
    <img alt="PHP 8.4" src="https://img.shields.io/badge/PHP-8.4-777bb4?logo=php&logoColor=white">
    <img alt="License: MIT" src="https://img.shields.io/badge/license-MIT-0f766e">
  </p>
  <p>
    <a href="#overview">Overview</a> ·
    <a href="#how-the-analysis-works">How it works</a> ·
    <a href="#quick-start">Quick start</a> ·
    <a href="#verification">Verification</a> ·
    <a href="#boundaries">Boundaries</a>
  </p>
</div>

## Overview

Laravel Integrity is a small static analyzer for PHP applications that follow Laravel's conventional directory layout. It answers a deliberately narrow question: which application classes have no discovered path from a framework entry point or an explicit wiring file?

That question is difficult to answer by searching for filenames. Laravel applications connect code through controllers, middleware, jobs, providers, routes, configuration, policies, listeners, and other conventions. Laravel Integrity turns the classes it can see into a reference graph, starts from known entry-point directories, and walks the graph before reporting anything as unreachable.

The result is a review queue, not a deletion list. The analyzer never boots Laravel, runs service providers, resolves the container, or executes application code. A finding means that the configured static model could not discover a route to the file; a maintainer still decides whether the class is unused, dynamically resolved, or intentionally retained.

Use it when a codebase needs a repeatable dead-code signal in CI without letting the analyzer execute the application. A separate ratchet keeps reviewed exceptions explicit and prevents the known list from growing silently.

## How the analysis works

| Stage | What happens |
| --- | --- |
| Source scan | PHP files under `app/` are tokenized and class, interface, trait, and enum declarations are indexed. |
| Reference graph | Known application-class references are resolved from imports and class-like tokens, then attached to each declaration. |
| Seed discovery | Classes in controller, middleware, job, console, provider, listener, notification, and policy directories become roots. |
| Wiring scan | References from PHP files under `config/`, `bootstrap/`, and `routes/` add further roots. |
| Graph walk | Every class reachable from a root is retained; files behind the remaining declarations become candidate findings. |
| Review policy | Registered exceptions are accepted, stale registrations fail, and total/debt ceilings prevent unreviewed growth. |

The analyzer keeps this pipeline deterministic. Paths and class lists are sorted, configuration is read from JSON, and the CLI writes one machine-readable result. That makes the output suitable for code review and straightforward CI gating.

## Implemented capabilities

- Builds a transitive graph of declarations and references without executing inspected code.
- Recognizes namespaced classes, imported names, aliases, interfaces, traits, enums, inheritance, implementation, construction, type references, attributes, and known class-like tokens.
- Supports framework-oriented entry points and wiring directories through the PHP API.
- Separates new findings, accepted exceptions, stale exceptions, and reachable classes in the result.
- Requires every exception to include a nonempty reason; entries may also be marked as debt.
- Applies independent ceilings to the total exception count and the debt subset.
- Returns stable process statuses for clean runs, review-required findings, analysis errors, and missing arguments.

| Exit status | Meaning |
| ---: | --- |
| `0` | No unregistered findings and no stale exceptions. |
| `1` | Findings or stale exceptions require review. |
| `2` | Configuration, parsing, analysis, or baseline failure. |
| `64` | One or more required CLI arguments are missing. |

## Quick start

Laravel Integrity requires PHP 8.4 within the PHP 8 series and Composer. The package is currently intended to be used from a checked-out repository; no registry release is implied.

```bash
git clone https://github.com/Retroplaya135/laravel-integrity.git
cd laravel-integrity
composer install

cp examples/exceptions.json exceptions.json
cp examples/baseline.json baseline.json

php bin/laravel-integrity \
  --root=/path/to/laravel-project \
  --exceptions=exceptions.json \
  --baseline=baseline.json
```

`--root` must point to the project directory containing `app/`. The exception file is a JSON object keyed by project-relative file path. Each value contains a required `reason` and an optional boolean `debt` field; [the example registry](examples/exceptions.json) is the canonical shape. The baseline contains integer `total` and `debt` ceilings, as shown in [the example baseline](examples/baseline.json).

## Practical usage

Start with an empty registry and a zero baseline. Run the analyzer, inspect each candidate in the context of service-container bindings, package discovery, configuration, and runtime naming, then make one of three decisions:

1. Remove code only after the application test suite and a targeted runtime check support that conclusion.
2. Improve the analyzer's entry points or wiring model when the file is statically connected but the current rules miss it.
3. Register an intentional exception with a concrete reason when the reference is genuinely dynamic or externally invoked.

The baseline should describe the reviewed state, not suppress the current run. A pull request that adds an exception must also move the relevant ceiling; that makes the increase visible in the diff. When a previously accepted file becomes reachable or is removed, its registry entry becomes stale and the check fails until the obsolete exception is deleted.

For CI, preserve the CLI exit status and archive the JSON output when a failure needs to be inspected outside the job log. Avoid converting every status to success: status `1` is the intended signal that human review is required.

## Verification

```bash
composer run lint
composer test
```

The current test runner contains focused synthetic cases for import resolution and transitive graph traversal, malformed exception rejection, baseline growth, and the distinction between unregistered findings and stale exceptions. The lint script parses every PHP file under `src/`, `tests/`, and `bin/`.

The suite does not boot a Laravel application and does not yet exercise the CLI as a separate process across its full exit-status matrix. Changes to parsing or discovery rules should therefore add a small fixture first and retain the non-executing analysis boundary.

## Repository map

| Path | Responsibility |
| --- | --- |
| `src/ReferenceGraph.php` | Token inspection, declaration indexing, reference resolution, and graph traversal. |
| `src/ReachabilityAnalyzer.php` | Project scan, root discovery, reachability analysis, and result assembly. |
| `src/ReachabilityExceptions.php` | Exception-file validation and reviewed-path lookup. |
| `src/ReachabilityRatchet.php` | Total and debt ceiling enforcement. |
| `bin/laravel-integrity` | JSON CLI and process-status contract. |
| `examples/` | Minimal exception and baseline files. |
| `tests/run.php` | Dependency-free synthetic verification. |

## Boundaries

Static reachability is conservative by design. Dynamic container lookups, strings used as class names, generated classes, macros, package discovery, framework callbacks, reflection, application-specific registries, and external invocation can all create valid paths that this analyzer cannot see. Conversely, a broad entry-point directory or incidental known-class token can make a file appear reachable even when no runtime path uses it.

The parser is intentionally smaller than a complete PHP semantic model. Compatibility has not been established across Laravel versions, and the repository does not currently ship a matrix of real applications. The CLI uses the default source, wiring, and entry-point directories; callers that need different conventions can construct `ReachabilityAnalyzer` through the PHP API.

Do not run the tool against untrusted source with elevated filesystem permissions. It reads PHP and JSON files but does not need application credentials, database access, or a bootable Laravel environment.

## Documentation

- [Exception registry example](examples/exceptions.json)
- [Ratchet baseline example](examples/baseline.json)
- [Contributing guide](CONTRIBUTING.md)
- [Security policy](SECURITY.md)

## Contributing, security, and license

Parsing and entry-point changes should begin with a failing synthetic fixture. Keep framework conventions configurable, keep tests independent of application code, and run both verification commands before opening a pull request. See [CONTRIBUTING.md](CONTRIBUTING.md) for the project rules.

Report path-containment, parser denial-of-service, or unsafe file-handling issues privately as described in [SECURITY.md](SECURITY.md). Do not attach proprietary application source to a public report.

Laravel Integrity is available under the [MIT License](LICENSE).
