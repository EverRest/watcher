#!/usr/bin/env bash
# Validates smoke-test env vars and runs watcher check (dry config path, no live model spend).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
CONFIG="${SMOKE_CONFIG:-$ROOT/docs/examples/smoke.config.php}"
required=(
  WATCHER_SERVICE WATCHER_LOKI_URL WATCHER_LOKI_TOKEN WATCHER_ANTHROPIC_KEY
  WATCHER_SLACK_WEBHOOK WATCHER_MODEL_TRIAGE WATCHER_MODEL_ANALYZE WATCHER_MODEL_ESCALATE
  WATCHER_PRICE_TRIAGE_IN WATCHER_PRICE_TRIAGE_OUT WATCHER_PRICE_ANALYZE_IN WATCHER_PRICE_ANALYZE_OUT
  WATCHER_PRICE_ESCALATE_IN WATCHER_PRICE_ESCALATE_OUT WATCHER_STATE_PATH
)
missing=()
for v in "${required[@]}"; do
  if [[ -z "${!v:-}" ]]; then
    missing+=("$v")
  fi
done
if ((${#missing[@]} > 0)); then
  echo "Missing environment variables (load docs/examples/smoke.env.example first):" >&2
  printf '  %s\n' "${missing[@]}" >&2
  exit 1
fi
exec php "$ROOT/bin/watcher" check --config="$CONFIG"
