# Kubernetes events (removed in 0.6.0)

The Kubernetes event source (spec 008) was removed in 0.6.0. There is no replacement inside watcher.

- The `kubernetes` and `events` sections and `source.driver: kubernetes` now fail at load. Delete them from every service configuration.
- To keep pod-level problems (crash loops, OOM kills, image pull failures, failing probes, restarts) in the digest, export the cluster events to the log backend your service already reads. Then read them through `source.driver: loki` or `source.driver: datadog`, with a stream selector that matches them.

Watcher reads those events like any other log line and redacts them the same way. The full list of changes is in [CHANGELOG.md](../CHANGELOG.md) under 0.6.0.
