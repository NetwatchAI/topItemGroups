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


/**
 * Top item groups widget form view.
 *
 * @var CView $this
 * @var array $data
 */

use Modules\TopItemGroups\Includes\CWidgetFieldColumnsListView;

$form = new CWidgetFormView($data);

$groupids = array_key_exists('groupids', $data['fields'])
	? new CWidgetFieldMultiSelectGroupView($data['fields']['groupids'])
	: null;

$column = new CWidgetFieldSelectView($data['fields']['column']);

if ($data['fields']['column']->getValues()) {
	$form->registerField($column);
	$column_view = $column->getView();
}
else {
	$column_view = _('Add a column');
}

$form
	->addField($groupids)
	->addField(array_key_exists('hostids', $data['fields'])
		? (new CWidgetFieldMultiSelectHostView($data['fields']['hostids']))
			->setFilterPreselect([
				'id' => $groupids->getId(),
				'accept' => CMultiSelect::FILTER_PRESELECT_ACCEPT_ID,
				'submit_as' => 'groupid'
			])
		: null
	)
	->addField(array_key_exists('evaltype', $data['fields'])
		? new CWidgetFieldRadioButtonListView($data['fields']['evaltype'])
		: null
	)
	->addField(array_key_exists('tags', $data['fields'])
		? new CWidgetFieldTagsView($data['fields']['tags'])
		: null
	)
	->addField(
		new CWidgetFieldCheckBoxView($data['fields']['maintenance'])
	)
	->addField(
		new CWidgetFieldSelectView($data['fields']['groupby_mode'])
	)
	->addField(
		(new CWidgetFieldTextBoxView($data['fields']['groupby_pattern']))->addRowClass('js-groupby-pattern-row')
	)
	->addField(
		(new CWidgetFieldIntegerBoxView($data['fields']['groupby_capture']))->addRowClass('js-groupby-capture-row')
	)
	->addField(
		(new CWidgetFieldIntegerBoxView($data['fields']['groupby_key_param']))
			->addRowClass('js-groupby-key-param-row')
	)
	->addField(
		(new CWidgetFieldTextBoxView($data['fields']['groupby_tag']))->addRowClass('js-groupby-tag-row')
	)
	->addField(
		(new CWidgetFieldRadioButtonListView($data['fields']['groupby_match_field']))
			->addRowClass('js-groupby-match-field-row')
	)
	->addField(
		new CWidgetFieldCheckBoxView($data['fields']['merge_hosts'])
	)
	->addField(
		(new CWidgetFieldColumnsListView($data['fields']['columns']))->addClass(ZBX_STYLE_TABLE_FORMS_SEPARATOR)
	)
	->addItem([
		$column->getLabel(),
		(new CFormField($column_view))->addClass($column->isDisabled() ? ZBX_STYLE_DISABLED : null)
	])
	->addField(
		new CWidgetFieldRadioButtonListView($data['fields']['order'])
	)
	->addField(array_key_exists('show_lines', $data['fields'])
		? new CWidgetFieldIntegerBoxView($data['fields']['show_lines'])
		: null
	)
	->includeJsFile('widget.edit.js.php')
	->initFormJs('widget_form.init('.json_encode([
		'templateid' => $data['templateid']
	], JSON_THROW_ON_ERROR).');')
	->show();
