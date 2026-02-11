#!/bin/bash
# =============================================================================
# LiteSpeed Cache Warmup for Hostinger Cloud / VPS
# =============================================================================
# Crawlt alle URLs aus der XML-Sitemap und waermt den LiteSpeed-Cache vor.
# Optimiert fuer Hostinger (30-Min Cron-Timeout) mit parallelen Requests.
#
# Usage:  bash cache-warmup.sh
# Cron:   0 2 * * * bash /path/to/cache-warmup.sh
#
# Voraussetzungen: curl, xargs (GNU), sh
# =============================================================================

# --- KONFIGURATION -----------------------------------------------------------
# Entweder hier anpassen ODER per Environment-Variable uebergeben:
#   DOMAIN=example.com bash cache-warmup.sh

DOMAIN="${DOMAIN:-example.com}"
SITEMAP_URL="${SITEMAP_URL:-https://www.${DOMAIN}/sitemap_index.xml}"
HOME_DIR="${HOME_DIR:-$(eval echo ~)}"

# Pfade (Hostinger-Standardstruktur)
STATUS_FILE="${STATUS_FILE:-${HOME_DIR}/domains/${DOMAIN}/public_html/wp-content/cache-warmup-status.json}"
LOG_FILE="${LOG_FILE:-${HOME_DIR}/.logs/cache-warmup.log}"

# Performance-Tuning
PARALLEL="${PARALLEL:-5}"         # Gleichzeitige Requests (5 = sicherer Default)
BATCH="${BATCH:-20}"              # URLs pro Batch (Status-Update nach jedem Batch)
CONNECT_TIMEOUT="${CONNECT_TIMEOUT:-5}"   # Sekunden fuer TCP-Verbindung
REQUEST_TIMEOUT="${REQUEST_TIMEOUT:-10}"  # Sekunden max pro Request
BATCH_DELAY="${BATCH_DELAY:-0.5}"         # Pause zwischen Batches (Sekunden)

UA="Mozilla/5.0 (compatible; CacheWarmup/2.0)"

# --- INIT --------------------------------------------------------------------

# Log-Verzeichnis sicherstellen
mkdir -p "$(dirname "$LOG_FILE")"

TMPDIR=$(mktemp -d)
trap "rm -rf $TMPDIR" EXIT

write_status() {
    echo "$1" > "$STATUS_FILE" 2>/dev/null
}

write_status '{"status":"collecting","message":"Sammle URLs aus Sitemaps..."}'

# --- URLS SAMMELN ------------------------------------------------------------

SITEMAPS=$(curl -s -A "$UA" --connect-timeout 10 --max-time 15 "$SITEMAP_URL" \
    | grep -oE 'https://[^<]+\.xml')

if [ -z "$SITEMAPS" ]; then
    # Vielleicht ist die URL selbst eine Sitemap (kein Index)
    curl -s -A "$UA" --connect-timeout 10 --max-time 15 "$SITEMAP_URL" \
        | grep -oE 'https://[^<]+</loc>' | sed 's|</loc>||' > "$TMPDIR/urls.txt"
else
    > "$TMPDIR/urls.txt"
    for SITEMAP in $SITEMAPS; do
        curl -s -A "$UA" --connect-timeout 10 --max-time 15 "$SITEMAP" \
            | grep -oE 'https://[^<]+</loc>' | sed 's|</loc>||' >> "$TMPDIR/urls.txt"
    done
fi

TOTAL=$(wc -l < "$TMPDIR/urls.txt" | tr -d ' ')

if [ "$TOTAL" -eq 0 ]; then
    write_status '{"status":"error","message":"Keine URLs in Sitemap gefunden"}'
    echo "[$(date)] FEHLER: Keine URLs in $SITEMAP_URL gefunden" >> "$LOG_FILE"
    exit 1
fi

echo "[$(date)] Warmup gestartet: $TOTAL URLs (parallel: $PARALLEL)" >> "$LOG_FILE"

# --- BATCH-VERARBEITUNG ------------------------------------------------------

CURRENT=0
HITS=0
MISSES=0
ERRORS=0
START_TIME=$(date +%s)

while [ "$CURRENT" -lt "$TOTAL" ]; do
    BATCH_START=$((CURRENT + 1))
    BATCH_END=$((CURRENT + BATCH))
    [ "$BATCH_END" -gt "$TOTAL" ] && BATCH_END=$TOTAL

    BATCH_RESULTS=$(sed -n "${BATCH_START},${BATCH_END}p" "$TMPDIR/urls.txt" \
        | xargs -P "$PARALLEL" -I{} sh -c '
            HEADER=$(curl -s -o /dev/null -D - -A "Mozilla/5.0 (compatible; CacheWarmup/2.0)" --connect-timeout '"$CONNECT_TIMEOUT"' --max-time '"$REQUEST_TIMEOUT"' "{}" 2>/dev/null | grep -i "x-litespeed-cache:")
            if echo "$HEADER" | grep -qi "hit"; then
                echo "H"
            elif [ -n "$HEADER" ]; then
                echo "M"
            else
                echo "E"
            fi
        ')

    BATCH_HITS=$(echo "$BATCH_RESULTS" | grep -c "^H$" || true)
    BATCH_MISSES=$(echo "$BATCH_RESULTS" | grep -c "^M$" || true)
    BATCH_ERRORS=$(echo "$BATCH_RESULTS" | grep -c "^E$" || true)

    HITS=$((HITS + BATCH_HITS))
    MISSES=$((MISSES + BATCH_MISSES))
    ERRORS=$((ERRORS + BATCH_ERRORS))
    CURRENT=$BATCH_END

    PCT=$((CURRENT * 100 / TOTAL))
    NOW=$(date +%s)
    ELAPSED=$((NOW - START_TIME))
    if [ "$ELAPSED" -gt 0 ] && [ "$CURRENT" -gt 0 ]; then
        ETA_SEC=$(( (TOTAL - CURRENT) * ELAPSED / CURRENT ))
        ETA_MIN=$((ETA_SEC / 60))
    else
        ETA_MIN=0
    fi

    write_status "{\"status\":\"running\",\"current\":$CURRENT,\"total\":$TOTAL,\"percent\":$PCT,\"hits\":$HITS,\"misses\":$MISSES,\"errors\":$ERRORS,\"eta_min\":$ETA_MIN}"

    sleep "$BATCH_DELAY"
done

# --- ERGEBNIS ----------------------------------------------------------------

END_TIME=$(date +%s)
DURATION=$(( (END_TIME - START_TIME) / 60 ))

write_status "{\"status\":\"done\",\"total\":$TOTAL,\"percent\":100,\"hits\":$HITS,\"misses\":$MISSES,\"errors\":$ERRORS,\"duration_min\":$DURATION,\"finished\":\"$(date)\"}"
echo "[$(date)] Warmup fertig: $HITS hits, $MISSES misses, $ERRORS errors von $TOTAL ($DURATION min)" >> "$LOG_FILE"
