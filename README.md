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
`pingview-monitoring-1.2.1.zip` from https://pingview.app/en/for/magento and
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
- Status, availability and incident state are computed by PingView and displayed as received. The module never recalculates them, so the panel, the PingView dashboard and any public status page always agree.

## What the panel shows

Uptime and response time, the result from each monitoring location, incidents,
certificate and domain expiry, security headers, a Lighthouse quality report,
and whether this store's own cron is still running. It is the same set the
PingView plugins for WordPress and PrestaShop show, so a merchant running more
than one platform reads one product. [CAPABILITIES.md](https://github.com/pingview-app/magento2-module/blob/main/CAPABILITIES.md) lists
every card and what it reads.

Not supported yet: a separate monitor per store view, automatic (rather than
one-click) repointing after the base URL changes, and the verification state of
the synthetic checkout journey.

## Translations

The admin UI ships English source strings and a Polish translation
(`i18n/pl_PL.csv`).

## Contributing

Tests, the release procedure and how the panel is kept in step with the other
PingView plugins: [CONTRIBUTING.md](https://github.com/pingview-app/magento2-module/blob/main/CONTRIBUTING.md).
