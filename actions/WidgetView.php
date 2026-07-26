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


namespace Modules\TopItemGroups\Actions;

use API,
	CAggFunctionData,
	CControllerDashboardWidgetView,
	CControllerResponseData,
	CItemHelper,
	CMacrosResolverHelper,
	CNumberParser,
	CSettingsHelper,
	CSvgGraph,
	Manager;

use Modules\TopItemGroups\Widget;
use Modules\TopItemGroups\Includes\CWidgetFieldColumnsList;
use Modules\TopItemGroups\Includes\CellResolver;
use Modules\TopItemGroups\Includes\GroupKeyResolver;

class WidgetView extends CControllerDashboardWidgetView {

	/** @property int $sparkline_max_samples  Limit of samples when requesting sparkline graph data for time period. */
	protected int $sparkline_max_samples;

	protected function init(): void {
		parent::init();

		$this->addValidationRules([
			'contents_width'	=> 'int32'
		]);
	}

	protected function doAction(): void {
		$data = [
			'name' => $this->getInput('name', $this->widget->getDefaultName()),
			'error' => null,
			'user' => [
				'debug_mode' => $this->getDebugMode()
			]
		];

		if (!$this->fields_values['override_hostid'] && $this->isTemplateDashboard()) {
			$data['configuration'] = $this->fields_values['columns'];
			$data['show_thumbnail'] = false;
			$data['rows'] = [];
		}
		else {
			$data += $this->getData();
		}

		$this->setResponse(new CControllerResponseData($data));
	}

	private function getData(): array {
		$columns = $this->fields_values['columns'];

		$groupids = !$this->isTemplateDashboard() && $this->fields_values['groupids']
			? getSubGroups($this->fields_values['groupids'])
			: null;

		if ($this->isTemplateDashboard()) {
			$hostids = $this->fields_values['override_hostid'];
		}
		else {
			$hostids = $this->fields_values['hostids'] ?: null;
		}

		$tags_exist = array_key_exists('tags', $this->fields_values);
		$maintenance_status = $this->fields_values['maintenance'] == HOST_MAINTENANCE_STATUS_OFF
			? HOST_MAINTENANCE_STATUS_OFF
			: null;

		// Host-level scoping is unaffected by the row-identity pivot: groups are extracted from items, but items
		// still belong to hosts, and the widget still scopes which hosts may contribute (REPORT.md Q1/Q2).
		$hosts = API::Host()->get([
			'output' => ['name', 'maintenance_status', 'maintenance_type', 'maintenanceid'],
			'groupids' => $groupids,
			'hostids' => $hostids,
			'evaltype' => $tags_exist ? $this->fields_values['evaltype'] : null,
			'tags' => $tags_exist ? $this->fields_values['tags'] : null,
			'filter' => ['maintenance_status' => $maintenance_status],
			'monitored_hosts' => true,
			'preservekeys' => true
		]);

		$hostids = array_keys($hosts);
		$maintenanceids = array_filter(array_column($hosts, 'maintenanceid', 'maintenanceid'));

		$db_maintenances = $maintenanceids && $maintenance_status === null
			? API::Maintenance()->get([
				'output' => ['name', 'description'],
				'maintenanceids' => $maintenanceids,
				'preservekeys' => true
			])
			: [];

		if (!$hostids) {
			return [
				'configuration' => $columns,
				'show_thumbnail' => false,
				'rows' => []
			];
		}

		$show_thumbnail = false;

		foreach ($columns as $column) {
			if ($column['data'] == CWidgetFieldColumnsList::DATA_ITEM_VALUE
					&& $column['display_value_as'] == CWidgetFieldColumnsList::DISPLAY_VALUE_AS_BINARY
					&& $column['show_thumbnail'] == 1) {
				$show_thumbnail = true;
				break;
			}
		}

		$this->sparkline_max_samples = ceil($this->getInput('contents_width') / count($columns));

		$merge_hosts = (bool) $this->fields_values['merge_hosts'];
		$groupby_config = $this->getGroupByConfig();
		$name_field = $this->isTemplateDashboard() ? 'name' : 'name_resolved';
		$need_tags = $groupby_config['mode'] == GroupKeyResolver::MODE_ITEM_TAG;

		$master_column_index = $this->fields_values['column'];
		$master_column = $columns[$master_column_index];

		// row_key (see CellResolver::makeRowKey()) => ['hostid' => representative hostid, 'group' => group key].
		// Populated as a side effect of resolving every column's items - see CellResolver::resolveItemsToCells().
		$rows_meta = [];

		$master_items = [];
		$master_row_values = [];
		$master_sparkline_values = [];

		switch ($master_column['data']) {
			case CWidgetFieldColumnsList::DATA_ITEM_VALUE:
				$numeric_only = self::isNumericOnlyColumn($master_column);
				$fetched = self::getItemsByPattern($master_column['item'], $numeric_only, $groupids, $hostids,
					$name_field, $need_tags
				);

				$master_items = CellResolver::resolveItemsToCells($fetched, $groupby_config, $merge_hosts, $name_field,
					$rows_meta
				);

				$master_row_values = self::getItemValues($master_items, $master_column);

				if ($master_column['display'] == CWidgetFieldColumnsList::DISPLAY_SPARKLINE) {
					$config = $master_column + ['contents_width' => $this->sparkline_max_samples];
					$master_sparkline_values = self::getItemSparklineValues($master_items, $config);
				}

				break;

			case CWidgetFieldColumnsList::DATA_GROUP_NAME:
				// No single item pattern drives row discovery for this master type (there is no Top hosts
				// equivalent - $hosts itself served that role there, "for free", because hosts are a first-class,
				// already-fetched entity; groups are not, so they must be discovered from items, see REPORT.md Q1).
				$discovered = self::getGroupDiscoveryItems($groupby_config, $groupids, $hostids, $name_field,
					$need_tags
				);
				CellResolver::resolveItemsToCells($discovered, $groupby_config, $merge_hosts, $name_field, $rows_meta);

				foreach ($rows_meta as $row_key => $meta) {
					$master_row_values[$row_key] = $meta['group'];
				}

				break;

			case CWidgetFieldColumnsList::DATA_TEXT:
				$discovered = self::getGroupDiscoveryItems($groupby_config, $groupids, $hostids, $name_field,
					$need_tags
				);
				CellResolver::resolveItemsToCells($discovered, $groupby_config, $merge_hosts, $name_field, $rows_meta);

				$row_hostids = array_values(array_unique(array_column($rows_meta, 'hostid')));
				$hostid_text = CMacrosResolverHelper::resolveWidgetTopHostsTextColumns(
					[$master_column_index => $master_column['text']], $row_hostids
				)[$master_column_index];

				foreach ($rows_meta as $row_key => $meta) {
					$text = $hostid_text[$meta['hostid']];

					if ($text !== '') {
						$master_row_values[$row_key] = $text;
					}
					else {
						unset($rows_meta[$row_key]);
					}
				}

				break;
		}

		if (!$rows_meta) {
			return [
				'configuration' => $columns,
				'show_thumbnail' => $show_thumbnail,
				'rows' => []
			];
		}

		$master_items_only_numeric_present = $master_column['data'] == CWidgetFieldColumnsList::DATA_ITEM_VALUE
			&& ($master_column['aggregate_function'] == AGGREGATE_COUNT
				|| !array_filter($master_items,
					static function(array $item): bool {
						return !in_array($item['value_type'], [ITEM_VALUE_TYPE_FLOAT, ITEM_VALUE_TYPE_UINT64]);
					}
				)
			);

		if ($this->fields_values['order'] == Widget::ORDER_TOP_N) {
			if ($master_items_only_numeric_present) {
				arsort($master_row_values, SORT_NUMERIC);

				$master_entities_min = end($master_row_values);
				$master_entities_max = reset($master_row_values);
			}
			elseif ($master_column['data'] != CWidgetFieldColumnsList::DATA_ITEM_VALUE
					|| $master_column['display_value_as'] != CWidgetFieldColumnsList::DISPLAY_VALUE_AS_BINARY) {
				natcasesort($master_row_values);
			}
		}
		else {
			if ($master_items_only_numeric_present) {
				asort($master_row_values, SORT_NUMERIC);

				$master_entities_min = reset($master_row_values);
				$master_entities_max = end($master_row_values);
			}
			elseif ($master_column['data'] != CWidgetFieldColumnsList::DATA_ITEM_VALUE
					|| $master_column['display_value_as'] != CWidgetFieldColumnsList::DISPLAY_VALUE_AS_BINARY) {
				natcasesort($master_row_values);
				$master_row_values = array_reverse($master_row_values, true);
			}
		}

		$show_lines = $this->isTemplateDashboard() ? 1 : $this->fields_values['show_lines'];
		$master_row_values = array_slice($master_row_values, 0, $show_lines, true);

		// Unlike Top hosts (:238-248 in the original), there is no backfill of unranked extra rows here: padding
		// a Top-N list of groups with arbitrary, unranked, order-undefined extra groups reads as a bug rather
		// than a feature - see REPORT.md's resolution of open question §11.2. If fewer than $show_lines rows have
		// a ranking value, fewer rows are shown.
		$master_row_keys = array_keys($master_row_values);

		$rows_meta = array_intersect_key($rows_meta, $master_row_values);
		$master_items = array_intersect_key($master_items, $master_row_values);

		// Hosts actually contributing to the rows being shown - the cheap scope for non-master column fetches.
		// Only valid when rows are not merged across hosts: a merged row's contributing hosts for THIS column may
		// differ from whichever host's item happened to win the master column's tie-break (REPORT.md §7.4/§11.5
		// resolution: merging changes which items compete for a cell, it does not imply they share a host).
		$shown_hostids = array_values(array_unique(array_column($rows_meta, 'hostid')));

		$number_parser = new CNumberParser([
			'with_size_suffix' => true,
			'with_time_suffix' => true,
			'is_binary_size' => false
		]);

		$number_parser_binary = new CNumberParser([
			'with_size_suffix' => true,
			'with_time_suffix' => true,
			'is_binary_size' => true
		]);

		$item_values = [];
		$text_columns = [];

		foreach ($columns as $column_index => &$column) {
			if ($column['data'] == CWidgetFieldColumnsList::DATA_TEXT) {
				$text_columns[$column_index] = $column['text'];
				continue;
			}

			if ($column['data'] != CWidgetFieldColumnsList::DATA_ITEM_VALUE) {
				continue;
			}

			$sparkline_item_values = [];
			$calc_extremes = $column['display'] == CWidgetFieldColumnsList::DISPLAY_BAR
				|| $column['display'] == CWidgetFieldColumnsList::DISPLAY_INDICATORS;

			$column += ['min' => '', 'min_binary' => '', 'max' => '', 'max_binary' => ''];

			if ($column_index == $master_column_index) {
				$column_cells = $master_items;
				$column_item_values = $master_row_values;
				$sparkline_item_values = $master_sparkline_values;
			}
			else {
				$numeric_only = self::isNumericOnlyColumn($column);

				if (!$calc_extremes || ($column['min'] !== '' && $column['max'] !== '')) {
					// Shown rows only. Merged mode cannot narrow by host (see $shown_hostids comment above).
					$fetch_hostids = $merge_hosts ? $hostids : $shown_hostids;
					$fetched = self::getItemsByPattern($column['item'], $numeric_only, $groupids, $fetch_hostids,
						$name_field, $need_tags
					);
					$column_cells = CellResolver::resolveItemsToCells($fetched, $groupby_config, $merge_hosts, $name_field,
						$rows_meta
					);
					$column_cells = array_intersect_key($column_cells, $master_row_values);
				}
				else {
					// Full candidate set, so bar/indicator scaling reflects the true range, not just the shown
					// page - mirrors Top hosts' own deliberate behaviour here (REPORT.md Q8).
					$fetched = self::getItemsByPattern($column['item'], $numeric_only, $groupids, $hostids,
						$name_field, $need_tags
					);
					$column_cells = CellResolver::resolveItemsToCells($fetched, $groupby_config, $merge_hosts, $name_field,
						$rows_meta
					);
				}

				$column_item_values = self::getItemValues($column_cells, $column);

				if ($column['display'] == CWidgetFieldColumnsList::DISPLAY_SPARKLINE) {
					$config = $column + ['contents_width' => $this->sparkline_max_samples];
					$sparkline_item_values = self::getItemSparklineValues($column_cells, $config);
				}
			}

			if ($calc_extremes) {
				if ($column['min'] !== '') {
					$number_parser_binary->parse($column['min']);
					$column['min_binary'] = $number_parser_binary->calcValue();

					$number_parser->parse($column['min']);
					$column['min'] = $number_parser->calcValue();
				}

				if ($column['max'] !== '') {
					$number_parser_binary->parse($column['max']);
					$column['max_binary'] = $number_parser_binary->calcValue();

					$number_parser->parse($column['max']);
					$column['max'] = $number_parser->calcValue();
				}

				if ($column_index == $master_column_index) {
					if ($column['min'] === '') {
						$column['min'] = $master_entities_min;
						$column['min_binary'] = $column['min'];
					}

					if ($column['max'] === '') {
						$column['max'] = $master_entities_max;
						$column['max_binary'] = $column['max'];
					}
				}
				elseif ($column_item_values) {
					if ($column['min'] === '') {
						$column['min'] = min($column_item_values);
						$column['min_binary'] = $column['min'];
					}

					if ($column['max'] === '') {
						$column['max'] = max($column_item_values);
						$column['max_binary'] = $column['max'];
					}
				}
			}

			if (array_key_exists('thresholds', $column)) {
				foreach ($column['thresholds'] as &$threshold) {
					$number_parser_binary->parse($threshold['threshold']);
					$threshold['threshold_binary'] = $number_parser_binary->calcValue();

					$number_parser->parse($threshold['threshold']);
					$threshold['threshold'] = $number_parser->calcValue();
				}
				unset($threshold);
			}

			$item_values[$column_index] = [];

			foreach ($column_cells as $row_key => $item) {
				$itemid = $item['itemid'];

				$column_value = [];

				if (array_key_exists($itemid, $column_item_values)) {
					$column_value['value'] = $column_item_values[$itemid];
				}

				if (array_key_exists($itemid, $sparkline_item_values)) {
					$column_value['sparkline_value'] = $sparkline_item_values[$itemid];
				}

				if ($column_value && array_key_exists($row_key, $master_row_values)) {
					$item_values[$column_index][$row_key] = [
						'item' => $item,
						'is_binary_units' => isBinaryUnits($item['units'])
					] + $column_value;
				}
			}
		}
		unset($column);

		$text_columns = CMacrosResolverHelper::resolveWidgetTopHostsTextColumns($text_columns, $shown_hostids);

		$rows = [];

		foreach ($master_row_keys as $row_key) {
			$hostid = $rows_meta[$row_key]['hostid'];
			$group = $rows_meta[$row_key]['group'];

			$row = [];

			foreach ($columns as $column_index => $column) {
				switch ($column['data']) {
					case CWidgetFieldColumnsList::DATA_HOST_NAME:
						$data = [
							'value' => $hosts[$hostid]['name'],
							'hostid' => $hostid,
							'maintenance_status' => $hosts[$hostid]['maintenance_status']
						];

						if ($data['maintenance_status'] == HOST_MAINTENANCE_STATUS_ON) {
							$data['maintenance_type'] = $hosts[$hostid]['maintenance_type'];

							if (array_key_exists($hosts[$hostid]['maintenanceid'], $db_maintenances)) {
								$maintenance = $db_maintenances[$hosts[$hostid]['maintenanceid']];

								$data['maintenance_name'] = $maintenance['name'];
								$data['maintenance_description'] = $maintenance['description'];
							}
							else {
								$data['maintenance_name'] = _('Inaccessible maintenance');
								$data['maintenance_description'] = '';
							}
						}

						$row[] = $data;

						break;

					case CWidgetFieldColumnsList::DATA_GROUP_NAME:
						$row[] = ['value' => $group];

						break;

					case CWidgetFieldColumnsList::DATA_TEXT:
						$row[] = ['value' => $text_columns[$column_index][$hostid]];

						break;

					case CWidgetFieldColumnsList::DATA_ITEM_VALUE:
						$row[] = array_key_exists($row_key, $item_values[$column_index])
							? $item_values[$column_index][$row_key]
							: null;

						break;
				}
			}

			$rows[] = [
				'columns' => $row,
				'context' => $merge_hosts ? ['group' => $group] : ['hostid' => $hostid, 'group' => $group]
			];
		}

		return [
			'configuration' => $columns,
			'show_thumbnail' => $show_thumbnail,
			'rows' => $rows
		];
	}

	/**
	 * Widget-level "Group by" configuration, in the shape GroupKeyResolver::resolve() expects.
	 */
	private function getGroupByConfig(): array {
		// groupby_match_field is stored/validated as GroupKeyResolver::MATCH_FIELD_* (an integer - see
		// GroupKeyResolver's class constants for why), translated here to the FIELD_NAME/FIELD_KEY string
		// GroupKeyResolver::resolve() actually indexes $item with.
		$match_field = (int) $this->fields_values['groupby_match_field'] == GroupKeyResolver::MATCH_FIELD_KEY
			? GroupKeyResolver::FIELD_KEY
			: GroupKeyResolver::FIELD_NAME;

		return [
			'mode' => (int) $this->fields_values['groupby_mode'],
			'pattern' => $this->fields_values['groupby_pattern'],
			'capture' => (int) $this->fields_values['groupby_capture'],
			'key_param' => (int) $this->fields_values['groupby_key_param'],
			'tag' => $this->fields_values['groupby_tag'],
			'match_field' => $match_field
		];
	}

	/**
	 * Check if column configuration requires selecting numeric items only.
	 *
	 * @param array $column  Column configuration.
	 *
	 * @return bool
	 */
	private static function isNumericOnlyColumn(array $column): bool {
		if ($column['display'] == CWidgetFieldColumnsList::DISPLAY_AS_IS) {
			return CAggFunctionData::requiresNumericItem($column['aggregate_function']);
		}

		return $column['aggregate_function'] != AGGREGATE_COUNT;
	}

	/**
	 * Fetch the items a single column's item-name pattern matches. Unlike Top hosts' exact-match getItems()
	 * (REPORT.md Q3/Q4), this is a wildcard search: pattern-based item selection makes "several items match" the
	 * normal case, not a rare edge case, which is why resolveItemsToCells()'s tie-break (not a silent per-host
	 * dedupe) is what turns this into cells.
	 */
	private static function getItemsByPattern(string $pattern, bool $numeric_only, ?array $groupids,
			?array $hostids, string $name_field, bool $select_tags): array {
		return API::Item()->get([
			'output' => ['itemid', 'hostid', 'key_', 'name', 'name_resolved', 'history', 'trends', 'value_type',
				'units', 'lastclock'
			],
			'selectValueMap' => ['mappings'],
			'selectTags' => $select_tags ? ['tag', 'value'] : null,
			'groupids' => $groupids,
			'hostids' => $hostids,
			'monitored' => true,
			'webitems' => true,
			'searchWildcardsEnabled' => true,
			'searchByAny' => true,
			'search' => [$name_field => $pattern],
			'filter' => [
				'status' => ITEM_STATUS_ACTIVE,
				'value_type' => $numeric_only ? [ITEM_VALUE_TYPE_FLOAT, ITEM_VALUE_TYPE_UINT64] : null
			],
			// Candidate-item safety cap (TASKS.md §9): a broad pattern must not turn into an unbounded fetch.
			// Surfacing an actionable "results truncated" message is Phase 6 work (TASKS.md Phase 6 checklist);
			// for now the cap silently bounds the query instead of letting it run away.
			'limit' => CSettingsHelper::get(CSettingsHelper::SEARCH_LIMIT),
			'preservekeys' => true
		]);
	}

	/**
	 * Discover candidate items when no single column pattern can drive row discovery - only needed when the
	 * master column is Group name or Text (REPORT.md's getData() switch). Query shape depends on the group-by
	 * mode: modes with a name/key pattern search on it; key-parameter and regex modes cannot be pushed into SQL
	 * (TASKS.md §9) and fall back to a capped, unfiltered fetch resolved in PHP.
	 */
	private static function getGroupDiscoveryItems(array $groupby_config, ?array $groupids, ?array $hostids,
			string $name_field, bool $select_tags): array {
		$options = [
			'output' => ['itemid', 'hostid', 'key_', 'name', 'name_resolved', 'lastclock'],
			'selectTags' => $select_tags ? ['tag', 'value'] : null,
			'groupids' => $groupids,
			'hostids' => $hostids,
			'monitored' => true,
			'webitems' => true,
			'filter' => ['status' => ITEM_STATUS_ACTIVE],
			'limit' => CSettingsHelper::get(CSettingsHelper::SEARCH_LIMIT),
			'preservekeys' => true
		];

		switch ($groupby_config['mode']) {
			case GroupKeyResolver::MODE_ITEM_NAME_PATTERN:
				$options['search'] = [$name_field => $groupby_config['pattern']];
				$options['searchWildcardsEnabled'] = true;

				break;

			case GroupKeyResolver::MODE_ITEM_KEY_PATTERN:
				$options['search'] = ['key_' => $groupby_config['pattern']];
				$options['searchWildcardsEnabled'] = true;

				break;

			case GroupKeyResolver::MODE_ITEM_TAG:
				$options['tags'] = [['tag' => $groupby_config['tag']]];
				$options['selectTags'] = ['tag', 'value'];

				break;

			// MODE_ITEM_KEY_PARAMETER and MODE_REGEX have no server-side-filterable shape: every candidate item
			// must be fetched (capped) and resolved in PHP.
		}

		return API::Item()->get($options);
	}

	/**
	 * Return sparkline graph item values, applies data function SVG_GRAPH_MISSING_DATA_NONE on points for each item.
	 *
	 * @param array $items   Items required to get sparkline data for.
	 * @param array $column  Column configuration with sparkline configuration data.
	 *
	 * @return array itemid as key, sparkline data array of arrays as value, itemid with no data will be not present.
	 */
	private static function getItemSparklineValues(array $items, array $column): array {
		$result = [];
		$sparkline = $column['sparkline'];
		$items_by_valuetype = self::addDataSource($items, $sparkline['time_period']['from_ts'],
			['history' => $sparkline['history']] + $column
		);
		$items = array_key_exists(ITEM_VALUE_TYPE_FLOAT, $items_by_valuetype)
			? $items_by_valuetype[ITEM_VALUE_TYPE_FLOAT]
			: [];

		if (array_key_exists(ITEM_VALUE_TYPE_UINT64, $items_by_valuetype)) {
			$items = array_merge($items, $items_by_valuetype[ITEM_VALUE_TYPE_UINT64]);
		}

		if (!$items) {
			return $result;
		}

		$itemids_rows = Manager::History()->getGraphAggregationByWidth($items, $sparkline['time_period']['from_ts'],
			$sparkline['time_period']['to_ts'], $column['contents_width']
		);

		foreach ($itemids_rows as $itemid => $rows) {
			if (!$rows['data']) {
				continue;
			}

			$result[$itemid] = [];
			$points = array_column($rows['data'], 'avg', 'clock');
			/**
			 * Postgres may return entries in mixed 'clock' order, getMissingData for calculations
			 * requires order by 'clock'.
			 */
			ksort($points);
			$points += CSvgGraph::getMissingData($points, SVG_GRAPH_MISSING_DATA_NONE);
			ksort($points);

			foreach ($points as $ts => $value) {
				$result[$itemid][] = [$ts, $value];
			}
		}

		return $result;
	}

	private static function getItemValues(array $items, array $column): array {
		static $history_period_s;

		if ($history_period_s === null && in_array($column['display'], [CWidgetFieldColumnsList::DISPLAY_AS_IS,
				CWidgetFieldColumnsList::DISPLAY_BAR, CWidgetFieldColumnsList::DISPLAY_INDICATORS])) {
			$history_period_s = timeUnitToSeconds(CSettingsHelper::get(CSettingsHelper::HISTORY_PERIOD));
		}

		if ($column['aggregate_function'] != AGGREGATE_NONE) {
			$time_from = $column['time_period']['from_ts'];
		}
		else {
			$time_from = $column['display'] == CWidgetFieldColumnsList::DISPLAY_SPARKLINE
				? $column['sparkline']['time_period']['from_ts']
				: time() - $history_period_s;
		}

		$items_by_value_type = self::addDataSource($items, $time_from, $column);

		$result = [];

		if ($column['aggregate_function'] != AGGREGATE_NONE) {
			foreach ($items_by_value_type as $value_type => $items) {
				if ($value_type == ITEM_VALUE_TYPE_BINARY) {
					$output = $column['display_value_as'] == CWidgetFieldColumnsList::DISPLAY_VALUE_AS_BINARY
						? ['itemid', 'clock', 'ns']
						: ['itemid', 'value'];

					foreach (array_keys($items) as $itemid) {
						switch ($column['aggregate_function']) {
							case AGGREGATE_LAST:
							case AGGREGATE_FIRST:
								$db_values = API::History()->get([
									'output' => $output,
									'history' => ITEM_VALUE_TYPE_BINARY,
									'itemids' => $itemid,
									'time_from' => $column['time_period']['from_ts'],
									'time_till' => $column['time_period']['to_ts'],
									'sortfield' => ['clock', 'ns'],
									'sortorder' => $column['aggregate_function'] == AGGREGATE_LAST
										? ZBX_SORT_DOWN
										: ZBX_SORT_UP,
									'limit' => 1
								]);

								if ($db_values) {
									$result[$db_values[0]['itemid']] =
										$column['display_value_as'] == CWidgetFieldColumnsList::DISPLAY_VALUE_AS_BINARY
											? [
												'clock' => $db_values[0]['clock'],
												'ns' => $db_values[0]['ns']
											]
											: $db_values[0]['value'];
								}

								break;

							case AGGREGATE_COUNT:
								$db_values = API::History()->get([
									'output' => ['itemid'],
									'history' => ITEM_VALUE_TYPE_BINARY,
									'itemids' => $itemid,
									'time_from' => $column['time_period']['from_ts'],
									'time_till' => $column['time_period']['to_ts']
								]);

								if ($db_values) {
									$result[$db_values[0]['itemid']] = count($db_values);
								}

								break;
						}
					}
				}
				else {
					$values = Manager::History()->getAggregatedValues($items, $column['aggregate_function'], $time_from,
						$column['time_period']['to_ts']
					);

					$result += array_column($values, 'value', 'itemid');
				}
			}
		}
		else {
			$items_by_source = ['history' => [], 'trends' => []];

			foreach ($items_by_value_type as $value_type => $items) {
				if ($value_type == ITEM_VALUE_TYPE_BINARY) {
					$output = $column['display_value_as'] == CWidgetFieldColumnsList::DISPLAY_VALUE_AS_BINARY
						? ['itemid', 'clock', 'ns']
						: ['itemid', 'value'];

					foreach (array_keys($items) as $itemid) {
						$db_values = API::History()->get([
							'output' => $output,
							'history' => ITEM_VALUE_TYPE_BINARY,
							'itemids' => $itemid,
							'sortfield' => ['clock', 'ns'],
							'sortorder' => ZBX_SORT_DOWN,
							'limit' => 1
						]);

						if ($db_values) {
							$result[$db_values[0]['itemid']] =
								$column['display_value_as'] == CWidgetFieldColumnsList::DISPLAY_VALUE_AS_BINARY
									? [
										'clock' => $db_values[0]['clock'],
										'ns' => $db_values[0]['ns']
									]
									: $db_values[0]['value'];
						}
					}
				}
				else {
					foreach ($items as $itemid => $item) {
						$items_by_source[$item['source']][$itemid] = $item;
					}

					if ($items_by_source['history']) {
						$values = Manager::History()->getLastValues($items_by_source['history'], 1, $history_period_s);

						$result += array_column(array_column($values, 0), 'value', 'itemid');
					}

					if ($items_by_source['trends']) {
						$values = Manager::History()->getAggregatedValues($items_by_source['trends'], AGGREGATE_LAST,
							$time_from
						);

						$result += array_column($values, 'value', 'itemid');
					}
				}
			}
		}

		return $result;
	}

	private static function addDataSource(array $items, int $time, array $column): array {
		$items_by_history_type = [];

		if ($column['history'] == CWidgetFieldColumnsList::HISTORY_DATA_AUTO) {
			$items = CItemHelper::addDataSource($items, $time);
		}
		else {
			foreach ($items as &$item) {
				$item['source'] = $column['history'] == CWidgetFieldColumnsList::HISTORY_DATA_TRENDS
					? 'trends'
					: 'history';
			}
			unset($item);
		}

		foreach ($items as &$item) {
			if (!in_array($item['value_type'], [ITEM_VALUE_TYPE_FLOAT, ITEM_VALUE_TYPE_UINT64])) {
				$item['source'] = 'history';
			}

			$items_by_history_type[$item['value_type']][$item['itemid']] = $item;
		}
		unset($item);

		return $items_by_history_type;
	}
}
