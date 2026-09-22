# PingView Monitoring for Magento 2

Thin Magento 2 admin client for PingView external uptime monitoring. The module creates or links a PingView monitor, stores its API key using Magento encryption, and renders backend-computed status, availability and incidents. It does not run probes, cron jobs, checkout automation or notification delivery inside the store.

## Requirements

- Magento Open Source or Adobe Commerce 2.4.4+, or Mage-OS 3.x
- PHP 8.1 - 8.4
- outbound HTTPS access to `https://pingview.app`

## Install with Composer (recommended)

```bash
composer require pingview/module-monitoring
bin/magento module:enable PingView_Monitoring
bin/magento setup:upgrade
bin/magento cache:flush
```

Composer installs the module into `vendor/pingview/module-monitoring`. Nothing is
copied into `app/code`, so the module survives a rebuild of the deployment
artifact. In production mode also run the store’s usual deployment commands,
normally `setup:di:compile` and `setup:static-content:deploy`.

Update later with:

```bash
composer update pingview/module-monitoring
bin/magento setup:upgrade
```

On Adobe Commerce Cloud, add the requirement and commit the updated
`composer.lock`; the deploy pipeline installs it. This is the only supported
route on Cloud, where `app/code` is not writable.

## Install from the release archive

For stores that are not Composer-managed. Download
`pingview-monitoring-1.2.0.zip` from https://pingview.app/en/for/magento and
unpack it into `app/code`. The archive already contains the `PingView/Monitoring`
directory, so the module lands in `app/code/PingView/Monitoring`. Then run the
same `module:enable` / `setup:upgrade` / `cache:flush` sequence as above.

## First run

Open **System > PingView Monitoring**. Start with an email address, or expand the
existing-account option and paste a write-scoped PingView API key.

## Security and data flow

- All admin routes use Magento ACL and POST actions use Magento's form key protection.
- The API key is encrypted with Magento's configured encryption key before it enters `core_config_data`.
- API responses are escaped by Magento templates and cached for five minutes. A last-known result can be shown during a temporary API failure.
- Disconnect removes local credentials only. Monitoring continues in PingView.
- Status is rendered from the public API according to `BR-STATUS-01` / `CT-STATUS`; availability and incident state are never recomputed by this module.

## Scope

The module renders all twenty capabilities of `CT-PARITY`, the contract that
keeps this panel, the WordPress plugin and the PrestaShop module showing one
product. See [CAPABILITIES.md](CAPABILITIES.md) for what each one is, where it
renders and what it reads.

Deferred: per-store-view monitors, automatic (rather than one-click) URL
resync, and rendering the checkout journey's verification state.

## Checks

`Test/` is excluded from the Composer archive, so these run from a clone of the
repository, not from `vendor/pingview/module-monitoring`.

Neither smoke test needs a Magento installation:

```bash
php Test/status-view-smoke.php   # the shared view model
php Test/parity-smoke.php        # CT-PARITY capability coverage
```

`parity-smoke.php` compares the panel against a contract held in the PingView
backend repository. That repository is checked out beside this one only on a
full PingView working copy, so in a standalone clone the test reports SKIPPED
instead of failing.

The unit tests and the compile check need a Magento development installation
with this module in `app/code`:

```bash
vendor/bin/phpunit -c dev/tests/unit/phpunit.xml.dist app/code/PingView/Monitoring/Test/Unit
bin/magento setup:di:compile
```

Build the release archive on Windows with `./build-module-zip.ps1`. It delegates
to `git archive`, so the ZIP and the Composer download contain exactly the same
files, and it archives committed content only.

The admin UI ships an English source and a Polish translation (`i18n/pl_PL.csv`).
