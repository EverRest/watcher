# Architecture

## Overview

Every external system sits behind an interface chosen by config. The only things that leave the process are the
read queries to the log backend, the requests to one model provider per step, and the messages to the notifier.

```mermaid
flowchart TB
  subgraph pkg[watcher process]
    entry["Entry points<br/>vendor/bin/watcher, Laravel command, MCP server"] --> core["Watcher::run()"]
    core --> red["Reduce<br/>EventCollector, fingerprints"]
    core --> sel["Selection and baselines"]
    core --> ana["Analysis<br/>triage, analyze, escalate"]
    core --> dig["Digest and notices"]
    core --> led["RunLedger<br/>counters, run record, heartbeat"]
  end
  subgraph ext[external systems]
    loki[("Loki")]
    dd[("Datadog")]
    prov["Model provider<br/>OpenAI or Gemini"]
    chan["Slack, Telegram, Discord"]
    st[("Redis, PDO or file")]
  end
  red --> loki
  red --> dd
  ana --> prov
  dig --> chan
  led --> st
  red --> st
  sel --> st
```

## Inside `Watcher::run()`

`Watcher` is the single entry point (constructor, `fromConfig`, `run`); a run is split into small collaborators, none of them an interface or a pipeline framework:

- `RunLedger` owns what a run leaves behind: the counters (`RunStats`), the window snapshot, the run record, the heartbeat and the summary data. Writes that must not survive a failed run (dependency snapshots) wait in its buffer until the snapshot is written.
- `Reduce\EventCollector` reads the window from the source and reduces it to problem groups, keeping the event count, the truncation flag and which watch rules and mute entries matched which fingerprint (names and counts only).
- `Watcher::locked()` decides: compare with the previous window, select candidates, then the private `analyze()` (redact, truncate, read dependencies, run the models within their input limit) and `buildDigest()`. `WindowRead` and `Analysis` carry what these steps share.
- `WindowKeys` holds the state keys and lifetimes of one service and window (lock, run record, previous snapshot, reported markers).
