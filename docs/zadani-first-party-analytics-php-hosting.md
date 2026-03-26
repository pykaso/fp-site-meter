# Zadání: minimalistické first-party měření návštěvnosti pro statické weby na PHP hostingu

Cílem je vytvořit **velmi jednoduchý self-hosted analytický systém** pro statické weby běžící na vlastním hostingu.  
Řešení musí být navržené tak, aby šlo nasadit na běžný **PHP hosting bez dalších služeb**, pouze s podporou **PHP + SQLite**.

## Hlavní požadavky

Systém bude tvořen pouze těmito částmi:

1. **jeden PHP tracking endpoint**
2. **jeden JavaScript snippet**, který se vloží do měřených stránek
3. **minimalistický dashboard v PHP**
4. **SQLite databáze jako soubor**

## Účel systému

Systém má měřit:

- počet návštěv stránek
- počet kliknutí na konkrétní odkazy, zejména download odkazy
- agregaci dat po měsících
- zobrazení statistik v jednoduchém dashboardu

## Architektura

### 1. Tracking endpoint
Vytvoř jeden PHP soubor, například:

`/analytics/track.php`

Tento endpoint bude přijímat události z JavaScript snippetu pomocí HTTP requestu.

Musí podporovat minimálně tyto event typy:

- `pageview`
- `link_click`

### 2. JavaScript snippet
Vytvoř jeden JS soubor, například:

`/analytics/tracker.js`

Tento snippet bude vložen do stránek přes:

```html
<script defer src="https://mojedomena.cz/analytics/tracker.js" data-site="example.com"></script>
```

Snippet musí:

- po načtení stránky automaticky odeslat `pageview`
- umožnit měřit kliky na odkazy označené atributem, například:

```html
<a href="/downloads/app.zip" data-track-click="download_app_zip">Download</a>
```

- při kliknutí odeslat event `link_click`
- fungovat bez externích knihoven
- být co nejmenší a čistý vanilla JS

### 3. SQLite databáze
Data budou ukládána do SQLite souboru, například:

`/analytics/data/analytics.sqlite`

Databáze musí obsahovat tabulky minimálně pro:

- uživatele dashboardu
- raw eventy
- případně sessions/visits, pokud budou potřeba

Preferovaný minimální návrh:

#### tabulka `users`
- `id`
- `email`
- `password_hash`
- `created_at`

#### tabulka `events`
- `id`
- `site`
- `event_type`
- `event_name`
- `page_url`
- `referrer`
- `visitor_id`
- `ip_hash`
- `user_agent`
- `created_at`

Poznámky:
- IP adresu neukládat v raw podobě, pouze hash
- hesla ukládat pouze přes `password_hash()`
- timestamps ukládat v UTC

### 4. Dashboard
V adresáři například:

`/analytics/dashboard/`

poběží minimalistický dashboard v PHP.

Dashboard musí být chráněný přihlášením přes:

- email
- heslo

Bez přihlášení nesmí být statistiky dostupné.

## Funkční požadavky

### Tracking pageviews
Po načtení stránky se odešle událost `pageview` s těmito daty:

- site identifier
- aktuální URL
- referrer
- user agent
- visitor ID uložené v cookie/localStorage
- timestamp

### Tracking kliků
Kliknutí se bude měřit pouze na odkazech označených atributem:

```html
data-track-click="nazev_udalosti"
```

Příklad:

```html
<a href="/downloads/app.dmg" data-track-click="download_mac_dmg">Stáhnout pro macOS</a>
```

Odešle se:

- `event_type = link_click`
- `event_name = download_mac_dmg`
- `page_url`
- `site`
- timestamp

## Dashboard – minimální výstupy

Dashboard musí obsahovat minimálně tyto sekce:

### 1. Přehled návštěv podle měsíců
Tabulka:

- měsíc
- počet pageviews
- počet unikátních visitorů

### 2. Přehled kliknutí na odkazy podle měsíců
Tabulka:

- měsíc
- event_name
- počet kliknutí

### 3. Filtrování podle webu
Protože stejný systém bude použit pro více domén / aliasů, dashboard musí umět filtrovat podle `site`.

Například:

- všechny weby
- domena1.cz
- domena2.cz
- app.example.com

### 4. Volitelně detail posledních událostí
Jednoduchý seznam posledních 50 eventů:

- datum a čas
- site
- event type
- event name
- page_url

## Bezpečnostní požadavky

### Autentizace dashboardu
- přihlášení pouze email + heslo
- session-based login v PHP
- logout
- heslo hashovat přes `password_hash()`
- ověřovat přes `password_verify()`

### Ochrana endpointu
- tracking endpoint nesmí spadnout na chybných datech
- validovat vstupy
- omezit maximální délku stringů
- používat prepared statements
- základní ochrana proti spam requestům
- povolit pouze POST, případně GET pouze pokud je to opravdu nutné

### CSRF
- dashboard formuláře chránit proti CSRF

### XSS
- všechny hodnoty v dashboardu escapovat přes `htmlspecialchars()`

## Privacy požadavky

Systém má být privacy-friendly a first-party.

Proto:

- neukládat plnou IP adresu
- neukládat žádné zbytečné osobní údaje
- visitor ID držet jen jako náhodný anonymní identifikátor
- žádné third-party služby
- žádné externí cookies bannery logiky v rámci tohoto zadání, ale implementace má být co nejméně invazivní

## Technické požadavky

- čisté PHP bez frameworku
- čistý JavaScript bez frameworku
- SQLite přes PDO
- jednoduchá adresářová struktura
- snadná instalace na běžný hosting
- žádný Composer dependency tree, pokud to není nutné
- žádný Node.js build krok
- žádný Docker
- žádná externí databáze

## Návrh adresářové struktury

```text
/analytics
  /data
    analytics.sqlite
  /dashboard
    index.php
    login.php
    logout.php
  /includes
    auth.php
    config.php
    db.php
    helpers.php
  track.php
  tracker.js
  install.php
```

## Instalace

Vytvoř jednoduchý `install.php`, který:

- založí SQLite DB a tabulky
- vytvoří prvního admin uživatele
- po instalaci půjde snadno smazat nebo zablokovat

## Požadavky na kvalitu implementace

Výstup musí být:

- kompletní
- funkční
- jednoduchý na nasazení
- čitelný
- bez zbytečné architektury a abstrakcí

Preferuji:

- co nejméně souborů
- srozumitelný procedural nebo lehce strukturovaný PHP styl
- žádné overengineering
- vše připravené tak, aby to šlo rovnou nahrát na hosting

## Ukázka použití ve stránce

Implementace musí podporovat tento způsob vložení:

```html
<script defer src="/analytics/tracker.js" data-site="mojedomena.cz"></script>
```

A pro měření kliknutí:

```html
<a href="/downloads/app.zip" data-track-click="download_app_zip">Download</a>
<a href="/downloads/app.dmg" data-track-click="download_app_dmg">Download for macOS</a>
```

## Co má být výsledkem

Dodané řešení musí obsahovat:

1. kompletní PHP backend
2. kompletní JavaScript snippet
3. SQLite schéma
4. jednoduchý login/logout dashboard
5. měsíční přehled pageviews
6. měsíční přehled kliknutí podle `event_name`
7. stručný README s instalací

## Důležité omezení

Nechci:

- Matomo
- Google Analytics
- externí CDN
- React/Vue dashboard
- složitou architekturu
- event streaming
- Redis
- API token management
- multi-user role system

Chci opravdu jen malý interní analytický nástroj pro vlastní statické weby na PHP hostingu.
