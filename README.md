# LiteSpeed Cache Warmup for Hostinger

Bash-Script das alle URLs aus der XML-Sitemap crawlt und den LiteSpeed-Cache vorwärmt. Optimiert für Hostinger Cloud/VPS mit dem 30-Minuten Cron-Timeout.

## Warum?

Nach einem Cache-Purge (Plugin-Update, Deployment, manuelles Leeren) sind alle Seiten uncached. Der erste Besucher bekommt eine langsame, ungecachte Seite. Dieses Script wärmt den Cache nachts vor, sodass morgens alle Seiten schnell laden.

## Features

- **Parallele Requests** - 5 gleichzeitige Verbindungen (konfigurierbar)
- **Batch-Verarbeitung** - Status-Updates nach jedem Batch für Live-Monitoring
- **Status-JSON** - Schreibt Fortschritt als JSON-Datei (für WordPress-Plugins)
- **Sitemap-Index-Support** - Verarbeitet sowohl Sitemap-Index als auch einzelne Sitemaps
- **Hostinger-optimiert** - Passt in das 30-Min Cron-Timeout

## Voraussetzungen

- Hostinger Cloud oder VPS mit LiteSpeed
- SSH-Zugang
- WordPress mit XML-Sitemap (Rank Math, Yoast, oder WordPress-Standard)
- LiteSpeed Cache Plugin (der eingebaute Crawler sollte **deaktiviert** sein)

## Installation

### 1. Script auf den Server kopieren

```bash
scp -P 65002 cache-warmup.sh USER@SERVER_IP:/home/USER/domains/DOMAIN/cache-warmup.sh
```

### 2. Script anpassen

Die Domain oben im Script setzen:

```bash
DOMAIN="${DOMAIN:-meine-domain.de}"
```

Oder beim Aufruf per Environment-Variable:

```bash
DOMAIN=meine-domain.de bash /home/USER/domains/meine-domain.de/cache-warmup.sh
```

### 3. Testlauf

```bash
ssh USER@SERVER_IP -p 65002
bash /home/USER/domains/DOMAIN/cache-warmup.sh
```

### 4. Cronjob bei Hostinger einrichten

Im Hostinger Panel unter **Erweitert → Cron-Jobs**:

| Feld | Wert |
|---|---|
| Befehl | `bash /home/USER/domains/DOMAIN/cache-warmup.sh` |
| Zeitplan | Täglich um 2:00 Uhr (= `0 2 * * *`) |

**Wichtig:** Hostinger Cron-Zeiten sind in **UTC**. Für deutsche Zeit (CET) 2 Uhr morgens → `0 1 * * *` im Winter (UTC+1) bzw. `0 0 * * *` im Sommer (UTC+2).

## Konfiguration

Alle Parameter können per Environment-Variable oder direkt im Script gesetzt werden:

| Variable | Default | Beschreibung |
|---|---|---|
| `DOMAIN` | `example.com` | Die Domain (ohne www) |
| `SITEMAP_URL` | `https://www.DOMAIN/sitemap_index.xml` | URL zur Sitemap |
| `PARALLEL` | `5` | Gleichzeitige Requests |
| `BATCH` | `20` | URLs pro Batch |
| `CONNECT_TIMEOUT` | `5` | TCP-Verbindungstimeout (Sekunden) |
| `REQUEST_TIMEOUT` | `10` | Max. Zeit pro Request (Sekunden) |
| `BATCH_DELAY` | `0.5` | Pause zwischen Batches (Sekunden) |
| `STATUS_FILE` | `…/wp-content/cache-warmup-status.json` | Pfad zur Status-JSON |
| `LOG_FILE` | `~/.logs/cache-warmup.log` | Pfad zur Log-Datei |

### Performance-Tuning

| Server-Typ | PARALLEL | BATCH | Erwartete Dauer (2000 URLs) |
|---|---|---|---|
| Shared Hosting | `3` | `10` | ~50–60 min |
| Cloud / VPS (2 Kerne) | `5` | `20` | ~25–35 min |
| Cloud / VPS (4+ Kerne) | `5–8` | `20` | ~15–25 min |
| Dedicated | `10` | `50` | ~8–15 min |

> **Nicht über 10 gehen** – zu viele parallele Requests können den Server überlasten und zu HTTP-Timeouts führen (werden als Errors gezählt).

## Status-JSON

Das Script schreibt den Fortschritt nach `wp-content/cache-warmup-status.json`:

```json
{
  "status": "running",
  "current": 1200,
  "total": 2090,
  "percent": 57,
  "hits": 800,
  "misses": 350,
  "errors": 50,
  "eta_min": 8
}
```

| Status | Bedeutung |
|---|---|
| `collecting` | Sitemap wird ausgelesen |
| `running` | Warmup läuft |
| `done` | Fertig |
| `error` | Fehler (z.B. Sitemap nicht erreichbar) |

### Was bedeuten Hits / Misses / Errors?

- **Hit** – Seite war bereits im LiteSpeed-Cache
- **Miss** – Seite wurde neu in den Cache geladen (= erfolgreich vorgewärmt)
- **Error** – Kein `x-litespeed-cache`-Header (z.B. nicht-cachbare Seiten, Timeouts, Redirects)

Eine Error-Rate von 5–15% ist bei WooCommerce-Shops normal (Warenkorb, Mein Konto, etc.).

## Wichtig: Prüfen ob Caching überhaupt aktiv ist

Bevor du den Warmup einrichtest, prüfe ob LiteSpeed die Seiten überhaupt cacht. Einige Plugins setzen Cookies oder `Cache-Control: no-cache`-Header, die das Caching **komplett verhindern**:

### Bekannte Problemverursacher

| Plugin | Problem | Lösung |
|---|---|---|
| **Quform** | Setzt Session-Cookies auf jeder Seite | Quform nur auf Kontakt-Seite laden (z.B. via Asset CleanUp / Perfmatters) |
| **Mailchimp for WooCommerce** | Setzt `mailchimp_landing_site`-Cookie | In LiteSpeed Cache → Cache → Ausschlüsse den Cookie ignorieren, oder Plugin nur im Checkout laden |
| **WooCommerce** | `woocommerce_items_in_cart`-Cookie bei Warenkorb-Aktionen | Normal – nur Warenkorb/Checkout/Mein-Konto sind betroffen |
| **WPML** | Setzt Sprach-Cookies | LiteSpeed → Cache → „Vary Cookie" korrekt konfigurieren |
| **Elementor Pro** | Dynamische Widgets können Caching verhindern | Seiten ohne dynamische Widgets cachen normal |

### So prüfst du ob eine Seite gecacht wird

```bash
curl -s -o /dev/null -D - https://www.deine-domain.de/ | grep -i "x-litespeed-cache"
```

**Erwartete Ausgabe:**
```
x-litespeed-cache: hit       ← Seite kommt aus dem Cache
x-litespeed-cache: miss      ← Seite wurde gerade in den Cache geladen
```

**Keine Ausgabe?** → LiteSpeed cacht diese Seite nicht. Ursache prüfen:

```bash
curl -s -o /dev/null -D - https://www.deine-domain.de/ | grep -i "set-cookie\|cache-control\|x-litespeed"
```

Wenn du `set-cookie` oder `cache-control: no-cache` siehst, blockiert etwas den Cache. Häufige Lösung: Das verursachende Plugin per **LiteSpeed Cache → Cache → Ausschlüsse** oder per **Asset CleanUp / Perfmatters** nur auf den Seiten laden wo es gebraucht wird.

### Warmup Error-Rate als Indikator

Wenn der Warmup durchgehend **über 20% Errors** zeigt, wird vermutlich ein Plugin das Caching global blockieren. In dem Fall erst das Caching-Problem lösen, dann den Warmup einrichten.

## Wie funktioniert Cache-Purge in LiteSpeed?

Wenn ein Redakteur eine Seite bearbeitet, löscht LiteSpeed **nicht den gesamten Cache**, sondern nur:

- Die bearbeitete Seite selbst
- Die Startseite
- Kategorie-/Tag-Archivseiten
- Autor-Archiv
- Seiten mit "Neueste Beiträge"-Widget

**Der Rest bleibt gecacht.** Bei 2000 Seiten werden also ~5–10 Seiten gepurged, die restlichen ~1990 bleiben schnell.

**"Purge All" im LiteSpeed-Plugin** oder **Plugin-/Theme-Updates** löschen dagegen den kompletten Cache. Dann greift der Warmup-Cronjob in der nächsten Nacht.

## LiteSpeed Crawler deaktivieren

Das LiteSpeed Cache Plugin hat einen eingebauten Crawler. Wenn du dieses Script nutzt, **deaktiviere den Plugin-Crawler**, damit sie sich nicht gegenseitig stören:

**WordPress Admin → LiteSpeed Cache → Crawler → Crawler → AUS**

## Log-Datei

Das Log wächst minimal (~100 Bytes pro Run = ~36 KB/Jahr). Kein regelmäßiges Leeren nötig.

```
[Wed Feb 11 10:52:44 AM UTC 2026] Warmup fertig: 395 hits, 1492 misses, 203 errors von 2090 (42 min)
```

## Mehrere Domains

Für mehrere WordPress-Seiten auf demselben Server einfach mehrere Cronjobs anlegen:

```
0 1 * * * DOMAIN=shop-eins.de bash /home/USER/domains/shop-eins.de/cache-warmup.sh
0 2 * * * DOMAIN=shop-zwei.de bash /home/USER/domains/shop-zwei.de/cache-warmup.sh
```

## Lizenz

MIT
