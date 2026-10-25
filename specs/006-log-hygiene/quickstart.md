# Quickstart: Log Hygiene Reports

1. Enable it for one service:

   ```php
   'hygiene' => ['enabled' => true],   // everything else has a default
   ```

2. See today's picture (reads the last window, prints four reports, changes nothing but its own aggregates):

   ```bash
   vendor/bin/watcher hygiene --config=config/watcher.php
   ```

   The cost report works without step 1 (from run records).

3. Machine-readable, for a dashboard or a ticket:

   ```bash
   vendor/bin/watcher hygiene --config=config/watcher.php --json 2>/dev/null | jq '.reports.privacy.sites'
   ```

4. Weekly delivery to the notice channel; the host's scheduler calls it (non-empty reports only, unchanged findings at most every `repeat_days`):

   ```bash
   vendor/bin/watcher hygiene --config=config/watcher.php --notify
   ```

5. Proposals appear after `min_history_days` (14) days of daily passes, each with a ready-to-paste fragment and a replay of its effect:

   ```text
   mute  a1b2c3d4e5   ['fingerprint' => 'a1b2c3d4e5f6', 'until' => null],
         seen 16/16 days, 8100 events/day, 0 tokens; replay: 0 candidates removed
   ```

   A `watch` proposal appears only for a precursor whose masked text may be shown: it folds at least `hygiene.variable_distinct`
   messages (or you set `hygiene.allow_constant_labels: true`). A constant sentence such as "Connection pool exhausted" gets no label
   and no watch proposal by default, because a sentence that never varies may be one person's name.

   Paste into `mute` yourself. To decline: `watcher hygiene --config=… --decline=a1b2c3d4e5`.

## Checks that prove it works

- `tests/EndToEnd/HygienePrivacyTest`: feeds the privacy corpus plus `hygiene.log` and fails if any seeded value appears in any report, JSON, notice or stored record.
- Laravel: `php artisan watcher:hygiene --report=cost --json`.
