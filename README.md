# Unknot

Unknot is a local PHP architecture explorer. Its first command scans a directory without running the scanned application and writes a JSON report of file declarations and a small set of static references.

## Try the Laravel demo

With PHP 8.5 and the current stable Composer 2 installed, run:

```sh
composer install
php bin/unknot demo
```

Open the URL printed in the terminal. It starts a local, read-only dashboard with a pre-scanned report; no Laravel checkout or database setup is needed. Keep the printed URL private and press Ctrl+C to stop the server. Use `php bin/unknot demo --port=8766` to select another port.

The example covers the `src/Illuminate/Database` component of [Laravel framework v13.38.0 at c42f9ab](https://github.com/laravel/framework/tree/c42f9abd4a3d2f9a3cd6be109255ddce8092ceb8/src/Illuminate/Database), scanned on 1 November 2026. It contains 254 files, 3,237 method declarations and 1,572 static references, including 1,070 unresolved references. It does not cover the entire Laravel framework. Out-of-scope symbols and dynamic behavior can remain unresolved. Laravel is distributed under the [MIT licence](demo/LARAVEL-LICENSE.txt).

Start with `Capsule/Manager.php` and inspect the resolved reference to `Connectors/ConnectionFactory.php`, or search `Manager::connection` to find a method declaration. Clear the search field to explore all files. The report is included; starting the demo does not run Laravel or rescan its source.

To explore a potential change, select `Connectors/ConnectionFactory.php` and look at **Possible dependents**. The list includes `Capsule/Manager.php` and `DatabaseServiceProvider.php`, with the reference kind and line for each path.

## Foundation prototype

The current prototype requires PHP 8.5 and Composer 2. Compatibility has been checked with Composer 2.10.3, but use a current stable Composer release for security fixes. Install dependencies and run the included synthetic fixture:

```sh
composer install
php bin/unknot scan fixtures/sample-legacy-app --output=report.json
php bin/unknot serve --report=report.json
composer test
```

To opt in to source fingerprints for future provenance checks, add `--source-fingerprints` to `scan`. This creates a schema-2 report with a SHA-256 digest of each successfully parsed file; failed and skipped files have no digest. The normal scan remains schema 1. Digests can reveal whether known content was present, so treat a fingerprinted report as sensitive project data. A digest does not authenticate a report, prove a file belongs to a project, or make opening a file safe. Source viewing is not yet available.

For a schema-2 report, select a clean file in the dashboard and use **Compare a local file** to choose one file explicitly. The browser compares up to 2 MiB locally without displaying or sending the contents. A match means the bytes read match the fingerprint in the report; it does not authenticate the report, identify the project, or guarantee the file has not changed afterward. The bundled Laravel demo has no fingerprints and cannot use this comparison without a new scan.

Open the private URL printed by `serve` in a browser. The local service listens only on `127.0.0.1` and the URL contains a per-run access capability; keep it private. Press Ctrl+C to stop the service. You can choose a different port with `--port=8766`. A page refresh loses the capability held in browser memory: open the printed URL again or import a report locally. The [simple LegacyLens viewer](legacy-viewer/index.html) can also be opened directly and uses a file picker to read a schema-0 report without uploading it. The Unknot dashboard accepts schemas 0, 1 and 2.

For supported schema-0 reports, the LegacyLens viewer can search filenames, declarations and available method names, filter directories, follow references, and maintain a session-only reading list. Its optional legacy method evidence describes direct declarations and selected static call expressions; it does not establish runtime behavior or resolve call targets. Earlier schema-0 reports remain usable without this evidence.

The dashboard offers an interactive dependency map, search by file path or reported declaration name and kind, relationship and confidence filters, file details, a sortable hotspot table, diagnostics, local report import and JSON or text export. The report overview groups reported files by top-level folder and shows why references remain unresolved; selecting a folder filters the file list together with the search field. These are report-scope counts, not a complete map of omitted files or runtime behavior. New reports index methods directly declared in named classes, interfaces, traits and enums, with their line, visibility and static modifier. Anonymous classes, inherited or trait-composed methods, method calls and runtime dispatch are not indexed. The dashboard does not index the optional LegacyLens method evidence in schema-0 reports; rescan to search method declarations in the dashboard. Select a file to emphasize its visible connections: mint lines point to outgoing references, blue lines show incoming references, and dashed lines indicate inferred relationships. Hover over a node for its full path, use **Focus selected file** to zoom to its neighborhood, or select a linked filename in the details to follow it. **Back to previous file** restores the previous selection and search. Drag the map or nodes and use the zoom, fit and layout controls to navigate. Search includes directly connected files by default; turn that option off to show only matching files. Same-file references are hidden on the graph by default and can be shown using the graph control. Unresolved references have no drawn destination and remain visible in file details, with any possible candidates labeled as unconfirmed. The file list and details work without the graph, including for keyboard navigation.

The graph shows at most 500 files and 2,000 visible connections; narrow the search or use the file list for larger reports. Choose **Explore selected file** to start with one file, then **Expand one step** to add reported incoming and outgoing connections matching the current filters. **Undo expansion** removes the last layer; **Return to search** restores the search view. An expansion that would exceed a graph limit leaves the current view intact. Changing a reference filter restarts the focused view from the selected file. The cluster layout spreads files by their connections, and the flow layout provides a directed view. Large maps initially focus on the largest connected group; use **Fit graph** to see the whole map, or zoom in to reveal labels. The hotspot score is `2 × inbound + outbound + 3 × query indicators`: inbound and outbound count distinct other files connected by resolved static references. The score is a navigation aid, not a risk assessment. Database indicators identify a limited set of PDO/mysqli calls using direct syntax or short straight-line bindings. They are heuristic and do not contain SQL or connection arguments.

**Possible dependents** follows incoming references from the selected file and displays one shortest evidence path per result. It defaults to resolved static references; inferred links can be included explicitly and are labeled. The view stops at 500 dependent files, 2,000 examined references or five hops and reports when a limit is reached. It is a file-level navigation aid: unresolved references, unscanned code and dynamic behavior can hide dependents, and a path does not predict runtime effects or prove a method call.

The graph uses [Cytoscape.js 3.34.4](https://github.com/cytoscape/cytoscape.js) under its [MIT licence](public/assets/vendor/cytoscape.LICENSE.txt). Its unmodified browser distribution is included locally; Node.js is not required to run the dashboard. To verify or update the bundled asset during development, install Node.js and run `npm ci --ignore-scripts`, `npm run verify:vendor` and `npm test`. After changing the pinned package version, use `npm run vendor:graph` to copy its distribution and licence, then rerun the checks.

## A short demo

1. Run the commands above to scan the included fixture and start the local service. A warning exit status from the scan is expected because the fixture includes intentionally unresolved references.
2. In the dashboard, select `Orders.php`. Follow its resolved link to `Storage.php`, inspect the inferred include, and find the unresolved dynamic include and database indicators.
3. Filter by confidence or reference type, compare the hotspot counts, then export a JSON report or text summary. No target application code is executed.

The pipeline is a CLI scanner and isolated PHP parser worker, followed by a JSON report, a read-only local PHP API and a browser explorer. The browser can also import a report directly from disk. Neither route uploads source to a remote service.

On Windows, run `pwsh ./tests/windows-junction.ps1` and `pwsh ./tests/windows-report-acl.ps1` for the additional junction and report-permission checks. The scanner exits with status `2` when a report includes warnings, `1` for an error and `0` when there are no warnings. Source-relative includes are heuristic: the scanner recognizes literal strings, their concatenation, and a limited `__DIR__` form without evaluating project code. Dynamic includes and unrecognized symbols remain unresolved; ambiguous symbols include their candidate files. When a declaration has the wrong kind for a reference, the report retains the candidate and shows the expected and found kinds instead of claiming a resolved link. Linked paths and nonregular source files are skipped and reported. Files above 2 MiB are skipped with a warning, and the scan stops rather than silently exceeding its file or reference limits. The output directory must be outside the scanned directory. If a scan is incomplete, an existing report is preserved; choose a new output filename to save the partial report.

The report contains relative filenames and symbol names, which may be sensitive; treat it as local project data and delete it when no longer needed. It does not retain raw source snippets or include expressions. Report files are created with owner-only permissions on POSIX; on Windows their access depends on the destination directory's ACL. Use a destination directory trusted against concurrent modification. If the process is interrupted, a `.unknot-*` temporary report may remain in that directory; treat it as sensitive and remove it when no scan is writing. Report creation fails if the filesystem cannot provide a usable file identity; in that case an empty temporary file may need to be removed from the output directory.

Scan a stable source tree. Before opening a source, the scanner checks that the path still identifies a regular file and matches the discovered metadata. It also compares the open handle and the path after reading; a detected change is skipped and reported. The pre-open check can reject an already-replaced special file, but the path can still change between the check and the open. Filesystem metadata may not reveal every change, and these checks do not create an atomic snapshot.

This is an early prototype. Reading and parsing each source file runs in a fresh PHP process with a per-file deadline and a PHP-managed memory limit. Before receiving source or a source path, each worker must also establish a 256 MiB operating-system limit: an address-space limit on Linux or a process commit limit using a job object on 64-bit Windows. Linux needs POSIX resource-limit functions available without an ini file; Windows needs the matching PHP FFI extension at `ext/php_ffi.dll` beside the PHP executable. Workers ignore PHP configuration files and communicate through an authenticated loopback connection; source remains in memory. If the operating-system limit cannot be established, including on other operating systems, the worker reports `parser-resource-limit-unavailable` and does not receive source. The PHP executable must also provide its tokenizer and other required functions without loading configuration files. Filesystem race handling and Windows reparse-point behavior are not fully verified, so do not use the prototype on untrusted or sensitive projects. The report format is experimental and may change. It covers declarations, direct instantiation, static calls, literal includes, inheritance, interfaces (including those implemented by enums) and trait use. Late static binding and trait-dependent class references remain unresolved. Hack source files are reported as unsupported. Database indicators are incomplete and are not SQL analysis or security findings. Scanned code is parsed as data and is never bootstrapped or executed.

The per-file worker adds startup overhead, particularly when source or dependencies are on a slow filesystem. A listener is reused during the scan, but each file receives its own worker and authentication token. A small bounded set of competing connections can authenticate concurrently; the source is sent only to the authenticated worker. The scan deadline is checked between files; directory traversal itself is not separately timed. A local process can still interfere with the loopback connection and cause scan failures.

## Tested source syntax

The parser can inspect source syntax newer than the PHP runtime used to run the tool. Included fixtures exercise a PHP 5-style example, PHP 7.4 arrow functions, PHP 8.1 enums, PHP 8.2 readonly classes, PHP 8.3 typed constants, PHP 8.4 property hooks and the PHP 8.5 pipe operator. These are representative parsing and direct-reference checks, not complete language or runtime validation. Older PHP 5 syntax remains best effort; dynamic behavior and many relationships remain unresolved.

## Open-source regression examples

The scanner has also been exercised against these pinned releases; their source is not included in this repository:

- [FastRoute v1.3.0](https://github.com/nikic/FastRoute/tree/181d480e08d9476e61381e04a71b34dc0432e812) (BSD-3-Clause).
- [Monolog 2.7.0](https://github.com/Seldaek/monolog/tree/5579edf28aee1190a798bfa5be8bc16c563bd524) (MIT).
- [PHP dotenv v5.4.1](https://github.com/vlucas/phpdotenv/tree/264dce589e7ce37a7ba99cb901eed8249fbec92f) (BSD-3-Clause).

To repeat the checks, obtain those exact source revisions, retain their licenses, and scan each local project without running its code. Put their reports in a directory as `fastroute-context.json`, `monolog-context.json` and `phpdotenv-context.json`, then run:

```sh
php tests/check-open-source.php reports
```

The check verifies representative source relationships and FastRoute's three unsupported Hack fixtures. It is a focused regression check, not a measurement of complete dependency coverage.
