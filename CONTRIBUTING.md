# Working on this module

Everything here runs from a clone of this repository, not from
`vendor/pingview/module-monitoring`: `Test/` and the scripts are stripped from
the Composer archive (`.gitattributes`, `export-ignore`).

## Checks without a Magento installation

There is no `php` on a typical Windows workstation, so the commands below are
written for Docker. Run them from PowerShell: Git Bash rewrites the `-w`
argument into a Windows path.

```powershell
docker run --rm -v "C:\Projekty\availability-monitor:/w" -w /w/magento2-plugin php:8.1-cli sh -c '
  find . -path ./vendor -prune -o \( -name "*.php" -o -name "*.phtml" \) -print | xargs -n1 php -l | grep -v "No syntax";
  php Test/status-view-smoke.php; php Test/parity-smoke.php; php Test/translation-coverage.php'
docker run --rm -v "C:\Projekty\availability-monitor\magento2-plugin:/app" composer:2 validate --strict --no-check-lock
```

| Test | What it proves |
|---|---|
| `status-view-smoke.php` | `Model/StatusView.php`, the shared view model, still maps API payloads to the keys the template renders |
| `parity-smoke.php` | the panel still delivers every capability the WordPress plugin and the PrestaShop module deliver. It reads a contract from the PingView backend repository checked out beside this one; in a standalone clone it reports SKIPPED rather than failing |
| `translation-coverage.php` | every `__()` string has a row in `i18n/pl_PL.csv`, and no row is left over |

`Test/Unit` and `setup:di:compile` need a real Magento development install with
the module in `app/code`:

```bash
vendor/bin/phpunit -c dev/tests/unit/phpunit.xml.dist app/code/PingView/Monitoring/Test/Unit
bin/magento setup:di:compile
```

## Adding a capability

`data-pv-capability` on a card root is what `parity-smoke.php` reads. Markup,
classes and copy can be refactored freely; a capability that disappears from
this panel and not from the other two fails the build. See
[CAPABILITIES.md](CAPABILITIES.md).

## Releasing

`.claude/skills/release/SKILL.md` has the full procedure. In short:
`./bump-version.ps1 X.Y.Z`, run the checks, commit, `git tag X.Y.Z`,
`git push origin main X.Y.Z`. Packagist reads the tag; a published tag is
immutable, so a mistake is fixed by the next tag, never by `--force`.

`./build-module-zip.ps1` builds the `app/code` archive through `git archive`,
so the ZIP and the Composer download contain exactly the same files, from
committed content only.
