# Continuous integration

GitHub Actions runs `.github/workflows/ci.yml` on pull requests, pushes to `main`, and manual dispatch. All jobs have read-only repository permissions and time limits; superseded runs are cancelled. The `ci-required` job fails if any dependency fails, is cancelled or is skipped. Configure branch rules to require this check after its first successful run.

Runtime PHP syntax is checked on PHP 8.0, 8.3 and 8.5. Both JavaScript assets and block metadata are checked. On PHP 8.3, CI validates the committed Composer graph, installs it, audits all locked dependencies, and runs `composer check` (PHPStan with 2 GB memory and PHPUnit).

The locked PHPUnit version requires a newer PHP than the plugin’s PHP 8.0 minimum; the minimum lane deliberately checks runtime syntax without installing development dependencies. Unit tests use WordPress doubles and do not prove real EventON/database behavior. No package is published.
