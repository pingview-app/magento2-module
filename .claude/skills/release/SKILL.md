---
name: release
description: Use when shipping the PingView Magento 2 module (`pingview/module-monitoring`) - a fix or feature is committed and should reach stores, the version needs bumping, a tag is about to be pushed, Packagist shows an old version, or someone says "wydaj wtyczkę magento", "podbij wersję", "nowa wersja modułu", "tag", "release magento", "packagist", "wrzuć na packagist".
---

# Magento module release

Repo `magento2-plugin/`, public mirror `github.com/pingview-app/magento2-module`,
package `pingview/module-monitoring`. **Packagist reads the git tag and nothing
else** - there is no upload, no `version` field in `composer.json`, and a
published tag is immutable: a wrong tag is fixed by the next tag, never by
`--force`.

Run the steps in order; each has a gate. What went wrong before, and what each
step exists to stop: the tag was cut on the commit *before* the fixes (1.2.0
shipped without them), CI still filtered on `master` after the default branch
became `main`, and the version lived in three places that drift.

## 0. Everything that ships is committed and on `main`

```bash
git status --short          # must be empty
git log --oneline origin/main..HEAD   # what the tag will add
```

`git archive` (Composer dist and `build-module-zip.ps1`) reads committed
content only. An uncommitted fix is not in the release no matter what the
working tree shows.

**Gate:** clean tree, and the commits listed are exactly the ones you mean to ship.

## 1. Pick the version, bump it with the script

Patch for fixes, minor for anything an admin sees change on the panel.

```powershell
./bump-version.ps1 1.2.2
```

It rewrites `ApiClient::VERSION` (the User-Agent and the provisioning payload)
and the ZIP name in `README.md`, refuses a version that is not newer than the
current one or already tagged, and prints every line it changed. No other file
carries the version.

**Gate:** `git diff --stat` shows exactly `Model/ApiClient.php` and `README.md`.

## 2. Verify without a Magento install

No `php` on this machine; Docker has it. Two traps: Git Bash mangles `-w`
paths (run from PowerShell, or prefix `MSYS_NO_PATHCONV=1`), and
`parity-smoke.php` reads `../Backend/contracts/` - mount the parent, not the
module, or it reports SKIPPED/missing contract.

```powershell
docker run --rm -v "C:\Projekty\availability-monitor:/w" -w /w/magento2-plugin php:8.1-cli sh -c '
  find . -path ./vendor -prune -o \( -name "*.php" -o -name "*.phtml" \) -print | xargs -n1 php -l | grep -v "No syntax";
  php Test/status-view-smoke.php; php Test/parity-smoke.php; php Test/translation-coverage.php'
docker run --rm -v "C:\Projekty\availability-monitor\magento2-plugin:/app" composer:2 validate --strict --no-check-lock
```

`translation-coverage.php` fails on any `__()` string without a `pl_PL.csv`
row *and* lists them - add the rows, do not delete the strings.

**Gate:** no lint output, three `OK` lines, `composer.json is valid`.

## 3. Nothing development-only leaks into `vendor/`

`.gitattributes` `export-ignore` is the single list of what a store does not
receive. A new top-level dev file (a doc, a script, `.claude/`) goes there
**and** into the CI grep in `.github/workflows/ci.yml` ("Verify the dist
archive").

```bash
git archive --format=tar HEAD | tar -tf - | grep -E '^(Test/|\.github/|\.claude/|.*\.md$)' | grep -v '^README.md$'
```

**Gate:** the grep prints nothing.

## 4. Commit, tag, push - one push

```bash
git add Model/ApiClient.php README.md
git commit -m "chore: release X.Y.Z"
git tag X.Y.Z
git push origin main X.Y.Z
```

Tag name = bare `X.Y.Z`, no `v` (CI strips it but Packagist lists what it
gets). CI on the tag re-checks lint, smoke tests, the dist archive **and that
the tag equals `ApiClient::VERSION`** - a mismatch fails the run but the tag is
already on GitHub and Packagist has already crawled it, so step 1's gate is the
one that matters.

## 5. Confirm it arrived

```bash
gh run list --repo pingview-app/magento2-module --branch X.Y.Z --limit 1
curl -s https://repo.packagist.org/p2/pingview/module-monitoring.json | grep -o '"version":"[^"]*"' | head -3
```

The Packagist GitHub App is installed on the repo, so the new version shows
within a minute. If it does not: packagist.org -> package -> **Update**.

**Gate:** CI `completed success`, Packagist lists `X.Y.Z` first.

## 6. Hand the ZIP to the frontend

`frontend-nextjs` serves the ZIP and renders version, size and sha256 from one
record (`src/lib/plugins/catalog.ts`); `llms.txt` repeats the filename.

```powershell
./publish-frontend.ps1        # builds the ZIP from HEAD, copies it, rewrites catalog.ts + llms*.txt + the SEO test
```

Then in `frontend-nextjs`: `npx jest seo-content-consistency`, commit the files
the script names, push `github main`.

**Gate:** the test passes and `git diff --stat` in the frontend shows the ZIP,
`catalog.ts`, both `llms*.txt` and the test - nothing else.

## First time on a new machine

Packagist is already registered and the GitHub App installed; nothing to set
up. `origin` must be `pingview-app/magento2-module` (`git remote -v`) - the old
private `myusname/magento2-plugin` is `old-origin` and gets nothing.

## Tag already pushed with the wrong content

Do not delete or move it: Packagist keeps what it crawled, and a moved tag
gives two stores two different 1.2.2s. Fix `main`, then run this skill from
step 1 with the **next** patch number. The bad version stays listed; nothing
points stores at it once the newer one exists.

## Red flags

- "Tag first, fix CI later" - Packagist has already crawled the tag.
- "The version is only in one file" - it is in two; the script exists so you never check.
- "I'll push the tag from the working tree" - the archive is HEAD, not the tree.
- A `.md` other than `README.md` in `git archive` output.
