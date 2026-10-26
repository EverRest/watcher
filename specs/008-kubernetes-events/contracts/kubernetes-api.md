# Contract: what the client may send

| Method | Path | Query | Used by |
|---|---|---|---|
| GET | `/apis/events.k8s.io/v1/namespaces/{ns}/events` | `limit=500`, `continue` | run, ingest, check |
| GET | `/api/v1/namespaces/{ns}/events` | `limit=500`, `continue` | fallback when the above is 404 |
| GET | `/api/v1/namespaces/{ns}/pods` | `limit=500`, `continue` (`labelSelector` supported by the client, not sent by the source) | run, ingest, check |

Headers: `Accept: application/json`, `Authorization: Bearer {token}`. `{ns}` must match `^[a-z0-9]([-a-z0-9]*[a-z0-9])?$`.
Anything else throws before sending (`KubeApi::get()` checks method and path against this table). Architecture test
`KubernetesReadOnlyTest` scans `src/Source/Kubernetes` and `src/Source/KubernetesEventsSource.php` for any HTTP method
other than `GET`, any `watch`, `exec`, `log`, `proxy`, `secrets`, `configmaps` path segment, and any `verify => false`.
Failure classes (never the URL, token or body): 401/403 `unauthorised`, 404 `not found`, 429 `rate limited` (after retry),
timeout/transport `timeout`/`unavailable`, non-JSON `invalid response`.

## Minimal RBAC (documented)

```yaml
kind: Role   # per namespace; ClusterRole only for several namespaces, and it then grants these reads cluster-wide
rules:
  - apiGroups: [""]
    resources: ["pods"]
    verbs: ["get", "list"]
  - apiGroups: ["", "events.k8s.io"]
    resources: ["events"]
    verbs: ["get", "list"]
```
