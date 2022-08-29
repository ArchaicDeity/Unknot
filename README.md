# LegacyLens

LegacyLens is an early local PHP architecture explorer. It scans PHP source without executing the target project and writes an experimental JSON report of declarations and selected static references, inheritance and trait relationships.

Requires PHP 8.1 and Composer:

```sh
composer install
php bin/legacylens scan fixtures/sample-legacy-app --output=report.json
composer test
```

The scanner reports dynamic and ambiguous relationships as unresolved. Lexical self and parent references are resolved where possible; late static binding remains unresolved. Literal includes are heuristic, and Hack source is reported as unsupported. This is a prototype: parsing runs in the CLI process, and the filesystem and resource boundaries have not been hardened. Report output uses an exclusively created temporary file; POSIX permissions are restricted to the owner and Windows access depends on the destination ACL. An incomplete scan preserves an existing report; choose a new output path to save a partial report. The scanner checks source metadata for changes between discovery and reading, but this does not make an atomic snapshot. Use only a stable local source tree and a trusted output directory; do not scan untrusted or sensitive projects.
