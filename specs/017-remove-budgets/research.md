# Research: Remove Budgets and Token and Cost Accounting

Checked 2026-10-27. Decisions follow constitution 2.0.0 and the spec Clarifications.

## R1. Reservations go with budgets

**Decision.** `BudgetReservation` and the reservable-store method exist only to enforce budgets. They are removed. No step is refused for spend.

**Rationale.** Without a cap there is nothing to reserve. Keeping a reservation with no cap would be dead code.

**Rejected.** Keep a reservation for concurrent runs (the Redis atomicity). It protected the budget, not a correctness property of the findings.

## R2. Token counts are read for shape only

**Decision.** The analysis keeps its reply-shape rule: a reply must carry a usage object with non-negative counts, or it is an invalid reply (spec 016). The values are read for that check and then discarded.

**Rationale.** The invalid-reply rule protects the findings (a reply without usage is malformed by the provider's contract). Removing the check would change a failure rule, which the spec forbids. Discarding the values is what the spec asks.

**Rejected.** Drop the usage check. It would change the failure path for malformed replies.

## R3. Event stream and metrics

**Decision.** `watcher.event.v2` drops every spend and token-usage field. Metrics that name budget, spend, price, cost or token usage are removed. Run, finding and outcome metrics stay.

**Rationale.** A field kept at zero reports a false figure. The published v1 schema requires the removed fields, so v1 cannot be emitted once they go.

**Rejected.** Keep v1 with zeros.

## R4. Stored spend counters after upgrade

**Decision.** The state store's old spend keys are neither read nor deleted by 0.5.0. The package stops writing them.

**Rationale.** Deleting data the package no longer owns is an operator decision. Reading them would reintroduce a spend figure.

**Consequence.** Old counter keys sit in the store until an operator clears them. The changelog says so.

## R5. Migration by deletion

**Decision.** Removed keys fail at load with a fixed message that names the key, states it was removed in 0.5.0, and states that no replacement exists. Nothing is converted.

**Rationale.** A conversion from USD to tokens is impossible without prices, and the package must not invent a figure (constitution 2.0.0).

## R6. Digest and call count

**Decision.** The digest may state the number of model calls per step (a count, not money or tokens).

**Rationale.** The spec Clarification allows it; operators see how many calls a run made without a unit of spend.
