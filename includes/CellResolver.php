<?php declare(strict_types = 0);
/*
** Copyright (C) 2001-2026 Zabbix SIA
**
** This program is free software: you can redistribute it and/or modify it under the terms of
** the GNU Affero General Public License as published by the Free Software Foundation, version 3.
**
** This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY;
** without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
** See the GNU Affero General Public License for more details.
**
** You should have received a copy of the GNU Affero General Public License along with this program.
** If not, see <https://www.gnu.org/licenses/>.
**/


namespace Modules\TopItemGroups\Includes;

/**
 * Turns a flat, already-fetched item list into row-identity-keyed cells: resolves each item's group key via
 * GroupKeyResolver, builds the row identity (REPORT.md §7.4's composite hostid+group key, or group alone when
 * merging across hosts), and where several items land on the same row picks exactly one per the §7.3 tie-break.
 *
 * Pure, dependency-free (besides GroupKeyResolver itself): takes plain item arrays in, returns plain arrays out.
 * No API calls, so - like GroupKeyResolver - it can be unit-tested without a frontend (see
 * tests/unit/CellResolverTest.php). This is deliberately a separate class from GroupKeyResolver: resolving *a*
 * group key from *one* item is a different concern from deciding which of *several* same-group items wins a cell,
 * and TASKS.md's own test matrix (§12) treats them as two independently-testable units.
 */
class CellResolver {

	/**
	 * Build the opaque row-identity key. JSON-encoded rather than a delimited string so that group keys containing
	 * arbitrary characters (including whatever delimiter a naive concatenation would pick) can never collide two
	 * distinct rows into one - see REPORT.md's note on this under Q1/§7.4.
	 */
	public static function makeRowKey(string $hostid, string $group, bool $merge_hosts): string {
		return $merge_hosts ? json_encode(['g' => $group]) : json_encode(['h' => $hostid, 'g' => $group]);
	}

	/**
	 * Reshape a fetched item into the plain array GroupKeyResolver::resolve() expects.
	 *
	 * @param array  $item        Must contain 'key_' and 'tags' (if the mode needs them) and whichever of
	 *                             'name'/'name_resolved' $name_field names.
	 * @param string $name_field  GroupKeyResolver::FIELD_NAME always reads the item's 'name' key; this is where
	 *                             the name-resolved-vs-name choice (REPORT.md Q6) actually gets applied.
	 */
	public static function toResolverItem(array $item, string $name_field): array {
		return [
			'name' => $item[$name_field] ?? '',
			'key_' => $item['key_'] ?? '',
			'tags' => $item['tags'] ?? []
		];
	}

	/**
	 * Resolve a group key for every item, group items sharing a row identity, and pick one winning item per row
	 * per the cell-resolution tie-break (TASKS.md §7.3):
	 *   1. Enabled and supported - expected to already be guaranteed by the status/monitored filter used when the
	 *      items were fetched; this method does not re-check it.
	 *   2. More specific pattern match - not implemented: this method has no notion of "pattern specificity" for
	 *      items that all arrived from the same fetch (Model A only, no per-column pattern override in this pass -
	 *      see REPORT.md §7.2). A future per-column override would need to pass that information in.
	 *   3. Most recently updated value - compared via each item's 'lastclock'.
	 *   4. Lowest itemid, so results are stable across refreshes.
	 *
	 * Items producing no group key are excluded, never bucketed (§7.1) - simply absent from the returned cells.
	 *
	 * @param array $items           itemid => item. Each item must contain 'itemid', 'hostid', 'lastclock', plus
	 *                                whatever self::toResolverItem() reads.
	 * @param array $groupby_config  See GroupKeyResolver's class docblock.
	 * @param bool  $merge_hosts     Collapse the row identity to the group key alone (TASKS.md §7.4).
	 * @param string $name_field     See self::toResolverItem().
	 * @param array $rows_meta       [in/out] row_key => ['hostid' => representative hostid, 'group' => group key].
	 *                                Updated for every row_key this call encounters; when merging across hosts,
	 *                                the lowest hostid is kept as the representative, deterministically.
	 *
	 * @return array  row_key => winning item.
	 */
	public static function resolveItemsToCells(array $items, array $groupby_config, bool $merge_hosts,
			string $name_field, array &$rows_meta): array {
		$candidates_by_row = [];

		foreach ($items as $item) {
			$group = GroupKeyResolver::resolve(self::toResolverItem($item, $name_field), $groupby_config);

			if ($group === null) {
				continue;
			}

			$row_key = self::makeRowKey($item['hostid'], $group, $merge_hosts);
			$candidates_by_row[$row_key][$item['itemid']] = $item;

			if (!array_key_exists($row_key, $rows_meta) || $item['hostid'] < $rows_meta[$row_key]['hostid']) {
				$rows_meta[$row_key] = ['hostid' => $item['hostid'], 'group' => $group];
			}
		}

		$cells = [];

		foreach ($candidates_by_row as $row_key => $row_items) {
			$cells[$row_key] = self::pickWinningItem($row_items);
		}

		return $cells;
	}

	/**
	 * Pick exactly one item among several competing for the same cell: most recently updated first, lowest itemid
	 * as the final deterministic fallback (§7.3 steps 3-4). Stable regardless of input order or PHP's sort
	 * algorithm, since itemid is a total order with no ties.
	 */
	public static function pickWinningItem(array $items): array {
		uasort($items, static function(array $a, array $b): int {
			$clock_cmp = ($b['lastclock'] ?? 0) <=> ($a['lastclock'] ?? 0);

			return $clock_cmp !== 0 ? $clock_cmp : ((int) $a['itemid'] <=> (int) $b['itemid']);
		});

		return reset($items);
	}
}
