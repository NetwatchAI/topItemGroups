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
use Modules\TopItemGroups\Includes\GroupKeyResolver;

class GroupKeyResolverTest extends TestCase {

	// ---------------------------------------------------------------------------------------------------------
	// Item name pattern (wildcard) mode.
	// ---------------------------------------------------------------------------------------------------------

	public static function wildcardDataProvider(): array {
		return [
			'basic single wildcard, capture 1' => [
				'IIS [Default Web Site] Requests per second', 'IIS [*] Requests per second', 1, 'Default Web Site'
			],
			'multiple wildcards, capture the second' => [
				'IIS [Default Web Site] Requests per second', 'IIS [*] * per second', 2, 'Requests'
			],
			'multiple wildcards, capture the first' => [
				'IIS [Default Web Site] Requests per second', 'IIS [*] * per second', 1, 'Default Web Site'
			],
			'leading wildcard' => [
				'Mounted filesystem: /var/log', '*: *', 2, '/var/log'
			],
			'leading wildcard, capture the leading one' => [
				'Mounted filesystem: /var/log', '*: *', 1, 'Mounted filesystem'
			],
			'trailing wildcard' => [
				'Interface eth0(): Bits received', 'Interface *()*', 1, 'eth0'
			],
			// Two adjacent lazy wildcards with no literal between them is inherently ambiguous. The deterministic,
			// stable-across-refreshes result of two lazy quantifiers in sequence is: the leftmost stays empty and
			// the rightmost absorbs the whole free span, for as long as no later literal forces it to backtrack.
			'adjacent wildcards, second absorbs the free span' => [
				'abfoobar', 'a**bar', 2, 'bfoo'
			],
			'adjacent wildcards, first stays empty' => [
				'abfoobar', 'ab**bar', 1, ''
			],
			'value contains spaces' => [
				'db.database.size["My Database Name"]', 'db.database.size["*"]', 1, 'My Database Name'
			],
			'value contains brackets' => [
				'IIS [Site (prod)] Requests', 'IIS [*] Requests', 1, 'Site (prod)'
			],
			'value contains commas' => [
				'IIS [Site, prod] Requests', 'IIS [*] Requests', 1, 'Site, prod'
			],
			'value contains backslashes' => [
				'Mount C:\\data Requests', 'Mount * Requests', 1, 'C:\\data'
			],
			'unicode value' => [
				'IIS [Основной сайт] Requests', 'IIS [*] Requests', 1, 'Основной сайт'
			],
			'unicode value with emoji' => [
				'Queue [🚀-orders] Depth', 'Queue [*] Depth', 1, '🚀-orders'
			],
			'literal pattern characters are escaped, not treated as regex' => [
				'db.size[main]', 'db.size[*]', 1, 'main'
			]
		];
	}

	/** @dataProvider wildcardDataProvider */
	public function testItemNamePatternMatches(string $name, string $pattern, int $capture, string $expected): void {
		$item = ['name' => $name];
		$config = ['mode' => GroupKeyResolver::MODE_ITEM_NAME_PATTERN, 'pattern' => $pattern, 'capture' => $capture];

		if ($expected === '') {
			$this->assertNull(GroupKeyResolver::resolve($item, $config));
		}
		else {
			$this->assertSame($expected, GroupKeyResolver::resolve($item, $config));
		}
	}

	public static function wildcardNoMatchDataProvider(): array {
		return [
			'pattern does not match at all' => ['CPU load', 'IIS [*] Requests', 1],
			'pattern matches a prefix only (not anchored end)' => ['IIS [Site] Requests extra', 'IIS [*] Requests', 1],
			'pattern matches a suffix only (not anchored start)' => ['prefix IIS [Site] Requests', 'IIS [*] Requests', 1],
			'capture index has no corresponding wildcard' => ['IIS [Site] Requests', 'IIS [*] Requests', 2],
			'capture index is zero' => ['IIS [Site] Requests', 'IIS [*] Requests', 0],
			'pattern has no wildcards at all' => ['IIS Requests', 'IIS Requests', 1]
		];
	}

	/** @dataProvider wildcardNoMatchDataProvider */
	public function testItemNamePatternExcludesNonMatches(string $name, string $pattern, int $capture): void {
		$item = ['name' => $name];
		$config = ['mode' => GroupKeyResolver::MODE_ITEM_NAME_PATTERN, 'pattern' => $pattern, 'capture' => $capture];

		$this->assertNull(GroupKeyResolver::resolve($item, $config));
	}

	public function testItemKeyPatternMatchesAgainstKeyNotName(): void {
		$item = ['name' => 'Free disk space', 'key_' => 'vfs.fs.size[/var/log,free]'];
		$config = ['mode' => GroupKeyResolver::MODE_ITEM_KEY_PATTERN, 'pattern' => 'vfs.fs.size[*,free]', 'capture' => 1];

		$this->assertSame('/var/log', GroupKeyResolver::resolve($item, $config));
	}

	public function testCountWildcards(): void {
		$this->assertSame(0, GroupKeyResolver::countWildcards('no wildcards here'));
		$this->assertSame(1, GroupKeyResolver::countWildcards('IIS [*] Requests'));
		$this->assertSame(3, GroupKeyResolver::countWildcards('* * *'));
	}

	// ---------------------------------------------------------------------------------------------------------
	// Item key parameter mode (via CItemKey).
	// ---------------------------------------------------------------------------------------------------------

	public static function keyParameterDataProvider(): array {
		return [
			'simple unquoted parameter' => ['db.database.size[main]', 1, 'main'],
			'quoted parameter' => ['db.database.size["My Database"]', 1, 'My Database'],
			'quoted parameter with escaped quote' => ['db.database.size["Say \\"Hi\\""]', 1, 'Say "Hi"'],
			'second of several parameters' => ['vfs.fs.size[/var/log,free]', 2, 'free'],
			'first of several parameters' => ['vfs.fs.size[/var/log,free]', 1, '/var/log'],
			'nested bracket parameter, brackets preserved' => ['perf_counter[\\Memory(*)\\Available bytes]', 1,
				'\\Memory(*)\\Available bytes'
			],
			'parameter containing a comma, quoted' => ['custom.key["a, b", 2]', 1, 'a, b'],
			'unicode parameter' => ['custom.key[Основной]', 1, 'Основной']
		];
	}

	/** @dataProvider keyParameterDataProvider */
	public function testItemKeyParameterExtracts(string $key, int $param_index, string $expected): void {
		$item = ['key_' => $key];
		$config = ['mode' => GroupKeyResolver::MODE_ITEM_KEY_PARAMETER, 'key_param' => $param_index];

		$this->assertSame($expected, GroupKeyResolver::resolve($item, $config));
	}

	public function testItemKeyParameterEmptyParameterIsExcluded(): void {
		$item = ['key_' => 'custom.key[,second]'];
		$config = ['mode' => GroupKeyResolver::MODE_ITEM_KEY_PARAMETER, 'key_param' => 1];

		$this->assertNull(GroupKeyResolver::resolve($item, $config));
	}

	public function testItemKeyParameterMissingIndexIsExcluded(): void {
		$item = ['key_' => 'db.database.size[main]'];
		$config = ['mode' => GroupKeyResolver::MODE_ITEM_KEY_PARAMETER, 'key_param' => 5];

		$this->assertNull(GroupKeyResolver::resolve($item, $config));
	}

	public function testItemKeyParameterKeyWithNoParametersIsExcluded(): void {
		$item = ['key_' => 'system.cpu.load'];
		$config = ['mode' => GroupKeyResolver::MODE_ITEM_KEY_PARAMETER, 'key_param' => 1];

		$this->assertNull(GroupKeyResolver::resolve($item, $config));
	}

	// ---------------------------------------------------------------------------------------------------------
	// Item tag mode.
	// ---------------------------------------------------------------------------------------------------------

	public function testItemTagResolvesValue(): void {
		$item = ['tags' => [['tag' => 'site', 'value' => 'Default Web Site'], ['tag' => 'env', 'value' => 'prod']]];
		$config = ['mode' => GroupKeyResolver::MODE_ITEM_TAG, 'tag' => 'site'];

		$this->assertSame('Default Web Site', GroupKeyResolver::resolve($item, $config));
	}

	public function testItemTagAbsentTagIsExcluded(): void {
		$item = ['tags' => [['tag' => 'env', 'value' => 'prod']]];
		$config = ['mode' => GroupKeyResolver::MODE_ITEM_TAG, 'tag' => 'site'];

		$this->assertNull(GroupKeyResolver::resolve($item, $config));
	}

	public function testItemTagEmptyValueIsExcluded(): void {
		$item = ['tags' => [['tag' => 'site', 'value' => '']]];
		$config = ['mode' => GroupKeyResolver::MODE_ITEM_TAG, 'tag' => 'site'];

		$this->assertNull(GroupKeyResolver::resolve($item, $config));
	}

	public function testItemTagNoTagsAtAllIsExcluded(): void {
		$item = ['tags' => []];
		$config = ['mode' => GroupKeyResolver::MODE_ITEM_TAG, 'tag' => 'site'];

		$this->assertNull(GroupKeyResolver::resolve($item, $config));
	}

	public function testItemTagDuplicateTagUsesFirstOccurrence(): void {
		$item = ['tags' => [['tag' => 'site', 'value' => 'first'], ['tag' => 'site', 'value' => 'second']]];
		$config = ['mode' => GroupKeyResolver::MODE_ITEM_TAG, 'tag' => 'site'];

		$this->assertSame('first', GroupKeyResolver::resolve($item, $config));
	}

	// ---------------------------------------------------------------------------------------------------------
	// Regular expression mode.
	// ---------------------------------------------------------------------------------------------------------

	public function testRegexValidCaptureAgainstName(): void {
		$item = ['name' => 'IIS [Default Web Site] Requests per second'];
		$config = [
			'mode' => GroupKeyResolver::MODE_REGEX,
			'pattern' => 'IIS \[([^\]]+)\]',
			'capture' => 1,
			'match_field' => GroupKeyResolver::FIELD_NAME
		];

		$this->assertSame('Default Web Site', GroupKeyResolver::resolve($item, $config));
	}

	public function testRegexValidCaptureAgainstKey(): void {
		$item = ['key_' => 'vfs.fs.size[/var/log,free]'];
		$config = [
			'mode' => GroupKeyResolver::MODE_REGEX,
			'pattern' => 'vfs\.fs\.size\[([^,]+),',
			'capture' => 1,
			'match_field' => GroupKeyResolver::FIELD_KEY
		];

		$this->assertSame('/var/log', GroupKeyResolver::resolve($item, $config));
	}

	public function testRegexInvalidPatternIsExcludedNotFatal(): void {
		$item = ['name' => 'IIS [Default Web Site] Requests'];
		$config = [
			'mode' => GroupKeyResolver::MODE_REGEX,
			'pattern' => 'IIS \[([^\]]+',   // unbalanced group - invalid
			'capture' => 1,
			'match_field' => GroupKeyResolver::FIELD_NAME
		];

		$this->assertNull(GroupKeyResolver::resolve($item, $config));
	}

	public function testRegexCaptureIndexOutOfRangeIsExcluded(): void {
		$item = ['name' => 'IIS [Default Web Site] Requests'];
		$config = [
			'mode' => GroupKeyResolver::MODE_REGEX,
			'pattern' => 'IIS \[([^\]]+)\]',
			'capture' => 2,
			'match_field' => GroupKeyResolver::FIELD_NAME
		];

		$this->assertNull(GroupKeyResolver::resolve($item, $config));
	}

	public function testRegexNoMatchIsExcluded(): void {
		$item = ['name' => 'CPU load'];
		$config = [
			'mode' => GroupKeyResolver::MODE_REGEX,
			'pattern' => 'IIS \[([^\]]+)\]',
			'capture' => 1,
			'match_field' => GroupKeyResolver::FIELD_NAME
		];

		$this->assertNull(GroupKeyResolver::resolve($item, $config));
	}

	public function testRegexPatternContainingUnescapedSlashIsHandled(): void {
		$item = ['name' => 'Mounted filesystem: /var/log'];
		$config = [
			'mode' => GroupKeyResolver::MODE_REGEX,
			'pattern' => 'filesystem: (/[a-z/]+)',
			'capture' => 1,
			'match_field' => GroupKeyResolver::FIELD_NAME
		];

		$this->assertSame('/var/log', GroupKeyResolver::resolve($item, $config));
	}

	public static function isValidRegexDataProvider(): array {
		return [
			'valid pattern' => ['IIS \[([^\]]+)\]', true],
			'valid pattern with unescaped slash' => ['a/b(.*)', true],
			'unbalanced group' => ['IIS \[([^\]]+', false],
			'invalid quantifier' => ['*abc', false]
		];
	}

	/** @dataProvider isValidRegexDataProvider */
	public function testIsValidRegex(string $pattern, bool $expected): void {
		$this->assertSame($expected, GroupKeyResolver::isValidRegex($pattern));
	}

	// ---------------------------------------------------------------------------------------------------------
	// Cross-mode: items that produce no group key are excluded, never bucketed.
	// ---------------------------------------------------------------------------------------------------------

	public function testUnknownModeIsExcluded(): void {
		$item = ['name' => 'CPU load'];
		$config = ['mode' => 999];

		$this->assertNull(GroupKeyResolver::resolve($item, $config));
	}

	public function testMissingSourceFieldIsExcludedNotError(): void {
		// Item has no 'name' key at all (e.g. a text-only item shape passed in by mistake).
		$item = [];
		$config = ['mode' => GroupKeyResolver::MODE_ITEM_NAME_PATTERN, 'pattern' => 'IIS [*] Requests', 'capture' => 1];

		$this->assertNull(GroupKeyResolver::resolve($item, $config));
	}
}
