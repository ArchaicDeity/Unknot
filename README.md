# LegacyLens

LegacyLens is an early local PHP architecture explorer. It scans PHP source without executing the target project and writes an experimental JSON report of declarations and selected static references, inheritance and trait relationships.

Requires PHP 8.1 and Composer:

```sh
composer install
php bin/legacylens scan fixtures/sample-legacy-app --output=report.json
composer test
```

The scanner reports dynamic and ambiguous relationships as unresolved. Literal includes are heuristic, and Hack source is reported as unsupported. This is a prototype: parsing runs in the CLI process, and the filesystem and resource boundaries have not been hardened. Use only a stable local source tree and a trusted output directory; do not scan untrusted or sensitive projects.
