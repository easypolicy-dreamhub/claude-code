#!/bin/bash
# Finder sync for cPanel.
# Copies the latest data files from GitHub into the live Panel Finder and
# Great Journey Finder. Only the data/*.json files are replaced; index.html
# and anything else in the folders is left alone.
# A file is only replaced if the download worked, looks like real data, and
# is not suspiciously smaller than the current one (a safety net against a
# bad download wiping the site).

BASE="$HOME/public_html/members"
LOG="$HOME/finder-sync.log"
RAW="https://raw.githubusercontent.com/easypolicy-dreamhub"

log() { echo "$(date '+%Y-%m-%d %H:%M') $*" >> "$LOG"; }

sync_app() {
  local folder="$1" repo="$2"; shift 2
  local dir="$BASE/$folder/data"
  if [ ! -d "$dir" ]; then log "$folder: folder $dir not found, skipped"; return; fi
  for f in "$@"; do
    local tmp="$dir/.$f.download"
    if ! curl -fsSL --max-time 180 -o "$tmp" "$RAW/$repo/main/data/$f"; then
      log "$folder/$f: download failed, kept current file"; rm -f "$tmp"; continue
    fi
    local first; first=$(head -c 1 "$tmp")
    if [ "$first" != "{" ] && [ "$first" != "[" ]; then
      log "$folder/$f: download is not data, kept current file"; rm -f "$tmp"; continue
    fi
    local new_size old_size
    new_size=$(wc -c < "$tmp")
    old_size=$( [ -f "$dir/$f" ] && wc -c < "$dir/$f" || echo 0 )
    if [ "$old_size" -gt 0 ] && [ "$new_size" -lt $((old_size / 2)) ]; then
      log "$folder/$f: new file far smaller ($new_size vs $old_size bytes), kept current file"
      rm -f "$tmp"; continue
    fi
    if [ -f "$dir/$f" ] && cmp -s "$tmp" "$dir/$f"; then rm -f "$tmp"; continue; fi
    mv -f "$tmp" "$dir/$f" && chmod 644 "$dir/$f" && log "$folder/$f: updated"
  done
}

sync_app panel-finder panel-finder \
  hospitals.json specialists.json meta.json clinics.json clinics-meta.json
sync_app great-journey-finder great-journey-finder \
  hospitals.json clinics.json pharmacies.json physio.json vaccination.json meta.json

log "sync finished"
# Keep the log short: last 500 lines only.
tail -n 500 "$LOG" > "$LOG.tmp" 2>/dev/null && mv -f "$LOG.tmp" "$LOG"
