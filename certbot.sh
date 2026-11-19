#!/usr/bin/env bash
# PowerBrands Productions: HTTPS certificate. Run as root after deploy.sh:  bash certbot.sh
set -euo pipefail
D=powerbrandsproductions.com
fail() { printf '\n\033[1;31mERROR: %s\033[0m\n' "$*" >&2; exit 1; }
[ "$(id -u)" -eq 0 ] || fail "Run as root (sudo -i first)."
command -v certbot >/dev/null || fail "certbot not installed (apt install certbot python3-certbot-nginx)."

IP=$(curl -s -m 3 -H 'Metadata-Flavor: Google' \
  http://metadata.google.internal/computeMetadata/v1/instance/network-interfaces/0/access-configs/0/external-ip || true)
IP=${IP:-136.112.197.51}
echo "This server's public IP: $IP"

ok=1
for h in "$D" "www.$D"; do
  got=$(getent ahostsv4 "$h" | awk '{print $1}' | sort -u | tr '\n' ' ')
  echo "$h -> ${got:-<none>}"
  [ "$(echo $got)" = "$IP" ] || ok=0
done
[ "$ok" = 1 ] || fail "DNS does not point (only) to $IP yet. Fix the GoDaddy records or wait a few minutes, then re-run."

certbot --nginx -d "$D" -d "www.$D" --redirect -m andy@powerbrandsny.com --agree-tos -n
nginx -t && systemctl reload nginx
sleep 1
echo "https://$D/up -> $(curl -s -o /dev/null -w '%{http_code}' https://$D/up) (want 200)"
echo "http://$D/    -> $(curl -s -o /dev/null -w '%{http_code}' http://$D/) (want 301)"
systemctl list-timers 2>/dev/null | grep -q certbot && echo "Auto-renew timer: OK"
