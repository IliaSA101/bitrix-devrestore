<?php
/**
 * Заглушка ядра Битрикс для сквозного теста devrestore.php.
 * Реализует ровно то, что вызывает шаг «Ядро»: CUser, ModuleManager, Option, BXClearCache.
 * Пишет в ту же базу, что и сам сайт (параметры из /bitrix/.settings.php).
 */

namespace Bitrix\Main
{
	final class StubDb
	{
		public static function link(): \mysqli
		{
			static $link = null;
			if ($link === null)
			{
				$s = include $_SERVER['DOCUMENT_ROOT'] . '/bitrix/.settings.php';
				$c = $s['connections']['value']['default'];
				[$host, $port] = explode(':', $c['host']);
				$link = new \mysqli($host, $c['login'], $c['password'], $c['database'], (int)$port);
				$link->set_charset('utf8mb4');
			}
			return $link;
		}

		public static function esc(string $s): string
		{
			return "'" . self::link()->real_escape_string($s) . "'";
		}
	}

	final class ModuleManager
	{
		public static function isModuleInstalled(string $id): bool
		{
			return (bool)StubDb::link()->query('SELECT 1 FROM b_module WHERE ID = ' . StubDb::esc($id))->fetch_row();
		}

		public static function registerModule(string $id): void
		{
			StubDb::link()->query('INSERT IGNORE INTO b_module (ID) VALUES (' . StubDb::esc($id) . ')');
		}
	}
}

namespace Bitrix\Main\Config
{
	use Bitrix\Main\StubDb;

	final class Option
	{
		public static function set(string $module, string $name, string $value): void
		{
			$w = 'MODULE_ID = ' . StubDb::esc($module) . ' AND NAME = ' . StubDb::esc($name);
			StubDb::link()->query("DELETE FROM b_option WHERE $w");
			StubDb::link()->query('INSERT INTO b_option (MODULE_ID, NAME, VALUE) VALUES (' . StubDb::esc($module) . ', ' . StubDb::esc($name) . ', ' . StubDb::esc($value) . ')');
		}
	}
}

namespace
{
	use Bitrix\Main\StubDb;

	define('B_PROLOG_INCLUDED', true);
	// Ядро должно подключаться с этими константами — иначе на хите поедут агенты и почта.
	foreach (['NO_AGENT_CHECK', 'DisableEventsCheck', 'NOT_CHECK_PERMISSIONS'] as $c)
	{
		if (!defined($c))
		{
			throw new RuntimeException("stub: константа $c не объявлена до пролога");
		}
	}
	echo 'stub prolog output that must not reach JSON';

	class CStubResult
	{
		private $row;

		public function __construct($row)
		{
			$this->row = $row;
		}

		public function Fetch()
		{
			$r = $this->row;
			$this->row = false;
			return $r;
		}
	}

	class CUser
	{
		public $LAST_ERROR = '';
		public $authorized = 0;

		public static function GetByID($id)
		{
			return new CStubResult(StubDb::link()->query('SELECT * FROM b_user WHERE ID = ' . (int)$id)->fetch_assoc() ?: false);
		}

		public function Update($id, $fields)
		{
			if (strlen($fields['PASSWORD']) < 10)
			{
				$this->LAST_ERROR = 'Пароль короче 10 символов';
				return false;
			}
			// как обработчик OnBeforeUserUpdate на портале с премодерацией профилей: вне /bitrix/admin/ логин менять нельзя,
			// поле молча вырезается, а Update всё равно возвращает true
			if (strpos((string)($_SERVER['REQUEST_URI'] ?? ''), '/bitrix/admin/') !== 0)
			{
				unset($fields['LOGIN']);
			}
			// у настоящего хеша соль случайная — каждый Update даёт новый хеш
			StubDb::link()->query('UPDATE b_user SET ' . (isset($fields['LOGIN']) ? 'LOGIN = ' . StubDb::esc($fields['LOGIN']) . ', ' : '')
				. 'PASSWORD = ' . StubDb::esc('stub-' . bin2hex(random_bytes(8))) . ", ACTIVE = 'Y' WHERE ID = " . (int)$id);
			return true;
		}

		public static function GetUserGroup($id)
		{
			return [2];
		}

		public static function SetUserGroup($id, $groups)
		{
			\Bitrix\Main\Config\Option::set('stub', 'groups_' . (int)$id, implode(',', $groups));
		}

		public function Authorize($id)
		{
			$this->authorized = (int)$id;
			\Bitrix\Main\Config\Option::set('stub', 'authorized', (string)(int)$id);
			return true;
		}
	}

	function BXClearCache($full = false)
	{
		\Bitrix\Main\Config\Option::set('stub', 'cache_cleared', 'Y');
	}

	$USER = new CUser();
}
