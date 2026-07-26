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


use PHPUnit\Framework\TestCase;
use Modules\TopItemGroups\Includes\CellResolver;
use Modules\TopItemGroups\Includes\GroupKeyResolver;

class CellResolverTest extends TestCase {

	private const WILDCARD_CONFIG = [
		'mode' => GroupKeyResolver::MODE_ITEM_NAME_PATTERN,
		'pattern' => 'IIS [*] Requests per second',
		'capture' => 1
	];

	// ---------------------------------------------------------------------------------------------------------
	// makeRowKey(): composite by default, collapses to group-only when merging, never collides distinct pairs.
	// ---------------------------------------------------------------------------------------------------------

	public function testMakeRowKeyDiffersByHostWhenNotMerged(): void {
		$key_a = CellResolver::makeRowKey('101', 'Default Web Site', false);
		$key_b = CellResolver::makeRowKey('102', 'Default Web Site', false);

		$this->assertNotSame($key_a, $key_b);
	}

	public function testMakeRowKeySameAcrossHostsWhenMerged(): void {
		$key_a = CellResolver::makeRowKey('101', 'Default Web Site', true);
		$key_b = CellResolver::makeRowKey('102', 'Default Web Site', true);

		$this->assertSame($key_a, $key_b);
	}

	public function testMakeRowKeyNoAmbiguousConcatenationCollision(): void {
		// A naive "$hostid/$group" concatenation would collide these two distinct pairs.
		$key_a = CellResolver::makeRowKey('12', '3/x', false);
		$key_b = CellResolver::makeRowKey('123', 'x', false);

		$this->assertNotSame($key_a, $key_b);
	}

	// ---------------------------------------------------------------------------------------------------------
	// resolveItemsToCells(): grouping, tie-break, exclusion, rows_meta population.
	// ---------------------------------------------------------------------------------------------------------

	public function testSingleItemPerRowIsKeptAsIs(): void {
		$items = [
			'100' => ['itemid' => '100', 'hostid' => '1', 'lastclock' => 1000,
				'name' => 'IIS [Default Web Site] Requests per second']
		];
		$rows_meta = [];

		$cells = CellResolver::resolveItemsToCells($items, self::WILDCARD_CONFIG, false, 'name', $rows_meta);

		$row_key = CellResolver::makeRowKey('1', 'Default Web Site', false);

		$this->assertArrayHasKey($row_key, $cells);
		$this->assertSame('100', $cells[$row_key]['itemid']);
		$this->assertSame(['hostid' => '1', 'group' => 'Default Web Site'], $rows_meta[$row_key]);
	}

	public function testNonMatchingItemIsExcludedNotBucketed(): void {
		$items = [
			'100' => ['itemid' => '100', 'hostid' => '1', 'lastclock' => 1000, 'name' => 'CPU load']
		];
		$rows_meta = [];

		$cells = CellResolver::resolveItemsToCells($items, self::WILDCARD_CONFIG, false, 'name', $rows_meta);

		$this->assertSame([], $cells);
		$this->assertSame([], $rows_meta);
	}

	public function testTieBreakPrefersMostRecentLastclock(): void {
		$items = [
			'100' => ['itemid' => '100', 'hostid' => '1', 'lastclock' => 1000,
				'name' => 'IIS [Default Web Site] Requests per second'],
			'200' => ['itemid' => '200', 'hostid' => '1', 'lastclock' => 5000,
				'name' => 'IIS [Default Web Site] Requests per second']
		];
		$rows_meta = [];

		$cells = CellResolver::resolveItemsToCells($items, self::WILDCARD_CONFIG, false, 'name', $rows_meta);

		$row_key = CellResolver::makeRowKey('1', 'Default Web Site', false);
		$this->assertSame('200', $cells[$row_key]['itemid']);
	}

	public function testTieBreakFallsBackToLowestItemidWhenClocksEqual(): void {
		$items = [
			'300' => ['itemid' => '300', 'hostid' => '1', 'lastclock' => 5000,
				'name' => 'IIS [Default Web Site] Requests per second'],
			'150' => ['itemid' => '150', 'hostid' => '1', 'lastclock' => 5000,
				'name' => 'IIS [Default Web Site] Requests per second']
		];
		$rows_meta = [];

		$cells = CellResolver::resolveItemsToCells($items, self::WILDCARD_CONFIG, false, 'name', $rows_meta);

		$row_key = CellResolver::makeRowKey('1', 'Default Web Site', false);
		$this->assertSame('150', $cells[$row_key]['itemid']);
	}

	public function testTieBreakFallsBackToLowestItemidWhenNoClockAtAll(): void {
		$items = [
			'300' => ['itemid' => '300', 'hostid' => '1', 'lastclock' => 0,
				'name' => 'IIS [Default Web Site] Requests per second'],
			'150' => ['itemid' => '150', 'hostid' => '1', 'lastclock' => 0,
				'name' => 'IIS [Default Web Site] Requests per second']
		];
		$rows_meta = [];

		$cells = CellResolver::resolveItemsToCells($items, self::WILDCARD_CONFIG, false, 'name', $rows_meta);

		$row_key = CellResolver::makeRowKey('1', 'Default Web Site', false);
		$this->assertSame('150', $cells[$row_key]['itemid']);
	}

	public function testTieBreakIsStableAcrossInputOrder(): void {
		$item_a = ['itemid' => '150', 'hostid' => '1', 'lastclock' => 5000,
			'name' => 'IIS [Default Web Site] Requests per second'];
		$item_b = ['itemid' => '300', 'hostid' => '1', 'lastclock' => 5000,
			'name' => 'IIS [Default Web Site] Requests per second'];

		$rows_meta_1 = [];
		$cells_1 = CellResolver::resolveItemsToCells(['150' => $item_a, '300' => $item_b], self::WILDCARD_CONFIG,
			false, 'name', $rows_meta_1
		);

		$rows_meta_2 = [];
		$cells_2 = CellResolver::resolveItemsToCells(['300' => $item_b, '150' => $item_a], self::WILDCARD_CONFIG,
			false, 'name', $rows_meta_2
		);

		$row_key = CellResolver::makeRowKey('1', 'Default Web Site', false);
		$this->assertSame($cells_1[$row_key]['itemid'], $cells_2[$row_key]['itemid']);
	}

	public function testDifferentHostsProduceSeparateRowsWhenNotMerged(): void {
		$items = [
			'100' => ['itemid' => '100', 'hostid' => '1', 'lastclock' => 1000,
				'name' => 'IIS [Default Web Site] Requests per second'],
			'200' => ['itemid' => '200', 'hostid' => '2', 'lastclock' => 1000,
				'name' => 'IIS [Default Web Site] Requests per second']
		];
		$rows_meta = [];

		$cells = CellResolver::resolveItemsToCells($items, self::WILDCARD_CONFIG, false, 'name', $rows_meta);

		$this->assertCount(2, $cells);
		$this->assertCount(2, $rows_meta);
	}

	public function testDifferentHostsMergeIntoOneRowWhenMerging(): void {
		$items = [
			'100' => ['itemid' => '100', 'hostid' => '2', 'lastclock' => 9000,
				'name' => 'IIS [Default Web Site] Requests per second'],
			'200' => ['itemid' => '200', 'hostid' => '1', 'lastclock' => 1000,
				'name' => 'IIS [Default Web Site] Requests per second']
		];
		$rows_meta = [];

		$cells = CellResolver::resolveItemsToCells($items, self::WILDCARD_CONFIG, true, 'name', $rows_meta);

		$this->assertCount(1, $cells);
		$this->assertCount(1, $rows_meta);

		$row_key = CellResolver::makeRowKey('anything', 'Default Web Site', true);
		// Most recent value wins the cell (host 2's item), but the lowest hostid is kept as the representative.
		$this->assertSame('100', $cells[$row_key]['itemid']);
		$this->assertSame('1', $rows_meta[$row_key]['hostid']);
	}

	public function testDifferentGroupsOnSameHostProduceSeparateRows(): void {
		$items = [
			'100' => ['itemid' => '100', 'hostid' => '1', 'lastclock' => 1000,
				'name' => 'IIS [Default Web Site] Requests per second'],
			'200' => ['itemid' => '200', 'hostid' => '1', 'lastclock' => 1000,
				'name' => 'IIS [Intranet] Requests per second']
		];
		$rows_meta = [];

		$cells = CellResolver::resolveItemsToCells($items, self::WILDCARD_CONFIG, false, 'name', $rows_meta);

		$this->assertCount(2, $cells);
		$this->assertSame(['Default Web Site', 'Intranet'],
			array_values(array_unique(array_column($rows_meta, 'group')))
		);
	}

	public function testRowsMetaAccumulatesAcrossMultipleCalls(): void {
		// Mirrors how WidgetView calls this once per column, feeding the same $rows_meta through each time.
		$rows_meta = [];

		CellResolver::resolveItemsToCells(
			['100' => ['itemid' => '100', 'hostid' => '1', 'lastclock' => 1000,
				'name' => 'IIS [Default Web Site] Requests per second']],
			self::WILDCARD_CONFIG, false, 'name', $rows_meta
		);

		CellResolver::resolveItemsToCells(
			['200' => ['itemid' => '200', 'hostid' => '2', 'lastclock' => 1000,
				'name' => 'IIS [Intranet] Requests per second']],
			self::WILDCARD_CONFIG, false, 'name', $rows_meta
		);

		$this->assertCount(2, $rows_meta);
	}

	public function testTemplateDashboardUsesNameFieldNotNameResolved(): void {
		$items = [
			'100' => ['itemid' => '100', 'hostid' => '1', 'lastclock' => 1000,
				'name' => 'IIS [Default Web Site] Requests per second', 'name_resolved' => 'CPU load']
		];
		$rows_meta = [];

		$cells = CellResolver::resolveItemsToCells($items, self::WILDCARD_CONFIG, false, 'name', $rows_meta);

		$row_key = CellResolver::makeRowKey('1', 'Default Web Site', false);
		$this->assertArrayHasKey($row_key, $cells);
	}

	// ---------------------------------------------------------------------------------------------------------
	// toResolverItem()
	// ---------------------------------------------------------------------------------------------------------

	public function testToResolverItemPicksConfiguredNameField(): void {
		$item = ['name' => 'template name', 'name_resolved' => 'resolved name', 'key_' => 'a.b', 'tags' => []];

		$this->assertSame('resolved name', CellResolver::toResolverItem($item, 'name_resolved')['name']);
		$this->assertSame('template name', CellResolver::toResolverItem($item, 'name')['name']);
	}

	public function testToResolverItemDefaultsMissingFieldsSafely(): void {
		$resolved = CellResolver::toResolverItem([], 'name_resolved');

		$this->assertSame('', $resolved['name']);
		$this->assertSame('', $resolved['key_']);
		$this->assertSame([], $resolved['tags']);
	}
}
