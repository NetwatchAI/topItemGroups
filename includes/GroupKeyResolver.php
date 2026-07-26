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
 * Derives a group key from an item, per the widget's "Group by" configuration.
 *
 * Pure, dependency-free: takes plain arrays in, returns a plain string or null out. No API calls, no Zabbix
 * globals, so it can be unit-tested without a frontend (see tests/unit/GroupKeyResolverTest.php).
 *
 * Expected $item shape (only the keys relevant to the chosen mode need be present):
 *   [
 *       'name' => string,    // name or name_resolved, already chosen by the caller (see REPORT.md Q6)
 *       'key_' => string,    // item key, exactly as returned by API::Item()->get()
 *       'tags' => [['tag' => string, 'value' => string], ...]
 *   ]
 *
 * Expected $config shape (fields beyond 'mode' are only required for the modes that use them):
 *   [
 *       'mode'      => self::MODE_*,
 *       'pattern'   => string,   // wildcard pattern (MODE_ITEM_NAME_PATTERN / MODE_ITEM_KEY_PATTERN)
 *                                // or regex (MODE_REGEX)
 *       'capture'   => int,      // 1-based: which wildcard, or which regex capture group, to extract
 *       'key_param' => int,      // 1-based key parameter index (MODE_ITEM_KEY_PARAMETER)
 *       'tag'       => string,   // tag name (MODE_ITEM_TAG)
 *       'match_field' => string  // self::FIELD_NAME | self::FIELD_KEY (MODE_REGEX only)
 *   ]
 */
class GroupKeyResolver {

	public const MODE_ITEM_NAME_PATTERN = 1;
	public const MODE_ITEM_KEY_PATTERN = 2;
	public const MODE_ITEM_KEY_PARAMETER = 3;
	public const MODE_ITEM_TAG = 4;
	public const MODE_REGEX = 5;

	public const FIELD_NAME = 'name';
	public const FIELD_KEY = 'key_';

	/**
	 * Resolve the group key for a single item under the given configuration.
	 *
	 * @param array $item    See class docblock.
	 * @param array $config  See class docblock.
	 *
	 * @return string|null  The group key, or null if this item does not belong to any group (never coerced to an
	 *                       "unknown" bucket - the caller must exclude it from the result set).
	 */
	public static function resolve(array $item, array $config): ?string {
		$value = match ($config['mode']) {
			self::MODE_ITEM_NAME_PATTERN => self::resolveWildcard($item[self::FIELD_NAME] ?? '', $config),
			self::MODE_ITEM_KEY_PATTERN => self::resolveWildcard($item[self::FIELD_KEY] ?? '', $config),
			self::MODE_ITEM_KEY_PARAMETER => self::resolveKeyParameter($item[self::FIELD_KEY] ?? '', $config),
			self::MODE_ITEM_TAG => self::resolveTag($item['tags'] ?? [], $config),
			self::MODE_REGEX => self::resolveRegex($item, $config),
			default => null
		};

		// An empty resolved value is not a meaningful row label - treat it the same as "no match".
		return ($value === null || $value === '') ? null : $value;
	}

	/**
	 * Check whether a user-supplied regular expression compiles, without evaluating it against any data.
	 * Used by widget-level configuration validation (never let a bad regex reach resolve() and fatal).
	 */
	public static function isValidRegex(string $pattern): bool {
		return @preg_match('/'.\CRegexHelper::handleSlashEscaping($pattern).'/u', '') !== false;
	}

	/**
	 * How many wildcards ('*') a pattern contains, so the form can validate a capture index against it.
	 */
	public static function countWildcards(string $pattern): int {
		return substr_count($pattern, '*');
	}

	private static function resolveWildcard(string $subject, array $config): ?string {
		$regex = self::buildWildcardCaptureRegex($config['pattern'], (int) $config['capture']);

		if ($regex === null) {
			return null;
		}

		return (@preg_match($regex, $subject, $matches) === 1) ? $matches[1] : null;
	}

	/**
	 * Build a regex out of a '*'-wildcard pattern that captures only the Nth wildcard's matched text, with every
	 * other wildcard kept as a non-capturing, non-greedy match. Anchored start-to-end, case-insensitive and
	 * Unicode-aware to mirror the semantics of Zabbix's own wildcard search (see REPORT.md Q4: zbx_db_search()
	 * translates '*' to SQL '%' with no implicit leading/trailing wildcard, i.e. a full-string match).
	 *
	 * @return string|null  A ready-to-use preg pattern, or null if the pattern has fewer than $capture wildcards.
	 */
	private static function buildWildcardCaptureRegex(string $pattern, int $capture): ?string {
		if ($capture < 1) {
			return null;
		}

		$chunks = preg_split('/(\*)/', $pattern, -1, PREG_SPLIT_DELIM_CAPTURE);

		$wildcard_num = 0;
		$capture_found = false;
		$regex = '';

		foreach ($chunks as $chunk) {
			if ($chunk === '*') {
				$wildcard_num++;

				if ($wildcard_num === $capture) {
					$regex .= '(.*?)';
					$capture_found = true;
				}
				else {
					$regex .= '.*?';
				}
			}
			elseif ($chunk !== '') {
				$regex .= preg_quote($chunk, '/');
			}
		}

		if (!$capture_found) {
			return null;
		}

		return '/^'.$regex.'$/isu';
	}

	private static function resolveKeyParameter(string $key, array $config): ?string {
		$key_parser = new \CItemKey();

		if ($key_parser->parse($key) == \CParser::PARSE_FAIL) {
			return null;
		}

		$param = $key_parser->getParam(((int) $config['key_param']) - 1);

		return $param;
	}

	private static function resolveTag(array $tags, array $config): ?string {
		foreach ($tags as $tag) {
			if ($tag['tag'] === $config['tag']) {
				return $tag['value'];
			}
		}

		return null;
	}

	private static function resolveRegex(array $item, array $config): ?string {
		$field = $config['match_field'] === self::FIELD_KEY ? self::FIELD_KEY : self::FIELD_NAME;
		$subject = $item[$field] ?? '';

		$pattern = '/'.self::escapeDelimiter($config['pattern']).'/u';
		$capture = (int) $config['capture'];

		$result = @preg_match($pattern, $subject, $matches);

		if ($result !== 1 || !array_key_exists($capture, $matches)) {
			return null;
		}

		return $matches[$capture];
	}

	private static function escapeDelimiter(string $pattern): string {
		return str_replace('/', '\/', $pattern);
	}
}
