# Top item groups

A Zabbix dashboard widget that answers a question [Top hosts](https://www.zabbix.com/documentation/current/en/manual/web_interface/frontend_sections/dashboards/widgets/top_hosts)
can't: *"show me the top N **things inside my hosts** by some item value, with configurable columns."*

Very often the interesting entity isn't the host — it's something the host contains: an IIS site, a database, a
mounted filesystem, a network interface. That entity is usually encoded in an item's name or key, produced by
low-level discovery, one item per (entity, metric) pair. Zabbix has no first-class concept for it, so every
built-in widget flattens it back into a list of items and the relationship is lost.

This widget introduces that missing concept: a **group**, extracted from an item's name, key, or tag by a pattern
you configure, used as the row identity instead of the host. Everything else — columns, thresholds, bars,
sparklines, ordering — works exactly like Top hosts, because that's what this widget is built from.

## Concept model

| Term | Meaning |
|---|---|
| **Group** | The entity a row represents (an IIS site, a database, a mount point). Not a Zabbix entity — it exists only inside this widget. |
| **Group key** | The string extracted from an item that identifies its group. Also the row's default label. |
| **Group by** | The widget-level rule that derives a group key from an item (see the five modes below). |
| **Column** | Same as Top hosts: one user-declared column with a data source (item value / host name / group name / text) and its own display configuration. |
| **Cell** | The intersection of a group and a column — resolves to at most one item. |
| **Row identity** | Internally, `host + group key` by default, so the same group name on two different hosts is two rows, not one — unless you turn on *Merge groups across hosts*. |
| **Master column** | The column selected in *Order by*. Drives Top N / Bottom N, same role it plays in Top hosts. |

## Installation

1. Copy this entire directory to `<zabbix-frontend>/ui/modules/topitemgroups/` on your Zabbix instance (i.e. a
   sibling of `ui/modules/` next to `ui/widgets/`).
2. In the Zabbix frontend, go to **Administration → General → Modules** and click **Scan directory**. Zabbix
   scans both `ui/widgets/` and `ui/modules/` the same way, so this module is picked up exactly like a built-in
   widget would be.
3. Find **Top item groups** in the module list and **enable** it.
4. Add it to a dashboard from the "Add widget" list.

To upgrade, replace the directory contents and re-run **Scan directory** if `manifest.json`'s `id` or `namespace`
changed (they haven't between releases unless noted in the changelog). To uninstall, disable the module on the
Modules page, then delete the directory.

**Compatibility:** built against Zabbix **7.4.13rc1**. Relies on core classes and API options
(`CItemKey`, `CRegexHelper`, item `search`/`tags` options, `lastclock` output, `CWidgetField` and its subclasses)
that are internal to Zabbix and not guaranteed stable across major versions — it has not been tested against
anything outside the 7.4.x line.

## Configuring a group

**Group by** selects one of five modes. All of them produce a plain string; items that don't produce one are
excluded from the table — never bucketed into an "unknown" row.

| Mode | Configure | Extracts |
|---|---|---|
| **Item name pattern** | A pattern with `*` wildcards over the item's (resolved) name, e.g. `IIS [*] *`, plus which `*` to capture (1-based, left to right) | The matched wildcard's text |
| **Item key pattern** | Same wildcard pattern/capture, but matched against the item's key | The matched wildcard's text |
| **Item key parameter** | Which key parameter to read (1-based) | That parameter, via Zabbix's own key parser — quoting, escaping, nested brackets, commas all handled correctly |
| **Item tag** | A tag name | That tag's value on the item |
| **Regular expression** | A regex plus which capture group (1-based) and whether to match it against the item's name or key | The chosen capture group |

Wildcard matching is anchored to the *whole* name/key (not a substring search) and case-insensitive, matching how
Zabbix's own wildcard search behaves elsewhere in the product. Regex mode is the escape hatch for anything a
wildcard can't express; an invalid pattern is rejected with a form error when you save, not a runtime failure.

**Merge groups across hosts** (off by default): when on, two hosts both reporting a "Default Web Site" group
collapse into one row instead of two. Off, they stay separate — usually what you want when the two hosts are
genuinely independent instances, on when they're shards of one logical service. When several items across
different hosts compete for the same merged cell, one wins deterministically: most recently updated first, then
lowest item ID — so the same data always renders the same table.

## Columns

Identical to Top hosts, plus one new data source:

- **Item value** — pick an item name pattern (a *column* pattern, independent of the group pattern — e.g. the
  group pattern might be `IIS [*] *` while a column's own pattern is `IIS [*] Requests per second`), aggregation,
  display mode (as-is / bar / indicators / sparkline), thresholds, highlights, decimals, min/max, history vs.
  trends.
- **Host name** — the row's representative host (see "A note on row identity" below), with its usual context menu.
- **Group name** — the resolved group key, as plain text.
- **Text** — a static string supporting `{HOST.*}`/`{INVENTORY.*}`/user macros, resolved against the row's
  representative host, exactly like Top hosts' text columns do.

## Sorting from the table header

Clicking a column header ranks the table by that column; clicking the header that is already ranking toggles
between Top N and Bottom N. The arrow marks the ranking column and direction.

Two things are worth knowing:

- **Sorting re-ranks, it does not re-order.** The widget only ever fetches values for the whole candidate set for
  the ranking column, so "top 10 by requests/sec" and "top 10 by errors/sec" are different sets of groups, not the
  same ten groups in a different order. Clicking a header is exactly equivalent to changing *Order by* in the
  configuration — hence the round trip to the server.
- **The choice is yours alone and is temporary.** It lives in the browser: it survives the widget's own refresh
  cycle, but not a page reload, and it is not shared with anyone else viewing the dashboard. Reconfiguring the
  widget rebuilds it and *Order by* takes over again. Make a sort order permanent by setting *Order by*.

Ranking by a binary-valued column is accepted but only selects rows — binary values have no defined order, the same
as when such a column is chosen in *Order by*.

## Worked examples

Configuration only — none of these need code changes.

### 1. IIS sites (item name pattern)

Items named `IIS [Default Web Site] Requests per second`, `IIS [Intranet] Response time`, etc.

- Group by: **Item name pattern**, pattern `IIS [*] *`, capture **1**
- Columns: item name pattern `IIS [*] Requests per second`, `IIS [*] Response time (ms)`, `IIS [*] Errors per second`
- Order by: Requests per second, **Top N**

| Site | Requests/sec | Response time | Errors/sec |
|---|---:|---:|---:|
| Default Web Site | 1,204 | 84 ms | 0.4 |
| Intranet | 318 | 41 ms | 0 |

### 2. Database capacity (item key parameter)

Items with keys like `db.database.size["orders"]`, `db.database.size["billing"]`.

- Group by: **Item key parameter**, parameter **1**
- Columns: item name pattern matching the size items, displayed as a bar with a byte-unit min/max

### 3. Mounted filesystems (item key parameter)

Items with keys like `vfs.fs.size[/var/log,total]`, `vfs.fs.size[/var/log,used]`.

- Group by: **Item key parameter**, parameter **1** (the mount point)
- Group filter: not yet available in this build — see Known limitations below if you need to drop `/dev/*`-style
  system entities without touching the pattern.
- Columns: `vfs.fs.size[*,total]`, `vfs.fs.size[*,used]` as a bar, `vfs.fs.size[*,pfree]` with thresholds

### 4. Kubernetes pods (item tag)

Items tagged `pod: <pod-name>` by your discovery rule.

- Group by: **Item tag**, tag name `pod`
- Columns: whatever per-pod metrics your discovery produces (CPU, memory, restarts)

## A note on row identity

Every row has a "representative host" — the host whose item won the most-recently-updated tie-break for that
group — used for the Host name column, text-macro resolution, and item context menus. When rows are **not**
merged across hosts, this is unambiguous: a row is one specific host's group. When they **are** merged, the
representative is the lowest host ID among every host that contributed *any* matching item to that group,
computed independently of which column happened to discover it — deterministic and stable across refreshes, but
worth knowing if a merged row's Host name column shows a host you didn't expect.

For the same reason, a merged row can't be selected/highlighted or broadcast to other dashboard widgets (there's
no single correct host to broadcast) — clicking it is a no-op. Unmerged rows behave exactly like Top hosts rows
always have.

## Known limitations (current build)

- **No per-column pattern override for group extraction.** The group-by pattern is shared across all columns
  (each column still has its own item pattern, but the *group key* is always extracted the same way, from
  whichever item each column found). Useful when every metric for an entity follows the same naming convention,
  which is the common case; a template mixing naming conventions per metric isn't supported yet.
- **No "Group filter" field yet.** There's no built-in way to exclude resolved group keys (e.g. drop `tempdb` or
  `/dev/*`) without narrowing the group pattern itself.
- **No actionable message when the candidate-item cap is hit.** A very broad group pattern is capped (using
  Zabbix's own configured search-results limit) rather than left unbounded, but hitting the cap doesn't yet show
  a warning — the table just silently reflects a truncated candidate set.
- Not yet load-tested at large scale (target is 200 groups × 8 columns) or verified under restricted item
  permissions.
