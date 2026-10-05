# Legacy Disposition Map

The current package predates PRD/TSD 0–13. It is reference code, not the target
architecture. This map prevents accidental preservation of incompatible behavior.

| Legacy area | Disposition | Target owner/replacement | Planned phase |
|---|---|---|---|
| `InventoryManager` orchestration | Replace | `PostingEngine`, workflow and registered Actions | Core |
| `MovementPipeline` stage model | Replace selectively | `MovementPolicy`, workflow hooks, append-only ledger | Core |
| `JournalPoster`, `JournalManager` | Remove after bridge exists | Accounting/Null bridges | Accounting Bridge |
| `Journal`, `JournalEntry`, journal migration | Remove from fresh baseline | External accounting package | Accounting Bridge |
| `AverageDriver` | Replace | Separate Weighted Average and Moving Average drivers | Core |
| `FifoDriver` | Reuse algorithm only after audit | New `FifoCostingDriver` contract implementation | Core |
| `CostingManager` | Replace | Shared strategy/scope resolver | Core |
| Legacy Actions | Replace | Standard registered Document Types and Actions | Core |
| Branch/Warehouse/Rack models | Replace schema/model | Organization and Storage trees | Core |
| Legacy Item/ItemType | Replace schema/model | Core master data and mandatory Item Type | Core |
| Stage/ItemTypeStage | Replace | Per-Document-Type workflow maps/hooks | Core/WMS |
| Legacy Documents/Lines | Replace schema/model | Workflow, polymorphic references, tracking, idempotency | Core |
| Legacy Ledger/CostLayer | Replace schema/model | Append-only ledger and scoped cost layers | Core |
| `config.accounts` and overrides | Remove | External accounting service mapping | Accounting Bridge |
| `config.default_valuation=average` | Replace | Explicit FIFO/weighted-average/moving-average | Core |
| Existing migrations | Supersede, do not mutate into upgrade scripts | Fresh baseline v1 migrations | Core |
| Facade and helper | Retain public idea, redesign methods | New Core public API | Core |
| Provider auto-discovery | Retain and expand | Conditional bridges, contracts, commands, events | Core |

## Safety Rules During Replacement

- Do not delete a legacy class until every current reference has moved to its
  replacement in the same change.
- Do not edit the old migrations into a production upgrade path. Replace them
  only when the fresh baseline is introduced.
- Existing user changes in the working tree take precedence; resolve overlap
  deliberately instead of resetting files.
- Temporary compatibility adapters must be marked deprecated, tested, and given
  a removal version.
- Phase 0 coding-style enforcement covers the new test/tooling scaffold. Add
  `src/` to the formatter finder when Phase 1 replaces the legacy implementation.
