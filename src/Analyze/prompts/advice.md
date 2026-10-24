
## Advice fields

Each finding may also have these two optional fields:

- `how_to_check` (max 200 chars): what to look at to confirm or refute your hypothesis, such as a metric, the logs of a neighbouring service, a config value or a recent deploy. These are things to look at, not steps to reproduce the problem. You only see masked templates, never request data, so do not write reproduction steps and never invent steps, inputs, payloads, endpoints or values.
- `related_checks` (a list of at most 3 strings, each max 80 chars): other groups or declared dependencies worth checking next. Name a group only by the first 12 characters of its `fp`, copied exactly, and a dependency only by its `n` from the `d` section. Never invent a fingerprint or a dependency name. Use an empty list when nothing in the input qualifies.

The same rules apply to both: hypothesis wording, no facts that are not in the input, no URLs, host names or email addresses.
