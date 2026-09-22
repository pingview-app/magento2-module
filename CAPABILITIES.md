agy# PingView for Magento 2 — capability record

What this module does, where each thing renders, and what it talks to. One of
three sibling records with the same structure
([WordPress](../wordpress-plugin/CAPABILITIES.md),
[PrestaShop](../presta-plugin/CAPABILITIES.md)) so the three can be read
side by side; `CT-PARITY` is the rule they are held to.

Last verified: 2026-08-25 against module 1.2.0.

## 1. Identity

| | |
|---|---|
| Module | `PingView_Monitoring` |
| Version | 1.2.0 (git tag, `ApiClient::VERSION`) |
| Platform | Magento Open Source / Adobe Commerce 2.4.4+, Mage-OS 3.x |
| PHP | 8.1 - 8.4 |
| Install path | `vendor/pingview/module-monitoring` (Composer) or `app/code/PingView/Monitoring` (ZIP) |
| Module deps | `Magento_Backend`, `Magento_Config`, `Magento_Store`, `Magento_Catalog`, `Magento_Payment`, `Magento_Cron` |
| Entry point | Admin menu **System → PingView Monitoring** (`pingview/dashboard/index`) |
| ACL | `PingView_Monitoring::dashboard` (read), `PingView_Monitoring::manage` (write) |
| Outbound | HTTPS to the configured API base, default `https://pingview.app/api/v1` |

## 2. Screens

| Screen | When | What it holds |
|---|---|---|
| Setup | No API key stored | Email + consent form, API-key form behind a disclosure, three reassurance points |
| Panel | API key and monitor id stored | Status header, KPI strip, cards below (§3) |
| Waiting | Connected, no check completed yet | "Monitoring is ready" placeholder instead of empty cards |

The setup screen re-opens with the API-key form expanded when provisioning was
refused with `USER_EXISTS` / `DUPLICATE_MONITOR`, because for an existing
account that form is the only way forward.

## 3. Capabilities (CT-PARITY)

All 20 contract capabilities render here. Each carries its
`data-pv-capability` marker in `view/adminhtml/templates/dashboard.phtml`;
`Test/parity-smoke.php` fails the build if one disappears.

| Capability | Renders as | Data source |
|---|---|---|
| `status-pill` | Status word + icon + tone in the header | `quick-status.status` |
| `kpi-strip` | Status / uptime 24h / response median / needs-attention | `quick-status` + `StatusView::responseLatency` |
| `action-items` | "Worth fixing" list, highest priority first | `quick-status.actionItems` |
| `availability` | 24h and 7d percentages + 24h block chart | `quick-status.availability`, `/uptime?period=24h` |
| `watched` | Checklist of what is checked and what triggers an alert | `quick-status.checkInterval` |
| `monitored-pages` | Cart, checkout, a category, a product with "monitored" or a checkbox | Magento URLs + `token/info.monitors` |
| `alert-channels` | Delivery channels, links to notification and report settings | Static + team-scoped app URLs |
| `locations` | Per-location status and latency | `quick-status.locations` |
| `certificate` | SSL and domain state, including the plain-HTTP finding | `quick-status.ssl`, `.domainInfo` |
| `security-headers` | Observatory grade, checks passed, failed tests by points | `/deep-scan.httpObservatory` |
| `quality-report` | Lighthouse performance/accessibility + Core Web Vitals | `/deep-scan.lighthouse` |
| `checkout-journey` | What a synthetic journey asserts, never claimed as running | Static + the reported shop profile |
| `incidents` | Last 3 incidents with state and duration | `/incidents?limit=3` |
| `scheduler-watch` | Magento cron diagnosis (local) + heartbeat monitor (premium) | `cron_schedule` table + `POST /monitors` |
| `status-badge` | Badge preview and embed snippet, or a link to create a page | `/status-pages` |
| `target-resync` | Warning + one-click repoint when the base URL moved | Stored URL vs `getBaseUrl()` |
| `account-identity` | Which account and team this store reports into | `token/info.account`, `.team` |
| `independence` | Statement that monitoring runs outside the store | Static |
| `upsell` | Link into the full product | Team-scoped app URL |
| `disconnect` | Removes local credentials, leaves the monitor running | — |

Derivations behind these live in `Model/StatusView.php`, ported verbatim from
the PrestaShop module so the three panels cannot reach different conclusions
from the same payload.

## 4. API surface

Every read happens once per panel load and is cached as one blob
(`Model/StatusService.php`).

| Endpoint | Method | When | Timeout |
|---|---|---|---|
| `/magento/provision` | POST | Setup form | 30s |
| `/token/info` | GET | Connect by key, every panel load | 15s |
| `/monitors` | POST | Connect with no monitor, add pages, enable cron watch | 15s |
| `/monitors/{id}` | PATCH | Repoint target, pause/resume heartbeat | 15s |
| `/monitors/{id}/quick-status` | GET | Panel load | 15s |
| `/monitors/{id}/incidents?limit=3` | GET | Panel load | 15s |
| `/monitors/{id}/uptime?period=24h` | GET | Panel load | 15s |
| `/monitors/{id}/deep-scan` | GET | Panel load | 15s |
| `/status-pages` | GET | Panel load | 15s |
| `/shop-profile` | POST | After provisioning, connecting, and a resync | 15s |
| `/shop-profile?target=` | GET | Reserved for reading the verification state | 15s |

Provisioning gets 30s because it creates a user, a team, a monitor and an API
key, then sends mail. At a shorter timeout the request can fail *after* the
account exists, which strands the store on the setup screen.

Failures are typed: the envelope's `error.code` is carried through
`ApiClient::request()` so callers can act on `USER_EXISTS` or `PLAN_GATE`
rather than on prose.

## 5. Stored data

All in `core_config_data` under `pingview/general/`, listed once in
`Config::OWNED_CONFIG_KEYS`.

| Key | Holds | Notes |
|---|---|---|
| `api_key` | API key | Encrypted with Magento's encryptor |
| `monitor_id`, `team_id` | Ids | |
| `store_url` | The address the monitor was pointed at | Drives `target-resync` |
| `connected_at` | Timestamp | |
| `account_email`, `team_name`, `team_plan` | Account identity | Email is masked when it came from `token/info` |
| `scheduler_monitor_id`, `scheduler_ping_url` | Cron heartbeat | `heartbeatUrl` is returned only on create |
| `shop_profile_hash` | Fingerprint of the last profile sent | Stops pointless resends |
| `api_base`, `cache_ttl` | Configuration | From `etc/config.xml`, not written by the module |

Status blob cached under cache id `pingview_monitor_status`, tag `PINGVIEW`,
TTL from `cache_ttl` (default 300s, floor 60s).

Any write is followed by `ReinitableConfigInterface::reinit()`. Without it the
`config` cache keeps serving the pre-write snapshot and a fresh connection
reads back as "not connected".

## 6. Merchant actions

| Action | Route | ACL | Effect |
|---|---|---|---|
| Start free monitoring | `dashboard/provision` | manage | Creates account + monitor, stores the key, sends the shop profile |
| Connect an API key | `dashboard/connect` | manage | Reuses a monitor for this host or creates one |
| Refresh | `dashboard/refresh` | dashboard | Drops the status cache |
| Monitor the current address | `dashboard/resync` | manage | PATCHes the target, re-sends the profile |
| Start monitoring selected pages | `dashboard/pages` | manage | One monitor per page the module itself offered |
| Watch / stop watching Magento cron | `dashboard/scheduler` | manage | Creates or pauses a heartbeat monitor |
| Disconnect this store | `dashboard/disconnect` | manage | Deletes local keys only |

## 7. Background behaviour

**None.** The module declares no cron job and runs no code on storefront
requests. The cron heartbeat is a line the merchant adds to the crontab that
already runs `bin/magento cron:run`; the module never pings it itself, because
a pinger driven by Magento cron cannot report that Magento cron stopped.

## 8. Security

- Admin routes gated by ACL; every write action is `HttpPostActionInterface` with Magento's form key.
- API key encrypted before it reaches the database; a payload the current crypt key cannot read is treated as absent rather than sent as an empty bearer.
- All template output escaped through the block's escapers.
- `dashboard/pages` accepts only addresses the module itself offered.
- No secrets in logs: failures log the exception, never the key.

## 9. Translations

`i18n/pl_PL.csv`, 162 rows, covering all 145 strings the module uses. 49 rows
are reused verbatim from the PrestaShop module's Polish translation so the same
sentence reads the same way in both back offices.

## 10. Removal

| Action | Effect |
|---|---|
| Disable the module | Panel disappears; monitoring continues |
| `module:uninstall` | `Setup/Uninstall.php` deletes every `pingview/general/*` row, so no encrypted credential is left in a database that gets dumped or copied |
| Disconnect | Local credentials only; the monitor keeps running |

## 11. Verification

```bash
# Parity: every contract capability is present, none invented
php Test/parity-smoke.php

# The shared view model (401 assertions, no Magento needed)
php Test/status-view-smoke.php

# Unit tests, from a Magento installation
vendor/bin/phpunit -c dev/tests/unit/phpunit.xml.dist app/code/PingView/Monitoring/Test/Unit

# Syntax
php -l Model/StatusView.php
bin/magento setup:di:compile
```

## 12. Known gaps

| Gap | Why |
|---|---|
| No shop-profile verification state rendered | `GET /shop-profile` is wired but the checkout-journey card does not yet read its `verification` block |
| No automatic target resync | The repoint is one click, not automatic; the WordPress plugin does it on panel load |
| No admin notice outside the panel | A merchant who never opens the page is not told about an outage there; alerts are delivered by PingView instead |
| `monitorablePages()` offers one category and one product | The first match, not a chosen representative page |
