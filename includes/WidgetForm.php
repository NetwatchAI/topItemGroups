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

use Modules\TopItemGroups\Widget;

use Zabbix\Widgets\{
	CWidgetField,
	CWidgetForm
};

use Zabbix\Widgets\Fields\{
	CWidgetFieldCheckBox,
	CWidgetFieldIntegerBox,
	CWidgetFieldMultiSelectGroup,
	CWidgetFieldMultiSelectHost,
	CWidgetFieldMultiSelectOverrideHost,
	CWidgetFieldRadioButtonList,
	CWidgetFieldSelect,
	CWidgetFieldTags,
	CWidgetFieldTextBox
};

/**
 * Top item groups data widget form.
 */
class WidgetForm extends CWidgetForm {

	private const DEFAULT_ORDER_COLUMN = 0;

	// NOTE: the "Group filter" field from TASKS.md §8 (include/exclude pattern applied to resolved group keys,
	// e.g. to drop system entities like tempdb or lo without touching the group pattern) is not implemented yet -
	// remaining Phase 4 work.

	private array $field_column_values = [];

	protected function normalizeValues(array $values): array {
		$values = parent::normalizeValues($values);

		if (array_key_exists('columnsthresholds', $values)) {
			foreach ($values['columnsthresholds'] as $column_index => $fields) {
				$values['columns'][$column_index]['thresholds'] = [];

				foreach ($fields as $field_key => $field_values) {
					foreach ($field_values as $value_index => $value) {
						$values['columns'][$column_index]['thresholds'][$value_index][$field_key] = $value;
					}
				}
			}
		}

		// Apply sortable changes to data.
		if (array_key_exists('sortorder', $values)) {
			if (array_key_exists('column', $values) && array_key_exists('columns', $values['sortorder'])) {
				// Fix selected column index when columns were sorted.
				$values['column'] = array_search($values['column'], $values['sortorder']['columns'], true);
			}

			foreach ($values['sortorder'] as $key => $sortorder) {
				if (!array_key_exists($key, $values)) {
					continue;
				}

				$sorted = [];

				foreach ($sortorder as $index) {
					$sorted[] = $values[$key][$index];
				}

				$values[$key] = $sorted;
			}
		}

		if (array_key_exists('columns', $values)) {
			foreach ($values['columns'] as $key => $value) {
				$value['name'] = trim($value['name']);

				switch ($value['data']) {
					case CWidgetFieldColumnsList::DATA_ITEM_VALUE:
						$this->field_column_values[$key] = $value['name'] === '' ? $value['item'] : $value['name'];

						if (array_key_exists('display', $value)
								&& $value['display'] == CWidgetFieldColumnsList::DISPLAY_SPARKLINE) {
							$values['columns'][$key]['sparkline'] = array_key_exists('sparkline', $value)
								? array_replace(CWidgetFieldColumnsList::SPARKLINE_DEFAULT, $value['sparkline'])
								: CWidgetFieldColumnsList::SPARKLINE_DEFAULT;
						}
						break;

					case CWidgetFieldColumnsList::DATA_HOST_NAME:
						$this->field_column_values[$key] = $value['name'] === '' ? _('Host name') : $value['name'];
						break;

					case CWidgetFieldColumnsList::DATA_GROUP_NAME:
						$this->field_column_values[$key] = $value['name'] === '' ? _('Group name') : $value['name'];
						break;

					case CWidgetFieldColumnsList::DATA_TEXT:
						$this->field_column_values[$key] = $value['name'] === '' ? $value['text'] : $value['name'];
						break;
				}
			}
		}

		return $values;
	}

	public function addFields(): self {
		return $this
			->addField($this->isTemplateDashboard()
				? null
				: new CWidgetFieldMultiSelectGroup('groupids', _('Host groups'))
			)
			->addField($this->isTemplateDashboard()
				? null
				: new CWidgetFieldMultiSelectHost('hostids', _('Hosts'))
			)
			->addField($this->isTemplateDashboard()
				? null
				: (new CWidgetFieldRadioButtonList('evaltype', _('Host tags'), [
					TAG_EVAL_TYPE_AND_OR => _('And/Or'),
					TAG_EVAL_TYPE_OR => _('Or')
				]))->setDefault(TAG_EVAL_TYPE_AND_OR)
			)
			->addField($this->isTemplateDashboard()
				? null
				: new CWidgetFieldTags('tags')
			)
			->addField(
				new CWidgetFieldCheckBox('maintenance',
					$this->isTemplateDashboard() ? _('Show data in maintenance') : _('Show hosts in maintenance')
				)
			)
			->addField(
				(new CWidgetFieldSelect('groupby_mode', _('Group by'), [
					GroupKeyResolver::MODE_ITEM_NAME_PATTERN => _('Item name pattern'),
					GroupKeyResolver::MODE_ITEM_KEY_PATTERN => _('Item key pattern'),
					GroupKeyResolver::MODE_ITEM_KEY_PARAMETER => _('Item key parameter'),
					GroupKeyResolver::MODE_ITEM_TAG => _('Item tag'),
					GroupKeyResolver::MODE_REGEX => _('Regular expression')
				]))
					->setDefault(GroupKeyResolver::MODE_ITEM_NAME_PATTERN)
					->setFlags(CWidgetField::FLAG_LABEL_ASTERISK)
			)
			->addField(
				new CWidgetFieldTextBox('groupby_pattern', _('Group pattern'))
			)
			->addField(
				(new CWidgetFieldIntegerBox('groupby_capture', _('Capture'), 1, 20))
					->setDefault(1)
			)
			->addField(
				(new CWidgetFieldIntegerBox('groupby_key_param', _('Parameter'), 1, 20))
					->setDefault(1)
			)
			->addField(
				new CWidgetFieldTextBox('groupby_tag', _('Tag name'))
			)
			->addField(
				(new CWidgetFieldRadioButtonList('groupby_match_field', _('Match against'), [
					GroupKeyResolver::MATCH_FIELD_NAME => _('Item name'),
					GroupKeyResolver::MATCH_FIELD_KEY => _('Item key')
				]))->setDefault(GroupKeyResolver::MATCH_FIELD_NAME)
			)
			->addField(
				(new CWidgetFieldCheckBox('merge_hosts', _('Merge groups across hosts')))->setDefault(0)
			)
			->addField(
				(new CWidgetFieldColumnsList('columns', _('Columns')))
					->setFlags(CWidgetField::FLAG_NOT_EMPTY | CWidgetField::FLAG_LABEL_ASTERISK)
			)
			->addField(
				(new CWidgetFieldSelect('column', _('Order by'), $this->field_column_values))
					->setDefault($this->field_column_values
						? self::DEFAULT_ORDER_COLUMN
						: CWidgetFieldSelect::DEFAULT_VALUE
					)
					->setFlags(CWidgetField::FLAG_LABEL_ASTERISK)
			)
			->addField(
				(new CWidgetFieldRadioButtonList('order', _('Order'), [
					Widget::ORDER_TOP_N => _('Top N'),
					Widget::ORDER_BOTTOM_N => _('Bottom N')
				]))->setDefault(Widget::ORDER_TOP_N)
			)
			->addField($this->isTemplateDashboard()
				? null
				: (new CWidgetFieldIntegerBox('show_lines', _('Row limit'), ZBX_MIN_WIDGET_LINES,
					ZBX_MAX_WIDGET_LINES
				))
					->setDefault(10)
					->setFlags(CWidgetField::FLAG_LABEL_ASTERISK)
			)
			->addField(
				new CWidgetFieldMultiSelectOverrideHost()
			);
	}

	public function validate(bool $strict = false): array {
		$mode = (int) $this->getField('groupby_mode')->getValue();

		$pattern_modes = [
			GroupKeyResolver::MODE_ITEM_NAME_PATTERN, GroupKeyResolver::MODE_ITEM_KEY_PATTERN,
			GroupKeyResolver::MODE_REGEX
		];

		if (in_array($mode, $pattern_modes, true)) {
			$this->getField('groupby_pattern')->setFlags(CWidgetField::FLAG_NOT_EMPTY);
		}

		if ($mode == GroupKeyResolver::MODE_ITEM_TAG) {
			$this->getField('groupby_tag')->setFlags(CWidgetField::FLAG_NOT_EMPTY);
		}

		$errors = parent::validate($strict);

		if ($errors) {
			return $errors;
		}

		// Regex compile errors and out-of-range capture indexes must surface as form errors, never a fatal
		// (TASKS.md §8/§7.1) - GroupKeyResolver::resolve() already fails soft at runtime, but catching this at
		// save time is far more useful to whoever is configuring the widget.
		$pattern = $this->getField('groupby_pattern')->getValue();

		if ($mode == GroupKeyResolver::MODE_REGEX) {
			if ($pattern !== '' && !GroupKeyResolver::isValidRegex($pattern)) {
				$errors[] = _s('Invalid parameter "%1$s": %2$s.', _('Group pattern'),
					_('invalid regular expression')
				);
			}
		}
		elseif (in_array($mode, [GroupKeyResolver::MODE_ITEM_NAME_PATTERN, GroupKeyResolver::MODE_ITEM_KEY_PATTERN],
				true) && $pattern !== '') {
			$capture = (int) $this->getField('groupby_capture')->getValue();
			$wildcard_count = GroupKeyResolver::countWildcards($pattern);

			if ($wildcard_count == 0) {
				$errors[] = _s('Invalid parameter "%1$s": %2$s.', _('Group pattern'),
					_('must contain at least one "*" wildcard')
				);
			}
			elseif ($capture < 1 || $capture > $wildcard_count) {
				$errors[] = _s('Invalid parameter "%1$s": %2$s.', _('Capture'),
					_s('must be between 1 and %1$s for the given pattern', $wildcard_count)
				);
			}
		}

		return $errors;
	}
}
