# Unknot

Unknot is an early local PHP architecture explorer. It scans PHP source without executing the target project and writes an experimental JSON report of declarations and selected static references, inheritance and trait relationships.

Requires PHP 8.1 and Composer:

```sh
composer install
php bin/unknot scan fixtures/sample-legacy-app --output=report.json
composer test
```

Open `legacy-viewer/index.html` in a browser and choose the generated JSON report. Search filenames, declarations or available method names, filter by directory, follow a reference, and keep a temporary reading list. The viewer shows the first 500 matching files; refine the search to reach other results. The reading list clears when another report is opened or the page is closed. To check the viewer's basic interactions, run `node tests/legacy-viewer.js`.

Reports produced by this version include optional evidence for methods directly declared on named classes, interfaces and traits, and selected static call expressions. These are source expressions, not proof that a method ran. Version 1 leaves all method targets unresolved, excludes first-class callable creation, and does not describe instance calls, inherited methods, enum methods or anonymous types. Failed or skipped files have no method coverage. Older schema-0 reports still open and show method evidence as unavailable.

The scanner reports dynamic and ambiguous relationships as unresolved. Lexical self and parent references are resolved where possible; late static binding remains unresolved. Literal includes are heuristic, and Hack source is reported as unsupported. This is a prototype: parsing uses a separate PHP worker per file with a PHP memory limit and deadline. Before receiving source, workers require an operating-system limit: a 256 MiB address-space limit on Linux or a process commit limit on 64-bit Windows. Linux requires POSIX resource-limit functions without an ini file; Windows requires the matching PHP FFI extension. Unavailable controls produce a diagnostic. Filesystem race handling is not hardened. Report output uses an exclusively created temporary file; POSIX permissions are restricted to the owner and Windows access depends on the destination ACL. An incomplete scan preserves an existing report; choose a new output path to save a partial report. The scanner checks source metadata for changes between discovery and reading, but this does not make an atomic snapshot. Use only a stable local source tree and a trusted output directory; do not scan untrusted or sensitive projects.

## Open-source regression examples

The optional regression check uses pinned releases of [FastRoute v1.3.0](https://github.com/nikic/FastRoute/tree/181d480e08d9476e61381e04a71b34dc0432e812), [Monolog 2.7.0](https://github.com/Seldaek/monolog/tree/5579edf28aee1190a798bfa5be8bc16c563bd524), and [PHP dotenv v5.4.1](https://github.com/vlucas/phpdotenv/tree/264dce589e7ce37a7ba99cb901eed8249fbec92f). Their source is not included. Retain their licenses and scan the local projects without running them. Place their reports in a directory as `fastroute-context.json`, `monolog-context.json`, and `phpdotenv-context.json`, then run:

```sh
php tests/check-open-source.php reports
```

This checks a few known relationships and explicit unsupported Hack fixtures; it is not a measure of complete dependency coverage.

On Windows, run `pwsh ./tests/windows-junction.ps1` and `pwsh ./tests/windows-report-acl.ps1` for additional junction and report-permission checks.
