# Dashboards and alert rules

| File | What | Needs |
|---|---|---|
| `grafana-watcher.json` | one dashboard with an events row and a metrics row | Loki and/or Prometheus |
| `grafana-loki.json` | the events row only | Loki fed by the `log`, `file` or `http` path |
| `grafana-prometheus.json` | the metrics row only | Prometheus fed by the `prometheus` sink |
| `prometheus-rules.yaml` | alert rules on the metrics | Prometheus |
| `grafana-alerts.yaml` | the same alerts on the Loki event stream, file-provisioning format | Grafana-managed alerting with Loki |
| `mapping.md` | field table, shippers and example queries for Kibana, Datadog, Metabase, Superset | |

Import a dashboard from Dashboards, New, Import: Grafana asks for the datasources (`DS_LOKI`, `DS_PROMETHEUS`). The dashboards carry no uid and no hard-coded datasource; the Loki stream selector is the text variable `stream` (default `app="watcher"`). Thresholds in the alert rules are examples (36 h assumes a daily run). Replace `LOKI_DATASOURCE_UID`, the runbook URL and the team label in the alert files.
