#!/usr/bin/env bash
# Quick connectivity probe for Octava cloud (default prod host).
# Usage:
#   ./scripts/check-pro-oawms-connection.sh
#   BASE_URL=https://other.example.com ./scripts/check-pro-oawms-connection.sh
#   ./scripts/check-pro-oawms-connection.sh https://custom.host
#
# Prints DNS, TCP connect, TLS, TTFB, and total wall time per request (curl -w).
set -euo pipefail

BASE_URL="${1:-${BASE_URL:-https://pro.oawms.com}}"
BASE_URL="${BASE_URL%/}"

CONNECT_TIMEOUT="${CONNECT_TIMEOUT:-15}"
MAX_TIME="${MAX_TIME:-45}"

CURLFMT=$'status:%{http_code} namelookup:%{time_namelookup}s connect:%{time_connect}s appconnect:%{time_appconnect}s starttransfer:%{time_starttransfer}s total:%{time_total}s\n'

echo "Probe host: ${BASE_URL}"
echo "limits: connect_timeout=${CONNECT_TIMEOUT}s max_time=${MAX_TIME}s"
echo

probe() {
  local label="$1"
  shift
  echo "=== ${label} ==="
  if ! curl "$@" ; then
    echo "(curl exited non-zero)"
  fi
  echo
}

probe "GET root (minimal body discarded)" \
  -sS -o /dev/null \
  --connect-timeout "${CONNECT_TIMEOUT}" --max-time "${MAX_TIME}" \
  -w "${CURLFMT}" \
  "${BASE_URL}/"

probe "HEAD apps/woocommerce/connect (405/401 still means host reachable)" \
  -sS -o /dev/null -I \
  --connect-timeout "${CONNECT_TIMEOUT}" --max-time "${MAX_TIME}" \
  -w "${CURLFMT}" \
  "${BASE_URL}/apps/woocommerce/connect"

if command -v dig >/dev/null 2>&1; then
  host="${BASE_URL#*://}"
  host="${host%%/*}"
  echo "=== DNS (dig +short ${host}) ==="
  dig +short "${host}" A || true
  dig +short "${host}" AAAA || true
  echo
fi

echo "Done."
