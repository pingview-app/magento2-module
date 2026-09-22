# Publikacja na Packagist — `pingview/module-monitoring`

Packagist nie hostuje kodu. Crawluje **publiczne** repo git i czyta z niego
`composer.json` + tagi. Przed publikacją: pakietu nie było, repo
było prywatne, brak tagów, a `composer.json` wskazuje na
`github.com/pingview-app/magento2-monitoring`, które nie istnieje.

## Kroki

| # | Co | Po co | Jak |
|---|----|-------|-----|
| 1 | Publiczne repo `pingview-app/magento2-monitoring` | Packagist przyjmuje tylko publiczny URL; nazwa musi zgadzać się z `support.source` w `composer.json` | `gh repo create pingview-app/magento2-monitoring --public --source=. --remote=upstream --push` |
| 2 | Tag `1.2.0` | `composer.json` nie ma pola `version` — Packagist bierze wersję z taga. CI odrzuca tag ≠ `ApiClient::VERSION` | `git tag 1.2.0 && git push upstream 1.2.0` |
| 3 | Poczekać na zielone CI na tagu | Sprawdza `composer validate --strict` i że do archiwum nie wycieka `Test/`, `.github/` itd. | GitHub → Actions |
| 4 | Submit na Packagist | Rejestracja pakietu | packagist.org → **Submit** → wklej `https://github.com/pingview-app/magento2-monitoring` → **Check** → **Submit** |
| 5 | Włączyć GitHub App „Packagist” | Bez tego nowe tagi pojawią się dopiero po ręcznym **Update** / crawlu | GitHub → repo → Settings → Integrations → GitHub Apps → Packagist |
| 6 | Weryfikacja | Dowód, że `composer require` z README działa | `composer show -a pingview/module-monitoring` — ma pokazać `versions: 1.2.0` |

## Kolejne wydania

1. Podbić `ApiClient::VERSION` i wersję zipa w `README.md`.
2. `git tag X.Y.Z && git push upstream X.Y.Z` — Packagist odbiera hook, koniec.

Wersje na Packagist są **niemutowalne** (od 07/2026): błędnego taga nie
poprawia się przez `--force`, tylko nowym tagiem.

## Uwagi

- Packagist crawluje **wyłącznie** przez URL repo; nie da się wgrać zipa.
- Kod prywatny → Private Packagist (płatny) lub Satis; klient musi wtedy
  dodać `repositories` do swojego `composer.json`. Dla modułu OSL-3.0 nie ma
  powodu — zwykłe Packagist.
- Konto Packagist ma być na tym samym GitHubie, który ma admina w org
  `pingview-app` — inaczej GitHub App z kroku 5 nie podpięje repo.
