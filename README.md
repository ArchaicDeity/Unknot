# LegacyLens

LegacyLens is an early local PHP architecture explorer. It scans PHP source without executing the target project and writes an experimental JSON report of declarations and selected static references, inheritance and trait relationships.

Requires PHP 8.1 and Composer:

```sh
composer install
php bin/legacylens scan fixtures/sample-legacy-app --output=report.json
composer test
```

Open `legacy-viewer/index.html` in a browser and choose the generated JSON report. Search filenames or declarations, filter by directory, and follow a reference to another file. The report records static evidence only; unknown references do not have a confirmed destination. To check the viewer's basic interactions, run `node tests/legacy-viewer.js`.

The scanner reports dynamic and ambiguous relationships as unresolved. Lexical self and parent references are resolved where possible; late static binding remains unresolved. Literal includes are heuristic, and Hack source is reported as unsupported. This is a prototype: parsing runs in the CLI process, and the filesystem and resource boundaries have not been hardened. Report output uses an exclusively created temporary file; POSIX permissions are restricted to the owner and Windows access depends on the destination ACL. An incomplete scan preserves an existing report; choose a new output path to save a partial report. The scanner checks source metadata for changes between discovery and reading, but this does not make an atomic snapshot. Use only a stable local source tree and a trusted output directory; do not scan untrusted or sensitive projects.

## Open-source regression examples

The optional regression check uses pinned releases of [FastRoute v1.3.0](https://github.com/nikic/FastRoute/tree/181d480e08d9476e61381e04a71b34dc0432e812), [Monolog 2.7.0](https://github.com/Seldaek/monolog/tree/5579edf28aee1190a798bfa5be8bc16c563bd524), and [PHP dotenv v5.4.1](https://github.com/vlucas/phpdotenv/tree/264dce589e7ce37a7ba99cb901eed8249fbec92f). Their source is not included. Retain their licenses and scan the local projects without running them. Place their reports in a directory as `fastroute-context.json`, `monolog-context.json`, and `phpdotenv-context.json`, then run:

```sh
php tests/check-open-source.php reports
```

This checks a few known relationships and explicit unsupported Hack fixtures; it is not a measure of complete dependency coverage.
