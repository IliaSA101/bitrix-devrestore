<?php
/**
 * devrestore.php — восстановление копии 1С-Битрикс / Битрикс24 для разработки
 * с обезличиванием персональных данных.
 *
 * Положите файл в корень сайта (рядом с архивом резервной копии) и откройте в браузере.
 * Работает только на разрешённых хостах (локальных или явно перечисленных тестовых).
 *
 *   A. Подготовка: проверка окружения, уборка старых архивов и дампов, загрузка свежего
 *      restore.php с 1c-bitrix.ru и точечная правка: после восстановления он вернёт сюда.
 *   B. Обработка БД: отключение внешних действий → обезличивание → dev-настройки →
 *      уборка файлов → ядро (dev-админ, модули разработки, скрипты клиента) → проверка утечек.
 *
 * Общие настройки и профили клиентов лежат вне корня сайта, в папке профилей (см. DR_HOME).
 */

const DR_VERSION = '2.9.1';

// Папка профилей: config.php, profiles.json, scripts/, state/, logs/.
// По умолчанию — <родитель DOCUMENT_ROOT>/.devrestore, рядом с проектом (вне веб-доступа, на сервер с сайтом не уедет).
// DR_HOME — ручное переопределение, например одна общая папка на все проекты; в коде по умолчанию пусто.
// Порядок: DR_HOME (если папка есть) → переменная окружения DEVRESTORE_HOME (если папка есть) → .devrestore рядом с корнем.
const DR_HOME = '';

// Можно задать прямо здесь, чтобы одним файлом приехать на тестовый сервер
// (дополняют config.php): ключ доступа и хосты через запятую.
const DR_ACCESS_KEY = '';
const DR_ALLOWED_HOSTS = '';

const DR_RESTORE_URL = 'https://www.1c-bitrix.ru/download/files/scripts/restore.php';

error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
@set_time_limit(300);
@ignore_user_abort(true);
if (function_exists('mysqli_report'))
{
	mysqli_report(MYSQLI_REPORT_OFF);
}
ob_start();

if (PHP_VERSION_ID < 70400)
{
	die('devrestore: нужен PHP 7.4 или новее');
}
if (!extension_loaded('mysqli'))
{
	die('devrestore: нужно расширение PHP mysqli');
}

// фоновый импорт запускается из командной строки (php devrestore.php --dr-import=…): корень сайта — папка самого файла
if (PHP_SAPI === 'cli' && empty($_SERVER['DOCUMENT_ROOT']))
{
	$_SERVER['DOCUMENT_ROOT'] = __DIR__;
}
$drRoot = dr_norm_path((string)realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? '')));
if ($drRoot === '' || !dr_same_path($drRoot, dr_norm_path((string)realpath(__DIR__))))
{
	http_response_code(500);
	die('devrestore.php должен лежать в корне сайта (DOCUMENT_ROOT).');
}
define('DR_ROOT', $drRoot);
define('DR_SELF', basename(__FILE__));

// ============================================================================
// Утилиты
// ============================================================================

function dr_norm_path(string $p): string
{
	return rtrim(str_replace('\\', '/', $p), '/');
}

function dr_same_path(string $a, string $b): bool
{
	return DIRECTORY_SEPARATOR === '\\' ? strcasecmp($a, $b) === 0 : $a === $b;
}

function dr_h($s): string
{
	return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Строковый литерал SQL (sql_mode без NO_BACKSLASH_ESCAPES — его выставляет DrDb). */
function dr_lit(string $s): string
{
	return "'" . str_replace(['\\', "'"], ['\\\\', "''"], $s) . "'";
}

function dr_qi(string $name): string
{
	return '`' . str_replace('`', '``', $name) . '`';
}

function dr_host(): string
{
	$h = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
	if (preg_match('/^\[([0-9a-f:]+)\](:\d+)?$/', $h, $m))
	{
		return $m[1];
	}
	$h = (string)preg_replace('/:\d+$/', '', $h);
	return preg_match('/^[a-z0-9.\-]+$/', $h) ? $h : '';
}

function dr_clean_host($s): string
{
	$s = strtolower(trim((string)$s));
	$s = (string)preg_replace('~^[a-z]+://~', '', $s);
	$s = (string)preg_replace('~[/?#].*$~', '', $s);
	$s = (string)preg_replace('~:\d+$~', '', $s);
	return preg_match('/^[a-z0-9.\-]+$/', $s) ? $s : '';
}

function dr_ip_is_loopback(string $ip): bool
{
	$ip = trim($ip);
	return $ip === '::1' || $ip === '::ffff:127.0.0.1' || strpos($ip, '127.') === 0;
}

/**
 * Запрос с этой же машины. За обратным прокси (nginx → apache без remoteip, туннели) REMOTE_ADDR всегда 127.0.0.1,
 * поэтому любой заголовок проксирования с не-локальным адресом делает запрос «удалённым» — нужен ключ.
 */
function dr_is_loopback(): bool
{
	if (!dr_ip_is_loopback((string)($_SERVER['REMOTE_ADDR'] ?? '')))
	{
		return false;
	}
	foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_FORWARDED', 'HTTP_CLIENT_IP', 'HTTP_X_CLIENT_IP'] as $h)
	{
		if (empty($_SERVER[$h]))
		{
			continue;
		}
		preg_match_all('/[0-9a-f.:]{3,}/i', (string)$_SERVER[$h], $m);
		foreach ($m[0] ?: ['?'] as $ip)
		{
			if (!dr_ip_is_loopback($ip))
			{
				return false;
			}
		}
	}
	return true;
}

/** Варианты текущего хоста для сравнения с боевыми доменами: заголовок Host и имя из конфига веб-сервера, с www и без. */
function dr_host_variants(): array
{
	$out = [];
	foreach ([dr_host(), dr_clean_host($_SERVER['SERVER_NAME'] ?? '')] as $h)
	{
		if ($h !== '')
		{
			$out[] = (string)preg_replace('/^www\./', '', $h);
		}
	}
	return array_values(array_unique($out));
}

function dr_host_match(string $host, array $patterns): bool
{
	foreach ($patterns as $p)
	{
		$p = strtolower(trim((string)$p));
		if ($p === '')
		{
			continue;
		}
		$rx = '/^' . str_replace('\*', '[a-z0-9.\-]*', preg_quote($p, '/')) . '$/';
		if (preg_match($rx, $host))
		{
			return true;
		}
	}
	return false;
}

function dr_is_ajax(): bool
{
	return ($_REQUEST['action'] ?? '') === 'step';
}

function dr_json(array $data): void
{
	while (ob_get_level() > 0)
	{
		ob_end_clean();
	}
	header('Content-Type: application/json; charset=utf-8');
	header('Cache-Control: no-store');
	echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
	exit;
}

function dr_fatal(string $title, string $text, int $code = 403): void
{
	if (dr_is_ajax())
	{
		dr_json(['ok' => false, 'error' => $title . ': ' . $text]);
	}
	http_response_code($code);
	dr_page($title, '<div class="alert alert-danger"><b>' . dr_h($title) . '</b><br>' . dr_h($text) . '</div>');
}

function dr_redirect(string $url): void
{
	while (ob_get_level() > 0)
	{
		ob_end_clean();
	}
	header('Location: ' . $url);
	exit;
}

function dr_self_url(array $params = []): string
{
	return '/' . DR_SELF . ($params ? '?' . http_build_query($params) : '');
}

function dr_human_size(float $b): string
{
	$u = ['Б', 'КБ', 'МБ', 'ГБ', 'ТБ'];
	$i = 0;
	while ($b >= 1024 && $i < 4)
	{
		$b /= 1024;
		$i++;
	}
	return round($b, $i ? 1 : 0) . ' ' . $u[$i];
}

function dr_rrmdir(string $dir): bool
{
	if (!is_dir($dir))
	{
		return true;
	}
	$ok = true;
	foreach ((array)scandir($dir) as $f)
	{
		if ($f === '.' || $f === '..')
		{
			continue;
		}
		$p = $dir . '/' . $f;
		if (is_dir($p) && !is_link($p))
		{
			$ok = dr_rrmdir($p) && $ok;
		}
		else
		{
			$ok = @unlink($p) && $ok;
		}
	}
	return @rmdir($dir) && $ok;
}

// ============================================================================
// Папка профилей, конфиг, состояние, журнал
// ============================================================================

function dr_home(): string
{
	static $home = null;
	if ($home !== null)
	{
		return $home;
	}
	$cands = [];
	if (DR_HOME !== '')
	{
		$cands[] = DR_HOME;
	}
	if (($env = getenv('DEVRESTORE_HOME')) !== false && $env !== '')
	{
		$cands[] = $env;
	}
	foreach ($cands as $c)
	{
		if (is_dir($c))
		{
			return $home = dr_norm_path($c);
		}
	}
	return $home = dirname(DR_ROOT) . '/.devrestore';
}

function dr_home_init(): void
{
	$home = dr_home();
	// Папка профилей — разрешение запуска на этой машине: её создают руками, сам скрипт этого не делает.
	// Так файл, случайно уехавший на боевой сервер вместе с корнем сайта, там ничего не сможет.
	if (!is_dir($home))
	{
		http_response_code(403);
		die('devrestore: на этой машине запуск не разрешён. Создайте папку ' . dr_h($home)
			. ' (вне корня сайта) или укажите существующую в константе DR_HOME либо в переменной окружения DEVRESTORE_HOME — и обновите страницу.');
	}
	if (stripos(dr_norm_path((string)realpath($home)) . '/', DR_ROOT . '/') === 0)
	{
		http_response_code(500);
		die('devrestore: папка профилей ' . dr_h($home) . ' лежит внутри корня сайта — её содержимое было бы доступно из браузера.');
	}
	foreach ([$home . '/scripts', $home . '/state', $home . '/logs'] as $d)
	{
		if (!is_dir($d) && !@mkdir($d, 0775, true) && !is_dir($d))
		{
			dr_fatal('Нет папки профилей', 'Не удалось создать ' . $d . '. Создайте её вручную или укажите другую папку в константе DR_HOME в начале файла либо в переменной окружения DEVRESTORE_HOME.', 500);
		}
	}
	if (!is_file($home . '/config.php'))
	{
		file_put_contents($home . '/config.php', dr_config_template());
	}
	if (!is_file($home . '/.htaccess'))
	{
		@file_put_contents($home . '/.htaccess', "Require all denied\nDeny from all\n");
	}
}

function dr_config_defaults(): array
{
	return [
		'allowed_hosts' => ['localhost', '127.0.0.1', '::1', '*.local', '*.loc', '*.test', '*.localhost'],
		'access_key' => '',
		'dev_admin' => ['id' => 1, 'login' => 'admin', 'password' => ''],
		'keep_user_ids' => [],
		'text_mode' => 'dict',
		'companies' => 'individuals',
		'single_surname' => true,
		'clean_max_rows' => 500000,
		'agents_keep' => [],
		'dev_modules' => ['delight.mcp' => ['jwt_secret_key']],
		'self_delete' => 'auto',
		'batch_size' => 10000,
		'step_seconds' => 15,
		'leak_scan' => 'quick',
		'leak_scan_max_rows' => 1000000,
		'leak_scan_exclude' => [],
		'leak_quick_rows' => 20000,
		'mysql_bin' => '',
		'php_bin' => '',
		'import_skip_truncated' => true,
	];
}

function dr_config(): array
{
	static $cfg = null;
	if ($cfg !== null)
	{
		return $cfg;
	}
	dr_home_init();
	// в обход OPcache: config.php правят руками, а revalidate_freq отдал бы прошлую версию
	$user = dr_include_fresh(dr_home() . '/config.php');
	$cfg = array_replace(dr_config_defaults(), is_array($user) ? $user : []);
	if (DR_ALLOWED_HOSTS !== '')
	{
		$cfg['allowed_hosts'] = array_merge((array)$cfg['allowed_hosts'], array_map('trim', explode(',', DR_ALLOWED_HOSTS)));
	}
	if ($cfg['access_key'] === '' && DR_ACCESS_KEY !== '')
	{
		$cfg['access_key'] = DR_ACCESS_KEY;
	}
	$cfg['dev_admin'] = array_replace(dr_config_defaults()['dev_admin'], (array)$cfg['dev_admin']);
	return $cfg;
}

function dr_state_file(): string
{
	return dr_home() . '/state/' . substr(md5(strtolower(DR_ROOT)), 0, 12) . '.json';
}

function dr_state_load(): array
{
	$f = dr_state_file();
	$s = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
	if (!is_array($s))
	{
		$s = [];
	}
	if (empty($s['csrf']))
	{
		$s['csrf'] = bin2hex(random_bytes(16));
		$s['root'] = DR_ROOT;
		dr_state_save($s);
	}
	return $s;
}

function dr_state_save(array $s): void
{
	$json = json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
	if ($json === false || file_put_contents(dr_state_file(), $json, LOCK_EX) === false)
	{
		throw new RuntimeException('Не удалось сохранить состояние в ' . dr_state_file());
	}
}

function dr_profiles(): array
{
	$f = dr_home() . '/profiles.json';
	$p = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
	return is_array($p) ? $p : [];
}

function dr_profiles_save(array $p): void
{
	ksort($p);
	file_put_contents(dr_home() . '/profiles.json', json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX);
}

function dr_logf(string $line): void
{
	@file_put_contents(dr_home() . '/logs/devrestore-' . date('Y-m') . '.txt', date('[Y-m-d H:i:s] ') . '[' . dr_host() . '] ' . $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function dr_config_template(): string
{
	return <<<'PHP'
<?php
/**
 * Настройки devrestore.php — общие для всех сайтов, использующих эту папку профилей.
 * Профили клиентов (что выбрано для конкретного боевого домена) — в profiles.json рядом,
 * их пишет сам скрипт. Скрипты клиентов — в папке scripts/.
 */
return [
	// Хосты, на которых разрешён запуск. Звёздочка — любая часть имени.
	// Тестовый сервер добавьте явно: 'bitrix24.dev.example.ru' или '*.dev.example.ru'.
	'allowed_hosts' => ['localhost', '127.0.0.1', '::1', '*.local', '*.loc', '*.test', '*.localhost'],

	// Ключ доступа, если скрипт открывают не с этой же машины (тестовый сервер).
	// Пустой ключ — доступ только с 127.0.0.1.
	'access_key' => '',

	// Под кем работать на dev-копии. Пустой пароль — сгенерируется и будет показан в отчёте.
	'dev_admin' => ['id' => 1, 'login' => 'admin', 'password' => ''],

	// ID пользователей, которых не обезличивать (например, ваш аккаунт на портале клиента).
	'keep_user_ids' => [],

	// Свободный текст (комментарии, переписка, письма, названия сделок) по умолчанию:
	//   dict   — оставлять текст, заменяя людей (по словарю ФИО из базы, во всех падежах), e-mail, телефоны,
	//            ИНН физлиц, СНИЛС, карты, паспорта и адреса. Это псевдонимизация: редкие формы имён могут остаться,
	//            их показывает проверка на утечки;
	//   strict — заменять целиком на «[скрыто, N симв.]» — надёжнее всего;
	//   scrub  — оставлять текст, вычищая только e-mail и телефоны (имена остаются!).
	// Можно переопределить в профиле клиента.
	'text_mode' => 'dict',

	// Компании: individuals — заменять только ИП, КФХ и физлиц (ИНН из 12 цифр), юрлица оставлять;
	// all — заменять все названия. Учтите: юрлицо + должность контакта («директор ООО Ромашка») указывают на человека.
	'companies' => 'individuals',

	// Режим dict: одиночную фамилию из словаря («Звонок Иванову») тоже заменять.
	'single_surname' => true,

	// Режим dict: таблицы крупнее (строк) чистятся не по словарю, а заменой целиком — иначе обработка займёт часы.
	'clean_max_rows' => 500000,

	// Агенты, которые не отключать (шаблоны LIKE по полю NAME), например '%CSearch%'.
	'agents_keep' => [],

	// Модули разработки, которые живут в /bitrix/modules, но слетают при заливке чужой базы.
	// Перед восстановлением сохраняются перечисленные опции из текущей (локальной) базы,
	// после обезличивания модуль ставится заново, опции возвращаются, enabled = Y.
	// Для delight.mcp это значит: старый токен в .mcp.json продолжает работать.
	'dev_modules' => ['delight.mcp' => ['jwt_secret_key']],

	// Удалять devrestore.php в конце: 'auto' — если открыт не с 127.0.0.1 (тестовый сервер), true/false — всегда/никогда.
	// Сайт в Docker на своей машине приходит не с 127.0.0.1 — поставьте false.
	'self_delete' => 'auto',

	'batch_size' => 10000,   // строк (по ID) на один UPDATE
	'step_seconds' => 15,    // длительность одного шага обработки

	// Проверка на утечки после обезличивания:
	//   quick — только колонки, которые правила не обработали, и последние leak_quick_rows строк каждой (по умолчанию);
	//   full  — все текстовые колонки целиком (таблицы крупнее leak_scan_max_rows — по последним 200 000 строкам);
	//   false — не проверять.
	'leak_scan' => 'quick',
	'leak_quick_rows' => 20000,
	'leak_scan_max_rows' => 1000000,
	'leak_scan_exclude' => [],        // таблицы или шаблоны LIKE, которые не проверять

	// Импорт дампа клиентом mysql (в разы быстрее, чем через restore.php; restore.php только распаковывает архив).
	// '' — найти самому: рядом с сервером MySQL (@@basedir), затем в PATH; путь — взять этот; false — всегда через restore.php.
	'mysql_bin' => '',
	// PHP для командной строки, в котором идёт фоновый импорт: '' — найти рядом с PHP сайта и в PATH.
	'php_bin' => '',
	// Не загружать данные таблиц, которые обработка всё равно очищает (логи, очереди, поисковый индекс, сессии, корзина).
	'import_skip_truncated' => true,
];

PHP;
}

// ============================================================================
// Защита
// ============================================================================

function dr_guard(array $cfg): void
{
	$host = dr_host();
	if ($host === '' || !dr_host_match($host, (array)$cfg['allowed_hosts']))
	{
		dr_logf('ОТКАЗ: хост не разрешён');
		dr_fatal('Запуск на хосте «' . $host . '» запрещён', 'Разрешены только хосты из allowed_hosts: ' . implode(', ', (array)$cfg['allowed_hosts']) . '. Тестовый хост добавьте в ' . dr_home() . '/config.php или в константу DR_ALLOWED_HOSTS.');
	}
	if (isset(dr_profiles()[$host]))
	{
		dr_logf('ОТКАЗ: текущий хост — боевой домен из профилей');
		dr_fatal('Это боевой домен клиента', 'Хост «' . $host . '» записан в профилях как боевой. Запуск запрещён.');
	}
	if (dr_is_loopback())
	{
		return;
	}
	$key = (string)$cfg['access_key'];
	if ($key === '')
	{
		dr_fatal('Доступ только с этой машины', 'Скрипт открыт не с 127.0.0.1. Для тестового сервера задайте access_key в ' . dr_home() . '/config.php или DR_ACCESS_KEY в начале файла.');
	}
	$cookie = hash('sha256', $key . '|' . DR_ROOT);
	if (hash_equals($cookie, (string)($_COOKIE['dr_key'] ?? '')))
	{
		return;
	}
	$given = (string)($_POST['dr_key'] ?? '');
	if ($given !== '' && hash_equals($key, $given))
	{
		setcookie('dr_key', $cookie, ['expires' => time() + 86400 * 7, 'path' => '/', 'httponly' => true, 'samesite' => 'Strict', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
		dr_logf('Вход по ключу доступа');
		dr_redirect(dr_self_url());
	}
	if ($given !== '')
	{
		dr_logf('ОТКАЗ: неверный ключ доступа');
		usleep(500000);
	}
	if (dr_is_ajax())
	{
		dr_json(['ok' => false, 'error' => 'Нужен ключ доступа — обновите страницу.']);
	}
	dr_page('Ключ доступа', '<form method="post" class="card"><h2>Ключ доступа</h2>'
		. ($given !== '' ? '<div class="alert alert-danger">Неверный ключ.</div>' : '')
		. '<p>Скрипт открыт не с локальной машины. Введите access_key из config.php.</p>'
		. '<input type="password" name="dr_key" autofocus required> <button class="btn">Войти</button></form>');
}

function dr_check_csrf(array $state): void
{
	if (!hash_equals((string)$state['csrf'], (string)($_POST['csrf'] ?? '')))
	{
		dr_fatal('Форма устарела', 'Обновите страницу и повторите.', 400);
	}
}

// ============================================================================
// База данных
// ============================================================================

/** include в обход OPcache: файл мог быть переписан в этом же запросе (иначе прочитается старая версия и затрёт правку). */
function dr_include_fresh(string $f)
{
	if (function_exists('opcache_invalidate'))
	{
		@opcache_invalidate($f, true);
	}
	return (static function ($f) {
		return include $f;
	})($f);
}

function dr_write_php(string $f, string $code): void
{
	file_put_contents($f, $code);
	if (function_exists('opcache_invalidate'))
	{
		@opcache_invalidate($f, true);
	}
}

function dr_db_params(): array
{
	$f = DR_ROOT . '/bitrix/.settings.php';
	if (is_file($f))
	{
		$s = dr_include_fresh($f);
		$c = $s['connections']['value']['default'] ?? null;
		if (is_array($c) && !empty($c['host']))
		{
			return ['host' => (string)$c['host'], 'db' => (string)$c['database'], 'user' => (string)$c['login'], 'pass' => (string)$c['password']];
		}
	}
	$f = DR_ROOT . '/bitrix/php_interface/dbconn.php';
	if (is_file($f))
	{
		$src = (string)file_get_contents($f);
		$p = [];
		foreach (['DBHost' => 'host', 'DBName' => 'db', 'DBLogin' => 'user', 'DBPassword' => 'pass'] as $var => $k)
		{
			if (preg_match('/\$' . $var . '\s*=\s*([\'"])(.*?)\1\s*;/s', $src, $m))
			{
				$p[$k] = stripslashes($m[2]);
			}
		}
		if (isset($p['host'], $p['db'], $p['user']))
		{
			return $p + ['pass' => ''];
		}
	}
	throw new RuntimeException('Не найдены параметры подключения к БД (/bitrix/.settings.php или dbconn.php)');
}

final class DrDb
{
	/** @var mysqli */
	public $link;
	public $cs = 'utf8mb4';
	public $server = '';
	public $isMaria = false;
	/** Строка, которую нельзя показывать в тексте ошибки (соль обезличивания). */
	public $redact = '';
	/** Соль: подставляется вместо DR_SALT_MARK при выполнении, в задачах и тексте ошибок её нет. */
	public $salt = '';
	private $cols = null;
	private $tbls = null;
	private $uniq = null;
	private $rx = null;

	public static function connect(): DrDb
	{
		$p = dr_db_params();
		[$host, $port, $socket] = dr_db_host($p['host']);
		$link = mysqli_init();
		$link->options(MYSQLI_OPT_CONNECT_TIMEOUT, 10);
		if (!@$link->real_connect($host, $p['user'], $p['pass'], $p['db'], $port, $socket))
		{
			throw new RuntimeException('Не удалось подключиться к БД: ' . mysqli_connect_error());
		}
		$db = new self();
		$db->link = $link;
		if (!$link->set_charset('utf8mb4'))
		{
			$link->set_charset('utf8');
			$db->cs = 'utf8';
		}
		$db->server = (string)$link->server_info;
		$db->isMaria = stripos($db->server, 'mariadb') !== false;
		$db->q("SET SESSION sql_mode = ''");
		return $db;
	}

	/**
	 * @param bool $salted подставить соль вместо DR_SALT_MARK (false — для запросов с данными из базы)
	 * @return mysqli_result|bool
	 */
	public function q(string $sql, bool $salted = true)
	{
		$r = $this->link->query($salted && $this->salt !== '' ? str_replace(DR_SALT_MARK, $this->salt, $sql) : $sql);
		if ($r === false)
		{
			$shown = $this->redact !== '' ? str_replace($this->redact, '***', $sql) : $sql;
			$err = $this->link->error;
			if ($salted && $this->salt !== '')
			{
				$err = (string)preg_replace('/[0-9a-f]{8,}/i', '***', $err);
			}
			throw new RuntimeException($err . ' [SQL: ' . mb_substr($shown, 0, 300) . ']', $this->link->errno);
		}
		return $r;
	}

	public function exec(string $sql, bool $salted = true): int
	{
		$this->q($sql, $salted);
		return max(0, (int)$this->link->affected_rows);
	}

	/** Есть ли таблица прямо сейчас (без кеша списка таблиц). */
	public function tableExists(string $t): bool
	{
		return (int)$this->val('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ' . dr_lit($t)) > 0;
	}

	public function all(string $sql): array
	{
		$r = $this->q($sql);
		$out = [];
		while ($row = $r->fetch_assoc())
		{
			$out[] = $row;
		}
		$r->free();
		return $out;
	}

	public function one(string $sql): ?array
	{
		$rows = $this->all($sql);
		return $rows[0] ?? null;
	}

	public function val(string $sql)
	{
		$row = $this->one($sql);
		return $row ? reset($row) : null;
	}

	/** lower(имя) => ['name' => ..., 'rows' => ...] */
	public function tables(): array
	{
		if ($this->tbls === null)
		{
			$this->tbls = [];
			foreach ($this->all("SELECT TABLE_NAME n, TABLE_ROWS r FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'") as $r)
			{
				$this->tbls[strtolower($r['n'])] = ['name' => $r['n'], 'rows' => (int)$r['r']];
			}
		}
		return $this->tbls;
	}

	/** lower(таблица) => upper(колонка) => ['name','type','null','key','len'] */
	public function columns(): array
	{
		if ($this->cols === null)
		{
			$this->cols = [];
			$rows = $this->all("SELECT TABLE_NAME t, COLUMN_NAME c, DATA_TYPE d, IS_NULLABLE n, COLUMN_KEY k, CHARACTER_MAXIMUM_LENGTH l, CHARACTER_SET_NAME cs, COLLATION_NAME co FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME, ORDINAL_POSITION");
			foreach ($rows as $r)
			{
				$this->cols[strtolower($r['t'])][strtoupper($r['c'])] = [
					'name' => $r['c'],
					'type' => strtolower($r['d']),
					'null' => $r['n'] === 'YES',
					'key' => (string)$r['k'],
					'len' => (int)$r['l'],
					'cs' => (string)$r['cs'],
					'coll' => (string)$r['co'],
				];
			}
		}
		return $this->cols;
	}

	public function hasTable(string $t): bool
	{
		return isset($this->tables()[strtolower($t)]);
	}

	public function tableName(string $t): string
	{
		return $this->tables()[strtolower($t)]['name'] ?? $t;
	}

	public function hasColumn(string $t, string $c): bool
	{
		return isset($this->columns()[strtolower($t)][strtoupper($c)]);
	}

	/** Колонки, входящие в PRIMARY или UNIQUE (в том числе составной): одинаковая подмена в них упрётся в ключ. upper(колонка) => true */
	public function uniqueCols(string $t): array
	{
		if ($this->uniq === null)
		{
			$this->uniq = [];
			foreach ($this->all('SELECT TABLE_NAME t, COLUMN_NAME c FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND NON_UNIQUE = 0') as $r)
			{
				$this->uniq[strtolower($r['t'])][strtoupper($r['c'])] = true;
			}
		}
		return $this->uniq[strtolower($t)] ?? [];
	}

	/** Единственный числовой первичный ключ или null. */
	public function pk(string $t): ?string
	{
		$pri = [];
		foreach ($this->columns()[strtolower($t)] ?? [] as $meta)
		{
			if ($meta['key'] === 'PRI')
			{
				$pri[] = $meta;
			}
		}
		if (count($pri) === 1 && dr_is_int_type($pri[0]['type']))
		{
			return $pri[0]['name'];
		}
		return null;
	}

	public function option(string $module, string $name): ?string
	{
		if (!$this->hasTable('b_option'))
		{
			return null;
		}
		$v = $this->val('SELECT VALUE FROM b_option WHERE MODULE_ID = ' . dr_lit($module) . ' AND NAME = ' . dr_lit($name) . ' LIMIT 1');
		return $v === null ? null : (string)$v;
	}

	public function setOption(string $module, string $name, string $value): void
	{
		$w = 'MODULE_ID = ' . dr_lit($module) . ' AND NAME = ' . dr_lit($name);
		if ($this->val("SELECT COUNT(*) FROM b_option WHERE $w") > 0)
		{
			$this->exec('UPDATE b_option SET VALUE = ' . dr_lit($value) . " WHERE $w");
		}
		else
		{
			$this->exec('INSERT INTO b_option (MODULE_ID, NAME, VALUE) VALUES (' . dr_lit($module) . ', ' . dr_lit($name) . ', ' . dr_lit($value) . ')');
		}
	}

	public function supportsRegexpReplace(): bool
	{
		if ($this->rx === null)
		{
			try
			{
				$this->rx = $this->val("SELECT REGEXP_REPLACE('ab', 'b', 'c')") === 'ac';
			}
			catch (Throwable $e)
			{
				$this->rx = false;
			}
		}
		return $this->rx;
	}
}

function dr_is_int_type(string $t): bool
{
	return in_array($t, ['tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint'], true);
}

function dr_is_num_type(string $t): bool
{
	return dr_is_int_type($t) || in_array($t, ['decimal', 'numeric', 'float', 'double', 'real'], true);
}

function dr_is_str_type(string $t): bool
{
	return in_array($t, ['char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext'], true);
}

// ============================================================================
// Обезличивание: построитель SQL-выражений
// ============================================================================

/** Метка соли в SQL задач: настоящая соль подставляется в DrDb::q и не хранится в задачах. */
const DR_SALT_MARK = '{{devrestore-salt}}';
/** Временный словарь людей: удаляется до отметки «обезличено». */
const DR_DICT_TABLE = 'zz_devrestore_dict';

const DR_RX_EMAIL = '[[:alnum:]._%+-]+@[[:alnum:].-]+[.][[:alpha:]]{2,}';
const DR_RX_PHONE_PLUS = '[+]7[ (-]*[1-9][0-9]{2}[ )-]*[0-9]{3}[ -]*[0-9]{2}[ -]*[0-9]{2}';
// границы — не цифра и не латинская буква: 11 цифр внутри hex-ID, UUID и хешей — не телефон
const DR_RX_PHONE_8 = '(^|[^0-9A-Za-z])8[ (-]*[3-9][0-9]{2}[ )-]*[0-9]{3}[ -]*[0-9]{2}[ -]*[0-9]{2}([^0-9A-Za-z]|$)';
const DR_RX_PHONE_BARE = '(^|[^0-9A-Za-z])[78][3-9][0-9]{9}([^0-9A-Za-z]|$)';
const DR_RX_PHONE_SEP = '(^|[^0-9A-Za-z+.,-])(7 *)?[(]?9[0-9]{2}[)]?[ -]?[0-9]{3}[ -][0-9]{2}[ -][0-9]{2}([^0-9A-Za-z-]|$)';
// колонки идентификаторов и хешей: телефоном в них оказываются случайные 11 цифр — проверяем только e-mail
const DR_RX_ID_COLUMN = '/(^|_)(ID|UUID|GUID|HASH|HID|ETAG|XML_ID|CODE|CHECK|SUBDIR)$|FILE_HASH|DIGEST/i';

/**
 * Просмотр документов Диска после очистки секретов. Облачный редактор (документы Б24, доски) работает по ключу,
 * привязанному к домену прода; ключ стёрт — ядро на первом хите само переключит просмотр на встроенный и пришлёт
 * админу баннер «выбран недоступный вариант». Делаем это заранее. Ключ оставлен через options_keep — выбор не трогаем.
 * $cleared — очищенные опции «модуль/имя». Возвращает имя опции disk => новое значение.
 */
function dr_disk_viewer_fix(?string $viewer, ?string $boards, array $cleared): array
{
	$cleared = array_map('strtolower', $cleared);
	$out = [];
	if (in_array('disk/disk_onlyoffice_b24_secretkey', $cleared, true) && $viewer !== null && $viewer !== '' && $viewer !== 'bitrix')
	{
		$out['default_viewer_service'] = 'bitrix';
	}
	if (in_array('disk/disk_boards_b24_secretkey', $cleared, true) && $boards !== null && $boards !== '')
	{
		$out['default_viewer_service_boards'] = '';
	}
	return $out;
}

/** Префикс cookie Битрикс для dev-хоста: main/cookie_name. */
function dr_cookie_prefix(string $host): string
{
	return 'BITRIX_SM_' . strtoupper((string)preg_replace('/[^A-Za-z0-9]/', '_', $host));
}

/**
 * Заголовки итогового ответа: браузер забывает вход и сессию прежней копии. Иначе страница открывается,
 * а AJAX отклоняется по токену защиты, и сервер на каждый запрос заводит новую сессию.
 * Clear-Site-Data работает только на https; просроченные Set-Cookie — подстраховка, в том числе на http.
 * Cookie, поставленные по http, со страницы https не стереть (и Secure-cookie со страницы http) — поэтому на сервере
 * сессии прежней копии ещё и аннулируются новым server_uniq_id.
 * $cookies — имена из запроса; стираются сессия PHP и cookie Битрикс (префикс сайта и любой BITRIX_SM*), ключ devrestore — нет.
 */
function dr_logout_headers(array $cookies, string $host, bool $https, string $sessionName, string $prefix): array
{
	$names = [$sessionName => true, 'PHPSESSID' => true];
	foreach ($cookies as $n)
	{
		$n = (string)$n;
		if ($n === $sessionName || strpos($n, $prefix) === 0 || strpos($n, 'BITRIX_SM') === 0)
		{
			$names[$n] = true;
		}
	}
	unset($names['dr_key']);
	$out = $https ? ['Clear-Site-Data: "cookies"'] : [];
	$host = (string)preg_replace('/:\d+$/', '', $host);
	foreach (array_keys($names) as $n)
	{
		if ($n === '' || preg_match('/[^A-Za-z0-9_\-.]/', $n))
		{
			continue;
		}
		// cookie без домена и cookie на домен — разные cookie: стираем обе
		foreach (['', '; Domain=' . $host] as $domain)
		{
			$out[] = 'Set-Cookie: ' . $n . '=deleted; Expires=Thu, 01 Jan 1970 00:00:01 GMT; Max-Age=0; Path=/' . $domain . ($https ? '; Secure' : '');
		}
	}
	return $out;
}

/** Строка-код или ссылка внутри сериализованного и JSON (lead_12, CRM_DEAL, /crm/deal/details/5/, https://…): в ней ищем только контакты. */
const DR_RX_CODE_VALUE = '~^(?:[A-Za-z0-9_.:/\-]+|(?:https?://|/)\S*)$~';

/**
 * Сериализованное PHP или JSON-значение: распаковать, прогнать строковые значения через $fn, упаковать обратно.
 * $kind (ser или json) — подсказка правила: если значение так не распаковалось, пробуем другим способом.
 * Ключи не трогаются. $fn(строка, код) — код: значение вида lead_12 или ссылка (DR_RX_CODE_VALUE).
 * Возвращает [новое значение, состояние]: same — менять нечего (значение null, строку не писать); changed;
 * broken — не распаковалось или обратная упаковка не совпала с исходным байт-в-байт: значение не трогаем.
 */
function dr_struct_clean(string $v, string $kind, callable $fn, int $depth = 0): array
{
	foreach ($kind === 'json' ? ['json', 'ser'] : ['ser', 'json'] as $k)
	{
		$x = dr_struct_unpack($v, $k);
		if ($x === null)
		{
			continue;
		}
		$changed = false;
		$val = dr_struct_walk($x[0], $fn, $depth, $changed);
		if (!$changed)
		{
			return [null, 'same'];
		}
		$out = $k === 'ser' ? serialize($val) : json_encode($val, $x[1]);
		return is_string($out) ? [$out, 'changed'] : [null, 'broken'];
	}
	return [null, 'broken'];
}

/** [значение, флаги json_encode] или null, если без потерь не распаковать. */
function dr_struct_unpack(string $v, string $kind): ?array
{
	if ($kind === 'ser')
	{
		if (!preg_match('/^(?:[abidsOC]:|N;)/', $v))
		{
			return null;
		}
		// объект любого класса приходит как __PHP_Incomplete_Class и упаковывается обратно как был; внутрь него не заходим
		$x = @unserialize($v, ['allowed_classes' => false]);
		return ($x !== false || $v === 'b:0;') && serialize($x) === $v ? [$x, 0] : null;
	}
	// объекты, а не массивы: иначе пустой {} при упаковке станет []
	$x = json_decode($v);
	if (json_last_error() !== JSON_ERROR_NONE)
	{
		return null;
	}
	// флаги, с которыми значение записано, подбираем по исходнику: \u-экранирование, \/, HEX_* (как у Bitrix Json::encode), 1.0.
	// Большое целое json_decode превращает в дробное — исходник не воспроизводится, значение считается битым.
	$hex = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
	foreach ([0, JSON_UNESCAPED_UNICODE] as $u)
	{
		foreach ([0, JSON_UNESCAPED_SLASHES] as $sl)
		{
			foreach ([0, $hex] as $h)
			{
				foreach ([0, JSON_PRESERVE_ZERO_FRACTION] as $z)
				{
					if (json_encode($x, $u | $sl | $h | $z) === $v)
					{
						return [$x, $u | $sl | $h | $z];
					}
				}
			}
		}
	}
	return null;
}

/** Обход распакованного значения: строки — через dr_struct_str, массивы и объекты JSON — вглубь, остальное как есть. */
function dr_struct_walk($x, callable $fn, int $depth, bool &$changed)
{
	if (is_string($x))
	{
		$new = dr_struct_str($x, $fn, $depth);
		$changed = $changed || $new !== $x;
		return $new;
	}
	if (is_array($x))
	{
		foreach ($x as $k => $item)
		{
			$x[$k] = dr_struct_walk($item, $fn, $depth, $changed);
		}
	}
	elseif ($x instanceof stdClass)
	{
		foreach (get_object_vars($x) as $k => $item)
		{
			$x->{$k} = dr_struct_walk($item, $fn, $depth, $changed);
		}
	}
	return $x;
}

function dr_struct_str(string $s, callable $fn, int $depth): string
{
	if ($s === '')
	{
		return $s;
	}
	// внутри бывает ещё одно сериализованное или JSON: чистим его как структуру, иначе собьются длины и экранирование
	if ($depth < 3 && preg_match('/^(?:[aOs]:\d+:[{"]|\s*[\[{])/', $s))
	{
		[$new, $st] = dr_struct_clean($s, preg_match('/^[aOs]:/', $s) ? 'ser' : 'json', $fn, $depth + 1);
		if ($st !== 'broken')
		{
			return $new ?? $s;
		}
	}
	return (string)$fn($s, (bool)preg_match(DR_RX_CODE_VALUE, $s));
}

/** Адрес сервера из настроек Битрикс: [хост, порт или null, сокет или null] (p: — постоянное соединение, отбрасываем). */
function dr_db_host(string $host): array
{
	$port = null;
	$socket = null;
	if (strpos($host, 'p:') === 0)
	{
		$host = substr($host, 2);
	}
	if (preg_match('~^(.*):(\d+)$~', $host, $m))
	{
		$host = $m[1];
		$port = (int)$m[2];
	}
	elseif (preg_match('~^(.*?):(/.+)$~', $host, $m))
	{
		$host = $m[1] !== '' ? $m[1] : 'localhost';
		$socket = $m[2];
	}
	return [$host, $port, $socket];
}

/**
 * Файл параметров клиента mysql (--defaults-extra-file): пароль не попадает ни в командную строку, ни в список процессов.
 * $defaultSocket — сокет, которым ходит mysqli (localhost без сокета в настройках): у клиента путь по умолчанию бывает другим.
 */
function dr_mysql_cnf(array $p, string $defaultSocket = ''): string
{
	[$host, $port, $socket] = dr_db_host((string)$p['host']);
	$q = static function (string $v): string {
		return '"' . addcslashes($v, "\\\"\n\r\t") . '"';
	};
	$s = "[client]\nuser=" . $q((string)$p['user']) . "\npassword=" . $q((string)$p['pass']) . "\nhost=" . $q($host) . "\n";
	if ($port !== null)
	{
		$s .= 'port=' . $port . "\n";
	}
	$socket = $socket ?? ($host === 'localhost' && $defaultSocket !== '' ? $defaultSocket : null);
	if ($socket !== null)
	{
		$s .= 'socket=' . $q($socket) . "\n";
	}
	return $s;
}

/** Тома дампа по порядку: имя.sql, имя.sql.1, имя.sql.2… (как getNextName у restore.php). */
function dr_dump_parts(string $first): array
{
	$out = [];
	for ($f = $first, $n = 1; is_file($f); $f = $first . '.' . $n++)
	{
		$out[] = $f;
	}
	return $out;
}

/**
 * Начало оператора дампа: [вид (insert, create, drop), таблица в нижнем регистре, смещение ключевого слова] или null.
 * Строки-комментарии (-- и #) перед оператором пропускаются: restore.php склеивает их со следующим оператором.
 */
function dr_dump_head(string $stmt): ?array
{
	$head = substr($stmt, 0, 4096);
	$off = preg_match('/^(?:[ \t\r\n]*(?:--|#)[^\n]*\n)+/', $head, $m) ? strlen($m[0]) : 0;
	if (!preg_match('/\G[ \t\r\n]*(INSERT[ \t]+INTO|CREATE[ \t]+TABLE(?:[ \t]+IF[ \t]+NOT[ \t]+EXISTS)?|DROP[ \t]+TABLE(?:[ \t]+IF[ \t]+EXISTS)?)[ \t\r\n]+`?([A-Za-z0-9_$]+)`?/i',
		$head, $m, PREG_OFFSET_CAPTURE, $off))
	{
		return null;
	}
	$kind = ['i' => 'insert', 'c' => 'create', 'd' => 'drop'][strtolower($m[1][0][0])];
	return [$kind, strtolower($m[2][0]), $m[1][1]];
}

/**
 * Дамп резервной копии Битрикс как один поток по всем томам. Операторы — по правилу restore.php: оператор закрывает
 * строка, у которой предпоследний символ «;». Строка, разрезанная границей тома, склеивается.
 * Хвост без «;» в конце дампа restore.php не выполняет — здесь он тоже отбрасывается (его длина — в $tail).
 */
final class DrDumpReader
{
	private $parts;
	private $i = 0;
	private $fh = null;
	/** Прочитано байт по всем томам. */
	public $pos = 0;
	public $tail = 0;

	public function __construct(array $parts)
	{
		$this->parts = array_values($parts);
	}

	private function line(): ?string
	{
		$buf = '';
		while (true)
		{
			if ($this->fh === null)
			{
				if (!isset($this->parts[$this->i]))
				{
					return $buf === '' ? null : $buf;
				}
				$this->fh = @fopen($this->parts[$this->i], 'rb');
				if (!$this->fh)
				{
					throw new RuntimeException('не удалось открыть том дампа ' . basename($this->parts[$this->i]));
				}
			}
			$l = fgets($this->fh);
			if ($l === false)
			{
				fclose($this->fh);
				$this->fh = null;
				$this->i++;
				continue;
			}
			$this->pos += strlen($l);
			$buf .= $l;
			if (substr($l, -1) === "\n")
			{
				return $buf;
			}
		}
	}

	public function next(): ?string
	{
		$st = '';
		while (($l = $this->line()) !== null)
		{
			$st .= $l;
			if (substr($st, -2, 1) === ';')
			{
				return $st;
			}
		}
		$this->tail = strlen(trim($st));
		return null;
	}
}

/** Регулярка имён таблиц по шаблонам LIKE правил (% — любая часть имени) без учёта регистра; пустой список — null. */
function dr_like_rx(array $patterns): ?string
{
	$alt = [];
	foreach ($patterns as $p)
	{
		$alt[] = str_replace('%', '.*', preg_quote(strtolower((string)$p), '/'));
	}
	return $alt ? '/^(?:' . implode('|', array_unique($alt)) . ')$/i' : null;
}

/** Таблицы, данные которых при импорте не грузятся: группы очистки (truncate) из правил, кроме выключенных по умолчанию. */
function dr_import_skip_patterns(array $rules): array
{
	$off = [];
	foreach ($rules as $r)
	{
		if (!empty($r['default_off']))
		{
			$off[(string)$r['group']] = true;
		}
	}
	$out = [];
	foreach ($rules as $r)
	{
		if (isset($r['truncate']) && !isset($off[(string)$r['group']]))
		{
			foreach ((array)$r['truncate'] as $t)
			{
				$out[] = (string)$t;
			}
		}
	}
	return array_values(array_unique($out));
}

/** Где искать PHP для командной строки, если сайт работает через php-cgi, php-fpm или модуль веб-сервера. */
function dr_php_cli_candidates(string $binary, bool $win): array
{
	$out = [];
	if ($binary !== '')
	{
		$base = strtolower(basename($binary));
		$dir = dirname($binary);
		if (preg_match('/^php(\d+(\.\d+)?)?(\.exe)?$/', $base))
		{
			$out[] = $binary;
		}
		$out[] = $dir . '/php' . ($win ? '.exe' : '');
		// php-fpm8.2, php-cgi8.2 → php8.2 рядом и в /usr/bin
		if (preg_match('/^php-(?:fpm|cgi)(\d+(?:\.\d+)?)/', $base, $m))
		{
			$out[] = $dir . '/php' . $m[1];
			$out[] = '/usr/bin/php' . $m[1];
		}
	}
	return array_values(array_unique($out));
}

/** Длительность для отчёта: «850 мс», «12 с», «4 мин 05 с», «1 ч 02 мин». */
function dr_duration(float $sec): string
{
	if ($sec < 1)
	{
		return (int)round($sec * 1000) . ' мс';
	}
	$s = (int)round($sec);
	if ($s < 60)
	{
		return $s . ' с';
	}
	if ($s < 3600)
	{
		return intdiv($s, 60) . ' мин ' . sprintf('%02d', $s % 60) . ' с';
	}
	return intdiv($s, 3600) . ' ч ' . sprintf('%02d', intdiv($s % 3600, 60)) . ' мин';
}

/** Время обработки по этапам и пять самых долгих шагов: [этап => секунды], [[шаг, секунды]…]. */
function dr_stage_times(array $tasks): array
{
	$check = 'Проверка на утечки';
	$end = 'Завершение: отметка, dev-настройки, уборка, ядро';
	$st = ['Обезличивание' => 0.0, $check => 0.0, $end => 0.0];
	$top = [];
	foreach ($tasks as $t)
	{
		$sec = (float)($t['sec'] ?? 0);
		$kind = (string)($t['kind'] ?? '');
		$k = in_array($kind, ['leak', 'leak_names'], true) ? $check : (in_array($kind, ['drop_dict', 'marker', 'devsettings', 'cleanup', 'kernel'], true) ? $end : 'Обезличивание');
		$st[$k] += $sec;
		$top[] = [(string)($t['label'] ?? ''), $sec];
	}
	usort($top, static function ($a, $b) {
		return $b[1] <=> $a[1];
	});
	return [$st, array_slice($top, 0, 5)];
}

final class DrAnon
{
	// Двойники. Все склоняются регулярно (DrName), мужские имена — не на -а/-я: род двойника виден по списку.
	const FIRST_M = ['Александр', 'Алексей', 'Анатолий', 'Андрей', 'Антон', 'Аркадий', 'Арсений', 'Артём', 'Борис', 'Вадим', 'Валентин', 'Валерий',
		'Василий', 'Виктор', 'Виталий', 'Владимир', 'Владислав', 'Всеволод', 'Вячеслав', 'Геннадий', 'Георгий', 'Герман', 'Глеб', 'Григорий',
		'Даниил', 'Денис', 'Дмитрий', 'Евгений', 'Егор', 'Захар', 'Иван', 'Игорь', 'Кирилл', 'Константин', 'Леонид', 'Макар', 'Максим', 'Матвей',
		'Михаил', 'Николай', 'Олег', 'Павел', 'Пётр', 'Платон', 'Родион', 'Ростислав', 'Руслан', 'Савелий', 'Семён', 'Сергей', 'Станислав',
		'Степан', 'Тимофей', 'Тимур', 'Фёдор', 'Филипп', 'Эдуард', 'Юрий', 'Яков', 'Ярослав'];
	const FIRST_F = ['Александра', 'Алина', 'Алла', 'Алёна', 'Анастасия', 'Анна', 'Антонина', 'Арина', 'Валентина', 'Валерия', 'Варвара', 'Вера',
		'Вероника', 'Виктория', 'Галина', 'Дарья', 'Диана', 'Евгения', 'Екатерина', 'Елена', 'Елизавета', 'Жанна', 'Зинаида', 'Зоя', 'Инна',
		'Ирина', 'Карина', 'Кира', 'Кристина', 'Ксения', 'Лариса', 'Лидия', 'Людмила', 'Маргарита', 'Марина', 'Мария', 'Милана',
		'Надежда', 'Наталья', 'Нина', 'Оксана', 'Олеся', 'Ольга', 'Полина', 'Раиса', 'Регина', 'Светлана', 'Софья', 'Таисия', 'Тамара',
		'Татьяна', 'Ульяна', 'Эвелина', 'Юлия', 'Яна'];
	const LAST_M = ['Иванов', 'Петров', 'Сидоров', 'Смирнов', 'Кузнецов', 'Попов', 'Васильев', 'Соколов', 'Михайлов', 'Новиков', 'Фёдоров',
		'Морозов', 'Волков', 'Алексеев', 'Лебедев', 'Семёнов', 'Егоров', 'Павлов', 'Козлов', 'Степанов', 'Николаев', 'Орлов', 'Андреев',
		'Макаров', 'Никитин', 'Захаров', 'Зайцев', 'Соловьёв', 'Борисов', 'Яковлев', 'Григорьев', 'Романов', 'Воробьёв', 'Сергеев', 'Кузьмин',
		'Фролов', 'Александров', 'Дмитриев', 'Гусев', 'Киселёв', 'Ильин', 'Максимов', 'Поляков', 'Сорокин', 'Виноградов', 'Ковалёв', 'Белов',
		'Медведев', 'Антонов', 'Тарасов', 'Баранов', 'Филиппов', 'Комаров', 'Давыдов', 'Беляев', 'Герасимов', 'Богданов', 'Осипов',
		'Колесников', 'Тихонов', 'Крылов', 'Карпов', 'Абрамов', 'Марков', 'Титов', 'Власов', 'Мельников', 'Денисов', 'Гаврилов', 'Матвеев',
		'Чернов', 'Широков', 'Голубев', 'Казаков', 'Афанасьев', 'Савельев', 'Лазарев', 'Ефимов', 'Назаров', 'Мухин', 'Калинин', 'Субботин',
		'Блинов', 'Кудрявцев', 'Прохоров', 'Наумов', 'Миронов', 'Щербаков', 'Ершов', 'Шубин', 'Тимофеев', 'Логинов', 'Рябов', 'Цветков'];
	const PATR_M = ['Александрович', 'Алексеевич', 'Анатольевич', 'Андреевич', 'Антонович', 'Аркадьевич', 'Борисович', 'Валентинович',
		'Валерьевич', 'Васильевич', 'Викторович', 'Витальевич', 'Владимирович', 'Вячеславович', 'Геннадьевич', 'Георгиевич', 'Григорьевич',
		'Дмитриевич', 'Евгеньевич', 'Егорович', 'Иванович', 'Игоревич', 'Кириллович', 'Константинович', 'Леонидович', 'Максимович',
		'Матвеевич', 'Михайлович', 'Николаевич', 'Олегович', 'Павлович', 'Петрович', 'Романович', 'Семёнович', 'Сергеевич', 'Станиславович',
		'Степанович', 'Фёдорович', 'Юрьевич', 'Ярославович'];
	/** Мужские имена на -а/-я (иначе по окончанию считаем имя женским). */
	const MALE_A = ['никита', 'илья', 'кузьма', 'фома', 'лука', 'савва', 'данила', 'гаврила', 'миша', 'паша', 'гоша', 'лёша', 'леша', 'дима', 'коля', 'ваня', 'петя', 'вася', 'толя', 'серёжа', 'сережа', 'витя', 'юра', 'слава', 'костя', 'федя', 'стёпа', 'тёма', 'вова', 'гена', 'жора', 'саня'];

	/** Колонки-дубли текста (HTML-версии, заголовки писем): в режиме dict заменяются целиком — чистить их дорого и незачем. */
	/** Организационно-правовые формы юрлиц (начало названия). */
	const LEGAL_FORMS = ['ООО', 'ОАО', 'ЗАО', 'ПАО', 'АО', 'НАО', 'НКО', 'АНО', 'ГУП', 'МУП', 'ФГУП', 'ФГБУ', 'ФГБОУ', 'ФГАОУ', 'ФКУ', 'ГБУ', 'ГКУ', 'ГАУ', 'ГБОУ',
		'ГБУЗ', 'МБУ', 'МКУ', 'МАУ', 'МБОУ', 'МАОУ', 'МБДОУ', 'ТОО', 'ОДО', 'ПК', 'СПК', 'ТСЖ', 'ТСН', 'СНТ'];

	const DUP_TEXT = ['BODY_HTML', 'HEADER', 'POST_MESSAGE_HTML', 'POST_MESSAGE_FILTER', 'MESSAGE_OUT', 'MESSAGE_HEADERS'];

	private $salt;
	private $cs;
	private $mode;
	private $rxOk;
	private $companies;

	/**
	 * $salt в задачах — метка DR_SALT_MARK: настоящая соль подставляется при выполнении и в state не попадает.
	 * $textMode: dict — текст чистится в PHP по словарю людей; strict — заменяется целиком; scrub — вычищаются e-mail и телефоны.
	 */
	public function __construct(string $salt, string $cs, string $textMode, bool $rxOk = true, string $companies = 'all')
	{
		$this->salt = $salt;
		$this->cs = $cs;
		$this->mode = $textMode;
		$this->rxOk = $rxOk;
		$this->companies = $companies;
	}

	/**
	 * PHP-метод для колонки (чистка выполняется в PHP построчно) или null — колонка идёт в SQL.
	 * clean — текст по словарю; person — значение-человек (ФИО); keep_rx — оставить, вычистив контакты и документы.
	 */
	public function php($spec, string $col): ?string
	{
		// из составных методов в PHP идёт только заголовок: ['title', 'Сделка'] — подпись нужна лишь для замены целиком
		if (is_array($spec) && ($spec[0] ?? '') !== 'title')
		{
			return null;
		}
		$name = is_array($spec) ? 'title' : (string)$spec;
		if ($this->mode !== 'dict')
		{
			return null;
		}
		if (in_array(strtoupper($col), self::DUP_TEXT, true))
		{
			return null;
		}
		switch ($name)
		{
			case 'text':
			case 'title':
			case 'clean':
				return 'clean';
			case 'full_name':
			case 'person':
			case 'company_person':
				return 'person';
			case 'keep_rx':
				return 'keep_rx';
			case 'company':
				return $this->companies === 'individuals' ? 'clean' : null;
		}
		return null;
	}

	public function mode(): string
	{
		return $this->mode;
	}

	/**
	 * Строка словаря людей для колонки имени (f), фамилии (l) или отчества (p): [ключ основы, род формы, двойник].
	 * Двойник считают те же выражения, что и для самих полей, — человек в тексте и в карточке получает одного двойника.
	 * Ключ — MD5(соль:k:основа), а не основа: брошенный словарь не раскрывает, кто есть кто.
	 */
	public function dictRow(string $kind, array $c, ?string $nameCol): array
	{
		$col = $c['col'];
		$n = $this->norm($col);
		$k = "MD5(CONCAT('" . $this->salt . ":k:', REPLACE($n, 'ё', 'е')))";
		if ($kind === 'f')
		{
			return [$k, 'IF(' . $this->isFemale($col) . ', 1, 0)', 'LOWER(' . $this->raw('first_name', [], $c) . ')'];
		}
		if ($kind === 'l')
		{
			return [$k, 'IF(' . self::surnameFemSql($n) . ', 1, 0)', 'LOWER(' . $this->raw('last_name', [$nameCol], $c) . ')'];
		}
		return [$k, "IF(RIGHT($n, 2) = 'на', 1, 0)", 'LOWER(' . $this->raw('patronymic', [$nameCol], $c) . ')'];
	}

	/** Тот же обезличиватель, но текст — заменой целиком (большие таблицы, колонки-дубли). */
	public function strict(): DrAnon
	{
		$c = clone $this;
		$c->mode = 'strict';
		return $c;
	}

	/**
	 * Полное выражение для SET: учитывает тип колонки и не трогает NULL и пустые строки.
	 * $c: col (с алиасом), meta, pk (с алиасом или null), q (callable имя→колонка с алиасом), already (уже заменённые колонки).
	 */
	public function build($spec, array $c): ?string
	{
		$name = is_array($spec) ? (string)$spec[0] : (string)$spec;
		$col = $c['col'];
		$m = $c['meta'];
		$isStr = dr_is_str_type($m['type']);
		// пустое значение в колонке с уникальным ключом получит одна строка, остальные упрутся в ключ и останутся
		// с исходным (настоящим токеном) — пишем вместо пустого хеш исходного значения той же длины
		if ($isStr && !empty($c['unique']) && ($name === 'secret' || $name === 'empty' || ($name === 'null' && !$m['null'])))
		{
			$name = 'unique_token';
		}
		switch ($name)
		{
			case 'keep':
				return null;
			case 'null':
				if ($m['null'])
				{
					return 'NULL';
				}
				if ($isStr)
				{
					return "''";
				}
				return dr_is_num_type($m['type']) ? '0' : null;
			case 'empty':
			case 'secret':
				if ($isStr)
				{
					return "''";
				}
				return $m['null'] ? 'NULL' : null;
			case 'random_password':
				return $isStr ? "CONCAT('!devrestore!', MD5(RAND()))" : null;
		}
		if (!$isStr)
		{
			return null;
		}
		$raw = $this->raw($name, is_array($spec) ? array_slice($spec, 1) : [], $c);
		if ($raw === null)
		{
			return null;
		}
		// Подставное значение собрано в кодировке соединения; приводим к кодировке и сравнению колонки,
		// иначе на таблицах в cp1251 IF() падает с «Illegal mix of collations».
		if (preg_match('/^\w+$/', $m['cs'] ?? '') && preg_match('/^\w+$/', $m['coll'] ?? ''))
		{
			$raw = "CONVERT($raw USING {$m['cs']}) COLLATE {$m['coll']}";
		}
		return "IF($col IS NULL OR $col = '', $col, $raw)";
	}

	/** Выражение в кодировке соединения: исходная колонка внутри — только через CONVERT. */
	public function raw(string $name, array $arg, array $c): ?string
	{
		$col = $c['col'];
		$u = 'CONVERT(' . $col . ' USING ' . $this->cs . ')';
		switch ($name)
		{
			case 'keep':
				return $u;
			case 'empty':
			case 'secret':
				return "''";
			case 'unique_token':
				// длина как у исходного (не короче 16 — меньше коллизий), не длиннее колонки и SHA-256 в hex
				$len = 'GREATEST(CHAR_LENGTH(' . $col . '), 16)';
				$max = (int)($c['meta']['len'] ?? 0);
				$len = $max > 0 ? "LEAST($len, " . min(64, $max) . ')' : "LEAST($len, 64)";
				return "LEFT(SHA2(CONCAT('" . $this->salt . ":u:', $u), 256), $len)";
			case 'first_name':
				$h = $this->hn($this->nameNorm($col), 'f');
				$o = $this->nameNorm($col);
				return 'IF(' . $this->isFemale($col) . ', ' . $this->elt(self::FIRST_F, $this->idxNot(self::FIRST_F, $h, $o)) . ', '
					. $this->elt(self::FIRST_M, $this->idxNot(self::FIRST_M, $h, $o)) . ')';
			case 'last_name':
				// хеш по мужской форме: Иванов и Иванова — одна семья, один двойник (Петров / Петрова)
				$h = $this->hn(self::surnameKeySql($this->nameNorm($col)), 'l');
				$i = $this->idxNot(self::LAST_M, $h, self::surnameKeySql($this->nameNorm($col)));
				return 'IF(' . $this->gender($arg[0] ?? null, $c, self::surnameFemSql($this->norm($col))) . ', ' . $this->elt(self::lastF(), $i) . ', ' . $this->elt(self::LAST_M, $i) . ')';
			case 'patronymic':
				$h = $this->hn(self::patrKeySql($this->nameNorm($col)), 'p');
				$i = $this->idxNot(self::PATR_M, $h, self::patrKeySql($this->nameNorm($col)));
				return 'IF(' . $this->gender($arg[0] ?? null, $c, '(RIGHT(' . $this->norm($col) . ", 2) = 'на')") . ', ' . $this->elt(self::patrF(), $i) . ', ' . $this->elt(self::PATR_M, $i) . ')';
			case 'full_name':
				$f = $this->h($col, 'f');
				$l = $this->h($col, 'l');
				return 'IF(MOD(' . $this->h($col, 'g', 4) . ', 2) = 1, '
					. 'CONCAT(' . $this->pick(self::FIRST_F, $f) . ", ' ', " . $this->pick(self::lastF(), $l) . '), '
					. 'CONCAT(' . $this->pick(self::FIRST_M, $f) . ", ' ', " . $this->pick(self::LAST_M, $l) . '))';
			case 'email':
				if (!empty($c['unique']) && $c['pk'])
				{
					return "CONCAT('u', {$c['pk']}, '@example.test')";
				}
				return "CONCAT('u', LEFT(" . $this->md5($col, 'e') . ", 10), '@example.test')";
			case 'phone':
				if (!empty($c['unique']) && $c['pk'])
				{
					return "CONCAT('+7000', LPAD(MOD({$c['pk']}, 10000000), 7, '0'))";
				}
				$digits = 'CONVERT(' . $col . ' USING ' . $this->cs . ')';
				foreach ([' ', '-', '(', ')', '+', '.'] as $ch)
				{
					$digits = "REPLACE($digits, '$ch', '')";
				}
				$md5 = "MD5(CONCAT('" . $this->salt . ":p:', RIGHT($digits, 10)))";
				return "CONCAT('+7000', LPAD(MOD(CONV(SUBSTR($md5, 1, 7), 16, 10), 10000000), 7, '0'))";
			case 'contact':
				return "IF($col LIKE '%@%', " . $this->raw('email', [], $c) . ', ' . $this->raw('phone', [], $c) . ')';
			case 'company':
				if ($this->companies === 'individuals')
				{
					// ИП и КФХ — человек; оставляем только то, что похоже на юрлицо («Иванов Иван Иванович» без ИП — не юрлицо).
					// LIKE, а не REGEXP: в MySQL 5.7 REGEXP по кириллице побайтовый и регистрозависимый
					$U = "UPPER(TRIM($u))";
					$like = static function (array $prefixes) use ($U): string {
						return '(' . implode(' OR ', array_map(static function ($p) use ($U) {
							return "$U LIKE " . dr_lit($p . '%');
						}, $prefixes)) . ')';
					};
					$ind = $like(['ИП ', 'ИП"', 'ИП«', 'КФХ ', 'ИНДИВИДУАЛЬН', 'ГЛАВА К']);
					$legal = [];
					foreach (self::LEGAL_FORMS as $f)
					{
						array_push($legal, $f . " ", $f . "\"", $f . "«");
					}
					$legal = $like(array_merge($legal, ['«', '"', 'ОБЩЕСТВО ', 'АКЦИОНЕРН', 'ПУБЛИЧН', 'ГОСУДАРСТВ', 'МУНИЦИПАЛ', 'ФЕДЕРАЛ', 'АВТОНОМН',
						'НЕКОММЕРЧ', 'ТОВАРИЩ', 'КООПЕРАТИВ', 'ПРОИЗВОДСТВЕНН', 'ФОНД ', 'АССОЦИАЦ', 'СОЮЗ ', 'УЧРЕЖДЕН', 'LLC', 'LTD', 'GMBH', 'INC ']));
					return "IF($ind, " . $this->raw('company_person', [], $c) . ", IF($legal, $u, " . $this->raw('company_all', [], $c) . '))';
				}
				return $this->raw('company_all', [], $c);
			case 'company_all':
				return "CONCAT('ООО «Компания ', UPPER(LEFT(" . $this->md5($col, 'c') . ", 5)), '»')";
			case 'company_person':
				// компания-физлицо (ИП, реквизит контакта): название — это человек
				return "CONCAT('ИП ', " . $this->raw('full_name', [], $c) . ')';
			case 'inn':
				// ИНН из 12 цифр — физлицо, заменяем; из 10 — юрлицо: оставляем, если названия юрлиц тоже оставлены
				if ($this->companies !== 'individuals')
				{
					return $this->raw('digits', [], $c);
				}
				return "IF(CHAR_LENGTH(TRIM($u)) = 12, " . $this->raw('digits', [], $c) . ", $u)";
			case 'address':
				return "CONCAT('г. Тестовск, ул. Примерная, д. ', 1 + MOD(" . $this->h($col, 'a') . ', 150))';
			case 'digits':
				// Документы и счета — случайно, без хеша: сквозная согласованность им не нужна,
				// а детерминированную замену короткого номера можно перебрать, если соль утечёт.
				$chunk = "LPAD(FLOOR(RAND() * 1000000000), 9, '0')";
				return "LEFT(CONCAT($chunk, $chunk, $chunk, $chunk), CHAR_LENGTH($col))";
			case 'clean':
			case 'text':
				// в dict сюда попадает текст внутри SQL-выражений (case/expr) и в таблицах без числового ключа — чистим регулярками
				if ($this->mode === 'scrub' || ($this->mode === 'dict' && $this->rxOk))
				{
					return $this->scrub($u);
				}
				return "CONCAT('[скрыто, ', CHAR_LENGTH($col), ' симв.]')";
			case 'text_strict':
				return "CONCAT('[скрыто, ', CHAR_LENGTH($col), ' симв.]')";
			case 'keep_rx':
				return $this->rxOk ? $this->scrub($u) : $u;
			case 'person':
				return $this->raw('full_name', [], $c);
			case 'title':
				if ($this->mode === 'scrub' || ($this->mode === 'dict' && $this->rxOk))
				{
					return $this->scrub($u);
				}
				$label = dr_lit((string)($arg[0] ?? 'Запись'));
				return $c['pk'] ? "CONCAT($label, ' #', {$c['pk']})" : "CONCAT($label, ' ', LEFT(" . $this->md5($col, 't') . ', 6))';
			case 'login':
				return $c['pk'] ? "CONCAT('user', {$c['pk']})" : "CONCAT('user_', LEFT(" . $this->md5($col, 'u') . ', 10))';
			case 'filename':
				return "CONCAT('file_', LEFT(" . $this->md5($col, 'n') . ", 10), IF(LOCATE('.', $u) > 0, CONCAT('.', LEFT(SUBSTRING_INDEX($u, '.', -1), 10)), ''))";
			case 'url':
				return "'https://example.test/'";
			case 'ip':
				return "'127.0.0.1'";
			case 'im':
				return "CONCAT('im_', LEFT(" . $this->md5($col, 'i') . ', 10))';
			case 'fixed':
				return dr_lit((string)($arg[0] ?? ''));
			case 'case':
				$typeCol = ($c['q'])((string)$arg[0]);
				if ($typeCol === null)
				{
					return null;
				}
				$sql = "CASE $typeCol";
				foreach ((array)$arg[1] as $val => $method)
				{
					$sql .= ' WHEN ' . dr_lit((string)$val) . ' THEN ' . $this->raw((string)$method, [], $c);
				}
				return $sql . ' ELSE ' . $this->raw((string)($arg[2] ?? 'keep'), [], $c) . ' END';
			case 'expr':
				$self = $this;
				return (string)preg_replace_callback('/\{(col|pk|m:([a-z_]+))\}/', static function ($mm) use ($self, $c) {
					if ($mm[1] === 'col')
					{
						return 'CONVERT(' . $c['col'] . ' USING ' . $self->charset() . ')';
					}
					if ($mm[1] === 'pk')
					{
						return $c['pk'] ?? 'NULL';
					}
					return (string)$self->raw($mm[2], [], $c);
				}, (string)$arg[0]);
		}
		throw new InvalidArgumentException('Неизвестный метод обезличивания: ' . $name);
	}

	public function charset(): string
	{
		return $this->cs;
	}

	public static function lastF(): array
	{
		return array_map(static function ($s) {
			return $s . 'а';
		}, self::LAST_M);
	}

	public static function patrF(): array
	{
		return array_map(static function ($s) {
			return mb_substr($s, 0, -2) . 'на';
		}, self::PATR_M);
	}

	/** Мужская форма фамилии (как DrName::surnameKey): Иванова → иванов, Покровская → покровский. */
	public static function surnameKeySql(string $n): string
	{
		return "CASE WHEN RIGHT($n, 3) IN ('ова', 'ева', 'ёва', 'ина', 'ына') THEN LEFT($n, CHAR_LENGTH($n) - 1)"
			. " WHEN RIGHT($n, 4) IN ('ская', 'цкая') THEN CONCAT(LEFT($n, CHAR_LENGTH($n) - 2), 'ий') ELSE $n END";
	}

	/** Женская ли фамилия по форме (как DrName::surnameFem). */
	public static function surnameFemSql(string $n): string
	{
		return "(RIGHT($n, 3) IN ('ова', 'ева', 'ёва', 'ина', 'ына') OR RIGHT($n, 2) = 'ая')";
	}

	/** Мужская форма отчества (как DrName::patrKey): Ивановна → иванович. */
	public static function patrKeySql(string $n): string
	{
		return "CASE WHEN RIGHT($n, 3) = 'вна' THEN CONCAT(LEFT($n, CHAR_LENGTH($n) - 2), 'ич')"
			. " WHEN RIGHT($n, 3) = 'чна' THEN LEFT($n, CHAR_LENGTH($n) - 2) ELSE $n END";
	}

	public function norm(string $col): string
	{
		return 'LOWER(TRIM(CONVERT(' . $col . ' USING ' . $this->cs . ')))';
	}

	/** Нормализация имени для хеша двойника: ё → е, как DrName::fold в PHP. */
	private function nameNorm(string $col): string
	{
		return 'REPLACE(' . $this->norm($col) . ", 'ё', 'е')";
	}

	private function md5(string $col, string $tag): string
	{
		return "MD5(CONCAT('" . $this->salt . ':' . $tag . ":', " . $this->norm($col) . '))';
	}

	private function h(string $col, string $tag, int $len = 8): string
	{
		return 'CONV(SUBSTR(' . $this->md5($col, $tag) . ', 1, ' . $len . '), 16, 10)';
	}

	/** Хеш от готового нормализованного выражения. */
	private function hn(string $normExpr, string $tag): string
	{
		return "CONV(SUBSTR(MD5(CONCAT('" . $this->salt . ':' . $tag . ":', " . $normExpr . ')), 1, 8), 16, 10)';
	}

	/**
	 * Номер двойника (1…n) для ELT, но не совпадающего с оригиналом: «Иванов» → не «Иванов».
	 * Как DrFakes::not() в PHP: при совпадении — следующий в списке. $list — мужской (сравнение по ключу основы).
	 */
	private function idxNot(array $list, string $h, string $orig): string
	{
		return '1 + MOD(' . $h . " + IF(REPLACE(LOWER(" . $this->pick($list, $h) . "), 'ё', 'е') = $orig, 1, 0), " . count($list) . ')';
	}

	private function elt(array $list, string $idx): string
	{
		return 'ELT(' . $idx . ', ' . implode(', ', array_map('dr_lit', $list)) . ')';
	}

	private function pick(array $list, string $h): string
	{
		return 'ELT(1 + MOD(' . $h . ', ' . count($list) . '), ' . implode(', ', array_map('dr_lit', $list)) . ')';
	}

	private function isFemale(string $col): string
	{
		$n = $this->norm($col);
		return "(RIGHT($n, 1) IN ('а', 'я') AND $n NOT IN (" . implode(', ', array_map('dr_lit', self::MALE_A)) . '))';
	}

	/** Пол для фамилии/отчества: по уже заменённому имени в этой же строке, иначе по исходному, а без имени — по форме самой фамилии/отчества. */
	private function gender(?string $nameCol, array $c, string $own): string
	{
		if ($nameCol !== null)
		{
			$q = ($c['q'])($nameCol);
			if ($q !== null)
			{
				$byName = in_array(strtoupper($nameCol), $c['already'], true)
					? "($q IN (" . implode(', ', array_map('dr_lit', self::FIRST_F)) . '))'
					: $this->isFemale($q);
				return "IF(COALESCE($q, '') = '', $own, $byName)";
			}
		}
		return $own;
	}

	private function scrub(string $col): string
	{
		$e = 'REGEXP_REPLACE(' . $col . ', ' . dr_lit(DR_RX_EMAIL) . ", 'user@example.test')";
		$e = 'REGEXP_REPLACE(' . $e . ', ' . dr_lit(DR_RX_PHONE_PLUS) . ", '+7 000 000-00-00')";
		$e = 'REGEXP_REPLACE(' . $e . ', ' . dr_lit('8[ (-]*[3-9][0-9]{2}[ )-]*[0-9]{3}[ -]*[0-9]{2}[ -]*[0-9]{2}') . ", '+7 000 000-00-00')";
		return $e;
	}
}

// ============================================================================
// Люди в тексте: склонения, двойники, чистка (режим «по словарю»)
// ============================================================================

/** Частые имена — чтобы узнать «Иван Петренко», которого нет в базе. Нижний регистр, ё→е. */
const DR_KNOWN_FIRST_M = 'александр алексей анатолий андрей антон аркадий арсений артем артур богдан борис вадим валентин валерий василий вениамин виктор виталий владимир владислав всеволод вячеслав гавриил геннадий георгий герман глеб григорий давид даниил данила денис дмитрий евгений егор емельян захар иван игнат игорь илья иннокентий иосиф кирилл константин лев леонид макар максим марат марк матвей михаил назар никита николай олег павел петр платон прохор родион роман ростислав руслан рустам савелий святослав семен сергей станислав степан тарас тимофей тимур трофим федор филипп эдуард эльдар юрий яков ян ярослав айдар азат ильдар ильнур дамир шамиль альберт ринат радик рафаэль рамиль ренат';
const DR_KNOWN_FIRST_F = 'агата агния алевтина александра алена алина алиса алла альбина анастасия ангелина анжела анна антонина арина валентина валерия варвара василиса вера вероника виктория виолетта галина дарина дарья диана дина евгения екатерина елена елизавета жанна зарина зинаида злата зоя инна ирина камилла карина кира клавдия кристина ксения лариса лейла леся лидия лилия любовь людмила майя маргарита марина мария марьям милана мирослава надежда наталья наталия нелли нина оксана олеся ольга полина раиса регина римма роза светлана софия софья стефания таисия тамара татьяна ульяна фаина эвелина элеонора элина эльвира эльмира юлиана юлия яна ярослава гульнара альфия резеда гузель динара лиана';
/** Слова, похожие на фамилию, которые одни на себе фамилией не считаются. */
const DR_NAME_STOP = 'москва россия сделка задача клиент компания заказчик поставщик звонок встреча договор оплата менеджер директор магазин бензин привет спасибо добрый здравствуйте уважаемый уважаемая пожалуйста сегодня завтра вчера январь февраль апрель август сентябрь октябрь ноябрь декабрь понедельник вторник среда четверг пятница суббота воскресенье итого всего примечание комментарий лидов сделок контактов отделов складов заводов тендеров торгов списков отчетов звонков счетов ростов королев пушкин медицина мастер пользователь тестовый тестовая оператор администратор';
const DR_GEO_AFTER = 'область обл край район р-н округ город улица ул проспект пр переулок пер шоссе площадь бульвар набережная вокзал аэропорт станция метро республика губерния завод университет институт поселок село деревня';
const DR_GEO_BEFORE = 'г гор ул пр пос с дер ст м обл р-н им';

/** Склонение русских ФИО по правилам (без словаря исключений). Слова — в нижнем регистре. Падежи: 0 им, 1 род, 2 дат, 3 вин, 4 тв, 5 пр. */
final class DrName
{
	const SIB = ['г', 'к', 'х', 'ж', 'ш', 'ч', 'щ'];
	const HUSH = ['ж', 'ш', 'ч', 'щ', 'ц'];
	const FLEETING = ['павел' => 'павл', 'пётр' => 'петр', 'петр' => 'петр', 'лев' => 'льв'];

	public static function fold(string $s): string
	{
		return str_replace(['ё', 'Ё'], ['е', 'Е'], $s);
	}

	public static function ends(string $w, string $suffix): bool
	{
		return mb_substr($w, -mb_strlen($suffix)) === $suffix;
	}

	private static function cut(string $w, int $n): string
	{
		return mb_substr($w, 0, mb_strlen($w) - $n);
	}

	private static function last(string $w): string
	{
		return mb_substr($w, -1);
	}

	/** Как существительные на -а/-я: Никита, Анна, Илья, Глинка. */
	private static function aDecl(string $w): array
	{
		$s = self::cut($w, 1);
		if (self::ends($w, 'я'))
		{
			return [$w, $s . 'и', $s . 'е', $s . 'ю', $s . 'ей', $s . 'е'];
		}
		$l = self::last($s);
		return [$w, $s . (in_array($l, self::SIB, true) ? 'и' : 'ы'), $s . 'е', $s . 'у', $s . (in_array($l, self::HUSH, true) ? 'ей' : 'ой'), $s . 'е'];
	}

	/** Мужское на согласный, -й, -ь. */
	private static function mDecl(string $w, ?string $stem = null): array
	{
		if (self::ends($w, 'й') || self::ends($w, 'ь'))
		{
			$s = self::cut($w, 1);
			return [$w, $s . 'я', $s . 'ю', $s . 'я', $s . 'ем', $s . 'е'];
		}
		$s = $stem ?? $w;
		return [$w, $s . 'а', $s . 'у', $s . 'а', $s . (in_array(self::last($s), self::HUSH, true) ? 'ем' : 'ом'), $s . 'е'];
	}

	/** $kind: l — фамилия, f — имя, p — отчество. Не кириллица — не склоняется. */
	public static function decline(string $w, string $kind, bool $fem): array
	{
		$same = [$w, $w, $w, $w, $w, $w];
		if (!preg_match('/^[а-яё]+(-[а-яё]+)?$/u', $w))
		{
			return $same;
		}
		if (strpos($w, '-') !== false)
		{
			[$a, $b] = explode('-', $w, 2);
			$da = self::decline($a, $kind, $fem);
			$db = self::decline($b, $kind, $fem);
			$out = [];
			for ($i = 0; $i < 6; $i++)
			{
				$out[] = $da[$i] . '-' . $db[$i];
			}
			return $out;
		}
		if ($kind === 'p')
		{
			if (!$fem && self::ends($w, 'ич'))
			{
				return [$w, $w . 'а', $w . 'у', $w . 'а', $w . 'ем', $w . 'е'];
			}
			if ($fem && self::ends($w, 'на'))
			{
				$s = self::cut($w, 1);
				return [$w, $s . 'ы', $s . 'е', $s . 'у', $s . 'ой', $s . 'е'];
			}
			return $same;
		}
		if ($kind === 'f')
		{
			if ($fem)
			{
				if (self::ends($w, 'ия'))
				{
					$s = self::cut($w, 1);
					return [$w, $s . 'и', $s . 'и', $s . 'ю', $s . 'ей', $s . 'и'];
				}
				if (self::ends($w, 'а') || self::ends($w, 'я'))
				{
					return self::aDecl($w);
				}
				if (self::ends($w, 'ь'))
				{
					$s = self::cut($w, 1);
					return [$w, $s . 'и', $s . 'и', $w, $s . 'ью', $s . 'и'];
				}
				return $same;
			}
			if (isset(self::FLEETING[$w]))
			{
				return self::mDecl($w, self::FLEETING[$w]);
			}
			if (self::ends($w, 'ий'))
			{
				$s = self::cut($w, 1);
				return [$w, $s . 'я', $s . 'ю', $s . 'я', $s . 'ем', $s . 'и'];
			}
			if (self::ends($w, 'а') || self::ends($w, 'я'))
			{
				return self::aDecl($w);
			}
			return preg_match('/[оеиуэюы]$/u', $w) ? $same : self::mDecl($w);
		}
		if ($fem)
		{
			if (preg_match('/(ов|ев|ёв|ин|ын)а$/u', $w))
			{
				$s = self::cut($w, 1);
				return [$w, $s . 'ой', $s . 'ой', $s . 'у', $s . 'ой', $s . 'ой'];
			}
			if (self::ends($w, 'ая'))
			{
				$s = self::cut($w, 2);
				return [$w, $s . 'ой', $s . 'ой', $s . 'ую', $s . 'ой', $s . 'ой'];
			}
			return self::ends($w, 'а') || self::ends($w, 'я') ? self::aDecl($w) : $same;
		}
		if (preg_match('/(ов|ев|ёв|ин|ын)$/u', $w))
		{
			return [$w, $w . 'а', $w . 'у', $w . 'а', $w . 'ым', $w . 'е'];
		}
		if (preg_match('/(ий|ой|ый)$/u', $w) && mb_strlen($w) > 4)
		{
			$s = self::cut($w, 2);
			$ins = in_array(self::last($s), self::SIB, true) || self::ends($w, 'ий') ? 'им' : 'ым';
			return [$w, $s . 'ого', $s . 'ому', $s . 'ого', $s . $ins, $s . 'ом'];
		}
		if (preg_match('/(ых|их|[оеиуэюы])$/u', $w))
		{
			return $same;
		}
		return self::ends($w, 'а') || self::ends($w, 'я') ? self::aDecl($w) : self::mDecl($w);
	}

	/**
	 * Разбор слова (нижний регистр, ё→е): [лемма, женск., падеж] или null.
	 * $accept($lemma, $fem) отсекает кандидатов до склонения (словарь, список имён, окончания).
	 */
	/** Возможные начальные формы слова: отсечь до 3 букв окончания и подставить окончание им. падежа. */
	private static function lemmas(string $w): array
	{
		// предохранитель: ФИО-слов длиннее 40 букв не бывает, а длинные слова дороги в разборе
		if (mb_strlen($w) > 40)
		{
			return [];
		}
		// Латиница в русском тексте не склоняется: только точное совпадение (имя из словаря так и находится).
		// Иначе слаг ссылки «postavka-meditsinskikh-izdeliy» давал десятки тысяч «основ».
		if (!preg_match('/\p{Cyrillic}/u', $w))
		{
			return [$w];
		}
		if (strpos($w, '-') !== false)
		{
			// двойная фамилия — ровно две части по 2+ буквы, обе в одном падеже. Из трёх частей и больше людей
			// не бывает («из-за-того-что»), а перемножение вариантов частей растёт как 44^k и выедает память.
			$parts = explode('-', $w);
			if (count($parts) !== 2 || mb_strlen($parts[0]) < 2 || mb_strlen($parts[1]) < 2)
			{
				return [];
			}
			[$a, $b] = $parts;
			$out = [];
			foreach (self::lemmas($a) as $la)
			{
				foreach (self::lemmas($b) as $lb)
				{
					$out[] = $la . '-' . $lb;
				}
			}
			return $out;
		}
		$len = mb_strlen($w);
		$lemmas = [];
		for ($cut = 0; $cut <= min(3, $len - 2); $cut++)
		{
			$stem = mb_substr($w, 0, $len - $cut);
			foreach (['', 'а', 'я', 'ий', 'ой', 'ый', 'ая', 'ь', 'й', 'ич', 'на'] as $e)
			{
				$lemmas[$stem . $e] = true;
			}
		}
		foreach (['павл' => 'павел', 'петр' => 'петр', 'льв' => 'лев'] as $stem => $lemma)
		{
			if (mb_strpos($w, $stem) === 0)
			{
				$lemmas[$lemma] = true;
			}
		}
		return array_map('strval', array_keys($lemmas));
	}

	public static function parse(string $w, string $kind, callable $accept): ?array
	{
		$lemmas = array_flip(self::lemmas($w));
		$best = null;
		$bestScore = PHP_INT_MAX;
		foreach (array_keys($lemmas) as $lemma)
		{
			$lemma = (string)$lemma;
			foreach ([false, true] as $fem)
			{
				if (!$accept($lemma, $fem))
				{
					continue;
				}
				$forms = array_map([self::class, 'fold'], self::decline($lemma, $kind, $fem));
				$c = array_search($w, $forms, true);
				if ($c === false)
				{
					continue;
				}
				// типичная парадигма важнее «именительного по случайности»: Ивановым — это Иванов, а не «Ивановой»
				$typical = $kind !== 'l' || preg_match('/((ов|ев|ин|ын)а?|(ск|цк)(ий|ая))$/u', $lemma);
				$score = ($typical ? 0 : 4) + ($c === 0 ? 0 : 1) - mb_strlen($lemma) / 100;
				if ($score < $bestScore)
				{
					$bestScore = $score;
					$best = [$lemma, $fem, (int)$c];
				}
			}
		}
		return $best;
	}

	public static function surnameFem(string $w): bool
	{
		return (bool)preg_match('/((ов|ев|ёв|ин|ын)а|ая)$/u', $w);
	}

	public static function surnameKey(string $w): string
	{
		if (preg_match('/(ов|ев|ёв|ин|ын)а$/u', $w))
		{
			return self::cut($w, 1);
		}
		return preg_match('/(ск|цк)ая$/u', $w) ? self::cut($w, 2) . 'ий' : $w;
	}

	public static function patrKey(string $w): string
	{
		if (self::ends($w, 'вна'))
		{
			return self::cut($w, 2) . 'ич';
		}
		return self::ends($w, 'чна') ? self::cut($w, 2) : $w;
	}

	public static function firstFem(string $w): bool
	{
		$l = self::last($w);
		return ($l === 'а' || $l === 'я') && !in_array($w, DrAnon::MALE_A, true);
	}

	/** Регистр $fake как у $orig: Иванов / ИВАНОВ / иванов. */
	public static function likeCase(string $fake, string $orig): string
	{
		if (mb_strlen($orig) > 1 && mb_strtoupper($orig) === $orig)
		{
			return mb_strtoupper($fake);
		}
		if (mb_strtolower($orig) === $orig)
		{
			return mb_strtolower($fake);
		}
		return (string)preg_replace_callback('/(^|-)(\p{Ll})/u', static function ($m) {
			return $m[1] . mb_strtoupper($m[2]);
		}, mb_strtolower($fake));
	}
}

/**
 * Двойники в PHP — для людей, которых нет в словаре, и для e-mail/телефонов в тексте.
 * e-mail и телефон считаются той же функцией, что SQL DrAnon: адрес в карточке и в комментарии совпадёт.
 */
final class DrFakes
{
	private $salt;

	public function __construct(string $salt)
	{
		$this->salt = $salt;
	}

	public function idx(string $tag, string $norm, int $n): int
	{
		return (int)(hexdec(substr(md5($this->salt . ':' . $tag . ':' . $norm), 0, 8)) % $n);
	}

	/** Ключ основы в словаре (как SQL в dr_dict_sql). */
	public function key(string $lemma): string
	{
		return md5($this->salt . ':k:' . $lemma);
	}

	/** Номер двойника, не совпадающего с оригиналом (как DrAnon::idxNot в SQL): при совпадении — следующий. */
	private function not(array $list, string $tag, string $key): int
	{
		$i = $this->idx($tag, $key, count($list));
		return DrName::fold(mb_strtolower($list[$i])) === $key ? ($i + 1) % count($list) : $i;
	}

	public function first(string $lemma): array
	{
		$fem = DrName::firstFem($lemma);
		$list = $fem ? DrAnon::FIRST_F : DrAnon::FIRST_M;
		return [mb_strtolower($list[$this->not($list, 'f', $lemma)]), $fem];
	}

	public function last(string $lemma, bool $fem): string
	{
		$l = DrAnon::LAST_M[$this->not(DrAnon::LAST_M, 'l', DrName::surnameKey($lemma))];
		return mb_strtolower($l) . ($fem ? 'а' : '');
	}

	public function patr(string $lemma, bool $fem): string
	{
		$i = $this->not(DrAnon::PATR_M, 'p', DrName::patrKey($lemma));
		return mb_strtolower($fem ? DrAnon::patrF()[$i] : DrAnon::PATR_M[$i]);
	}

	public function email(string $e): string
	{
		return 'u' . substr(md5($this->salt . ':e:' . mb_strtolower(trim($e, ' '))), 0, 10) . '@example.test';
	}

	public function phone(string $raw): string
	{
		$d = substr((string)preg_replace('/\D/', '', $raw), -10);
		return '+7000' . str_pad((string)(hexdec(substr(md5($this->salt . ':p:' . $d), 0, 7)) % 10000000), 7, '0', STR_PAD_LEFT);
	}

	public function initials(string $skey, bool $fem, string $i1, string $i2): array
	{
		$h = $this->idx('i', $skey . '|' . $i1 . $i2, 1000003);
		$fl = $fem ? DrAnon::FIRST_F : DrAnon::FIRST_M;
		$pl = $fem ? DrAnon::patrF() : DrAnon::PATR_M;
		return [mb_substr($fl[$h % count($fl)], 0, 1), mb_substr($pl[intdiv($h, 7) % count($pl)], 0, 1)];
	}
}

/**
 * Чистка текста в режиме «по словарю».
 * Слой 1 (всегда): e-mail, телефоны, ИНН-12/СНИЛС/карты с контрольной суммой, паспорт и дата рождения у ключевых слов, адрес, ссылки на соцсети.
 * Слой 2 (имена): люди из словаря базы и «группы имени» (Имя + Фамилия, Фамилия И. О., ИП …) — замена двойником в том же падеже.
 */
final class DrCleaner
{
	const RX_TOKEN = '/(?<![\p{L}\d&])(?:\p{Lu}\.(?:[ \x{00A0}]?\p{Lu}\.){0,2}(?![\p{Ll}\d])|\p{L}+(?:-\p{L}+)*(?![\p{L}\d]))/u';
	const RX_SURNAME = '/((ов|ев|ин|ын)а?|(ск|цк)(ий|ая)|енко|ко|ук|юк|чук|ян|янц|швили|дзе|ых|их)$/u';
	const RX_SURNAME_FORM = '/((ов|ев|ин|ын)(а|у|ым|ой|е|ых)?|(ск|цк)(ий|ого|ому|им|ом|ая|ой|ую))$/u';

	/** @var DrFakes */
	private $fk;
	/** ключ основы . вид (l|f|p) . род (0|1) => двойник в им. падеже — плоско: на большой базе это сотни тысяч строк */
	private $dict;
	private $names;
	private $single;
	private $known = [];
	private $stop;
	private $geoAfter;
	private $geoBefore;
	private $fakeLemmas = [];
	private $info = [];
	private $keys = [];
	/** Режим проверки утечек: двойники из наших списков — не утечка. */
	public $ignoreFakes = false;
	/** Счётчики сработавших правил: email, phone, doc, addr, url, name. */
	public $hits = [];
	/** Слова, принятые за имя при последнем вызове names(). */
	private $found = [];
	private $collect = false;
	/** Разбор фамилии в роде и падежах группы: слово|род|падежи → [из словаря, любой]. */
	private $gparse = [];

	public function __construct(DrFakes $fk, array $dict, bool $names = true, bool $single = true)
	{
		$this->fk = $fk;
		$this->dict = $dict;
		$this->names = $names;
		$this->single = $single;
		foreach (explode(' ', DR_KNOWN_FIRST_M) as $n)
		{
			$this->known[$n] = false;
		}
		foreach (explode(' ', DR_KNOWN_FIRST_F) as $n)
		{
			$this->known[$n] = true;
		}
		$this->stop = array_flip(explode(' ', DR_NAME_STOP));
		$this->geoAfter = array_flip(explode(' ', DR_GEO_AFTER));
		$this->geoBefore = array_flip(explode(' ', DR_GEO_BEFORE));
		foreach (array_merge(DrAnon::FIRST_M, DrAnon::FIRST_F, DrAnon::LAST_M, DrAnon::lastF(), DrAnon::PATR_M, DrAnon::patrF()) as $f)
		{
			$this->fakeLemmas[DrName::fold(mb_strtolower($f))] = true;
		}
	}

	public function setNames(bool $on): void
	{
		$this->names = $on;
	}

	private function hit(string $k): void
	{
		$this->hits[$k] = ($this->hits[$k] ?? 0) + 1;
	}

	public function clean(string $t): string
	{
		if ($t === '')
		{
			return $t;
		}
		$t = $this->documents($t);
		return $this->names ? $this->people($t, false) : $t;
	}

	/** Значение — это человек целиком (ФИО-поля: директор в реквизитах, автор сообщения). */
	public function person(string $v): string
	{
		if ($v === '' || preg_match('/[\d@]/', $v) || substr_count(trim($v), ' ') > 3 || preg_match('/^\s*(ИП|КФХ)\s/u', $v))
		{
			return $this->clean($v);
		}
		return $this->people($v, true);
	}

	/** Только контакты и документы (поле «оставить» на странице «Поля клиента»). */
	public function contacts(string $t): string
	{
		return $t === '' ? $t : $this->documents($t);
	}

	/** Проверка утечек: слова, похожие на имена людей (двойники из наших списков не считаются при ignoreFakes). */
	public function names(string $t): array
	{
		$this->found = [];
		$this->collect = true;
		if ($t !== '')
		{
			$this->people($t, false);
		}
		$this->collect = false;
		return $this->found;
	}

	// --- слой 1: документы и контакты -------------------------------------------------

	private function rnd(string $s): string
	{
		return (string)preg_replace_callback('/\d/', static function () {
			return (string)random_int(0, 9);
		}, $s);
	}

	private function documents(string $t): string
	{
		$fk = $this->fk;
		// буквы любые, не только латиница: адрес на .рф, кириллическая «а» в «mаil.ru» — проверка утечек их видит, значит и чистка должна
		$t = (string)preg_replace_callback('/[\p{L}\p{N}._%+-]+@[\p{L}\p{N}.-]+\.\p{L}{2,}/u', function ($m) use ($fk) {
			if (stripos($m[0], '@example.test') !== false)
			{
				return $m[0];
			}
			$this->hit('email');
			return $fk->email($m[0]);
		}, $t);
		$t = (string)preg_replace_callback('~https?://(?:www\.|m\.)?(?:vk\.com|vk\.ru|t\.me|telegram\.me|instagram\.com|ok\.ru|facebook\.com|fb\.com|wa\.me|api\.whatsapp\.com|hh\.ru/resume|hh\.ru/applicant)[^\s"\'<>\]]*~iu', function () {
			$this->hit('url');
			return 'https://example.test/';
		}, $t);
		// телефоны: +7 / 8 с кодом 3–5 цифр (11 цифр всего), без разделителей, +<код страны> (10–15 цифр)
		$t = (string)preg_replace_callback('/(?<![\d+])(?:\+\d[\d ()\-\x{00A0}]{8,20}\d|8[ (\-\x{00A0}]*[3-9]\d{2,4}[ )\-\x{00A0}]*\d{1,3}[ \-\x{00A0}]*\d{2}[ \-\x{00A0}]*\d{2}|[78][3-9]\d{9})(?![\d])/u', function ($m) use ($fk) {
			$d = preg_replace('/\D/', '', $m[0]);
			$plus = $m[0][0] === '+';
			$ru = !$plus || strpos($m[0], '+7') === 0;
			if ($ru ? strlen($d) !== 11 : (strlen($d) < 10 || strlen($d) > 15))
			{
				return $m[0];
			}
			if (strpos($d, '7000') === 0 && $plus)
			{
				return $m[0]; // наш двойник
			}
			$this->hit('phone');
			return $ru ? $fk->phone($m[0]) : '+000' . substr(str_pad((string)$fk->idx('pi', $d, 1000000000), 9, '0', STR_PAD_LEFT), 0, max(7, strlen($d) - 3));
		}, $t);
		// мобильные без +7/8: «912 345-67-89», «(912) 345-67-89», «7 912 345-67-89», «7(912)3456789» — только с разделителями, иначе это может быть номер заказа
		$t = (string)preg_replace_callback('/(?<![\d+\-])(?:7[ \x{00A0}]*\(9\d{2}\)[ \-\x{00A0}]?\d{3}[ \-\x{00A0}]?\d{2}[ \-\x{00A0}]?\d{2}|(?:7[ \x{00A0}]+)?\(?9\d{2}\)?[ \-\x{00A0}]?\d{3}[ \-\x{00A0}]\d{2}[ \-\x{00A0}]\d{2})(?![\d\-])/u', function ($m) use ($fk) {
			$this->hit('phone');
			return $fk->phone($m[0]);
		}, $t);
		// числа с контрольной суммой: ИНН физлица (12), СНИЛС (11), карта (13–19, Луна)
		$t = (string)preg_replace_callback('/(?<![\d\-+])\d(?:[ \-]?\d){10,19}(?![\d\-])/', function ($m) use ($t) {
			$d = preg_replace('/\D/', '', $m[0][0]);
			$n = strlen($d);
			// штрихкоды и длинные номера заказов проходят Луну случайно: карта — группами по 4 или после слова «карта»
			$card = $n >= 13 && $n <= 19 && self::luhn($d) && preg_match('/^[2-6]/', $d)
				&& (preg_match('/^\d{4}([ \-]\d{4}){2,3}([ \-]?\d{1,3})?$/', $m[0][0])
					|| preg_match('/(карт\p{L}*|card|visa|master\p{L}*|мир)\P{L}{0,20}$/iu', ltrim(substr($t, max(0, $m[0][1] - 60), min(60, $m[0][1])), "\x80..\xBF")));
			$acc = $n === 20 && preg_match('/^(40[5-8]|42[0-3]|30[1-2])/', $d);
			if (($n === 12 && self::inn12($d)) || ($n === 11 && self::snils($d)) || $card || $acc)
			{
				$this->hit('doc');
				return $this->rnd($m[0][0]);
			}
			return $m[0][0];
		}, $t, -1, $cnt, PREG_OFFSET_CAPTURE);
		$t = (string)preg_replace_callback('/(паспорт\p{L}*|серия|сер\.)([^\d\n]{0,20})(\d{2}[ \x{00A0}]?\d{2})([^\d\n]{0,12})(\d{6})(?!\d)/iu', function ($m) {
			$this->hit('doc');
			return $m[1] . $m[2] . $this->rnd($m[3]) . $m[4] . $this->rnd($m[5]);
		}, $t);
		$t = (string)preg_replace_callback('/((?:дата\s+рождения|д\.\s?р\.|г\.\s?р\.|родил(?:ся|ась)|рожд\.?)\s*[:\-–]?\s*)(\d{1,2}[.\/\-]\d{1,2}[.\/\-]\d{2,4}|\d{4})/iu', function ($m) {
			$this->hit('doc');
			return $m[1] . (strlen($m[2]) === 4 ? '1980' : '01.01.1980');
		}, $t);
		$t = (string)preg_replace_callback('/(\d{1,2}[.\/]\d{1,2}[.\/]\d{2,4}|(?<!\d)\d{4})(\s*(?:г\.\s?р\.|года рождения))/iu', function ($m) {
			$this->hit('doc');
			return (strlen($m[1]) === 4 ? '1980' : '01.01.1980') . $m[2];
		}, $t);
		$t = (string)preg_replace_callback('/(?<!\p{L})(ул\.|улица|пр-т|просп\.|проспект|пер\.|переулок|б-р|бульвар|ш\.|шоссе|наб\.|набережная|мкр\.?|микрорайон)\s*[^\n,;<]{2,40}?,?\s*(?:д\.|дом)\s*\d+[\p{L}\/\d]*(?:\s*,?\s*(?:к\.|корп\.?|корпус|стр\.|строение|кв\.?|квартира|оф\.?|офис)\s*\d+\p{L}?)*/iu', function () {
			$this->hit('addr');
			return 'ул. Примерная, д. 1';
		}, $t);
		return $t;
	}

	private static function inn12(string $d): bool
	{
		$w1 = [7, 2, 4, 10, 3, 5, 9, 4, 6, 8];
		$w2 = [3, 7, 2, 4, 10, 3, 5, 9, 4, 6, 8];
		$s1 = 0;
		$s2 = 0;
		for ($i = 0; $i < 10; $i++)
		{
			$s1 += $w1[$i] * (int)$d[$i];
		}
		for ($i = 0; $i < 11; $i++)
		{
			$s2 += $w2[$i] * (int)$d[$i];
		}
		return $s1 % 11 % 10 === (int)$d[10] && $s2 % 11 % 10 === (int)$d[11];
	}

	private static function snils(string $d): bool
	{
		$s = 0;
		for ($i = 0; $i < 9; $i++)
		{
			$s += (int)$d[$i] * (9 - $i);
		}
		$c = $s < 100 ? $s : ($s % 101 === 100 ? 0 : $s % 101);
		return $c === (int)substr($d, 9, 2) && (int)substr($d, 0, 9) > 1001998;
	}

	private static function luhn(string $d): bool
	{
		$s = 0;
		$alt = false;
		for ($i = strlen($d) - 1; $i >= 0; $i--)
		{
			$n = (int)$d[$i];
			if ($alt && ($n *= 2) > 9)
			{
				$n -= 9;
			}
			$s += $n;
			$alt = !$alt;
		}
		return $s % 10 === 0;
	}

	// --- слой 2: люди ---------------------------------------------------------------

	private function inDict(string $lemma, string $kind, bool $fem): ?string
	{
		$k = $this->keys[$lemma] ?? ($this->keys[$lemma] = $this->fk->key($lemma));
		$v = $this->dict[$k . $kind . (int)$fem] ?? null;
		if ($v !== null && $this->ignoreFakes && isset($this->fakeLemmas[$lemma]))
		{
			return null;
		}
		return $v;
	}

	/** Что известно о слове (кеш по слову). */
	private function info(string $w): array
	{
		$f = DrName::fold(mb_strtolower($w));
		if (isset($this->info[$f]))
		{
			return $this->info[$f];
		}
		if (count($this->info) > 50000)
		{
			$this->info = [];
			$this->keys = [];
			$this->gparse = [];
		}
		$d = [];
		foreach (['l', 'f', 'p'] as $kind)
		{
			$d[$kind] = DrName::parse($f, $kind, function ($l, $fem) use ($kind) {
				return $this->inDict($l, $kind, $fem) !== null;
			});
		}
		$known = $this->known;
		$fakes = $this->ignoreFakes ? $this->fakeLemmas : [];
		$h = ['f' => null, 'p' => null, 'l' => null];
		if (!isset($this->stop[$f]))
		{
			$h['f'] = $d['f'] ? null : DrName::parse($f, 'f', static function ($l, $fem) use ($known, $fakes) {
				return isset($known[$l]) && $known[$l] === $fem && !isset($fakes[$l]);
			});
			$h['p'] = $d['p'] ? null : DrName::parse($f, 'p', static function ($l, $fem) use ($fakes) {
				return mb_strlen($l) > 4 && !isset($fakes[$l]) && (bool)preg_match($fem ? '/(овна|евна|ична)$/u' : '/(ович|евич|ьич)$/u', $l);
			});
			$h['l'] = $d['l'] ? null : DrName::parse($f, 'l', static function ($l, $fem) use ($fakes) {
				return mb_strlen($l) > 3 && !isset($fakes[$l]) && (bool)preg_match(DrCleaner::RX_SURNAME, $l);
			});
		}
		// проверка утечек: двойник из наших списков (в любом падеже) — не человек
		$fake = false;
		if ($this->ignoreFakes)
		{
			$isFake = function ($l) {
				return isset($this->fakeLemmas[$l]);
			};
			$fake = isset($this->fakeLemmas[$f]) || DrName::parse($f, 'l', $isFake) || DrName::parse($f, 'f', $isFake) || DrName::parse($f, 'p', $isFake);
		}
		return $this->info[$f] = ['f' => $f, 'd' => $d, 'h' => $h, 'fake' => $fake];
	}

	private function gap(string $t, array $a, array $b): bool
	{
		$s = substr($t, $a['o'] + strlen($a['w']), $b['o'] - $a['o'] - strlen($a['w']));
		if ($s === '')
		{
			return $a['init']; // «И.И.Иванов»
		}
		return (bool)preg_match('/^(?:[ \x{00A0}]|&nbsp;){1,3}$/u', $s);
	}

	private function people(string $t, bool $force): string
	{
		if (!preg_match_all(self::RX_TOKEN, $t, $m, PREG_OFFSET_CAPTURE))
		{
			return $t;
		}
		$tokens = [];
		foreach ($m[0] as $mm)
		{
			$w = $mm[0];
			$first = mb_substr($w, 0, 1);
			$tokens[] = ['w' => $w, 'o' => $mm[1], 'init' => strpos($w, '.') !== false, 'cap' => $first !== mb_strtolower($first)];
		}
		$n = count($tokens);
		$repl = [];
		if ($force)
		{
			$g = [];
			foreach ($tokens as $i => $tk)
			{
				$g[] = $i;
			}
			$repl = $this->group($t, $tokens, array_slice($g, 0, 4), true);
		}
		else
		{
			$ip = false;
			for ($i = 0; $i < $n; $i++)
			{
				$tk = $tokens[$i];
				if ($tk['w'] === 'ИП' || $tk['w'] === 'КФХ')
				{
					// «ИП Иванов И. И.», «Глава КФХ Петров»: следующие слова с заглавной — человек
					$ip = $i + 1 < $n && $this->gap($t, $tk, $tokens[$i + 1]);
					continue;
				}
				if (!$tk['cap'])
				{
					$ip = false;
					// строчная фамилия из словаря («позвони иванову») — только одиночным правилом
					if ($this->single && mb_strlen($tk['w']) >= 5 && preg_match('/((ов|ев|ин|ын)(а|у|ым|ой)|(ск|цк)(ого|ому|им|ую))$/u', $tk['w']))
					{
						$repl += $this->group($t, $tokens, [$i], false);
					}
					continue;
				}
				$g = [$i];
				while ($i + 1 < $n && count($g) < 4 && $tokens[$i + 1]['cap'] && !in_array($tokens[$i + 1]['w'], ['ИП', 'КФХ'], true)
					&& $this->gap($t, $tokens[$i], $tokens[$i + 1]))
				{
					$g[] = ++$i;
				}
				$repl += $this->group($t, $tokens, $g, $ip);
				$ip = false;
			}
		}
		if (!$repl)
		{
			return $t;
		}
		krsort($repl);
		foreach ($repl as $off => $r)
		{
			$t = substr_replace($t, $r[1], $off, $r[0]);
		}
		return $t;
	}

	/** Слово рядом с гео-словом («Орловская область», «г. Королёв») — не человек. */
	private function geo(string $t, array $tokens, int $idx): bool
	{
		$next = $tokens[$idx + 1] ?? null;
		if ($next && isset($this->geoAfter[mb_strtolower(rtrim($next['w'], '.'))]))
		{
			return true;
		}
		$before = substr($t, max(0, $tokens[$idx]['o'] - 8), min(8, $tokens[$idx]['o']));
		return (bool)preg_match('/(?:^|[^\p{L}])(\p{L}{1,3}|р-н)\.?\s*$/u', $before, $bm) && isset($this->geoBefore[mb_strtolower($bm[1])]);
	}

	/** Замены в группе слов: [смещение => [длина, новое]]. */
	private function group(string $t, array $tokens, array $g, bool $force): array
	{
		$info = [];
		$init = false;
		foreach ($g as $idx)
		{
			$w = $tokens[$idx]['w'];
			// «Иванов ИИ» — две-три прописные после слова считаем инициалами
			if ($tokens[$idx]['init'] || ($idx !== $g[0] && preg_match('/^[А-ЯЁ]{2,3}$/u', $w)
				&& !preg_match('/(ск|цк)(ий|ой|ая|ое|ого|ому|ом)$/u', mb_strtolower($tokens[$g[0]]['w']))))
			{
				$info[$idx] = null;
				$init = true;
				continue;
			}
			$x = $this->info($w);
			if ($x['fake'])
			{
				continue;
			}
			$info[$idx] = $x;
		}
		$any = static function (string $kind, bool $dictOnly) use ($info): bool {
			foreach ($info as $x)
			{
				if ($x !== null && ($x['d'][$kind] || (!$dictOnly && $x['h'][$kind])))
				{
					return true;
				}
			}
			return false;
		};
		$words = count(array_filter($info));
		$P = $any('p', false);
		$F = $any('f', false);
		$Ld = $any('l', true);
		$L = $any('l', false);
		$person = ($force && $words > 0)
			|| ($P && ($F || $L))
			|| ($init && $L)
			|| ($words >= 2 && $F && $L);
		$roles = [];
		if ($person)
		{
			$keys = array_keys($info);
			foreach ($keys as $pos => $idx)
			{
				$x = $info[$idx];
				if ($x === null)
				{
					$roles[$idx] = 'i';
					continue;
				}
				$next = $info[$keys[$pos + 1] ?? -1] ?? null;
				$isP = $x['d']['p'] || ($x['h']['p'] && !$x['d']['f'] && !$x['d']['l']);
				$isF = $x['d']['f'] || $x['h']['f'];
				$isL = $x['d']['l'] || $x['h']['l'];
				$nextP = $next && ($next['d']['p'] || $next['h']['p']);
				$nextF = $next && ($next['d']['f'] || $next['h']['f']);
				$taken = array_count_values($roles);
				if ($isP && !($isF && $nextP))
				{
					$roles[$idx] = 'p';
				}
				elseif ($isF && empty($taken['f']) && ($nextP || !$isL || (!$nextF && empty($taken['l']) && $next !== null)))
				{
					$roles[$idx] = 'f';
				}
				elseif ($isL || $isF || $force)
				{
					$roles[$idx] = 'l';
				}
			}
		}
		elseif ($this->single && $Ld)
		{
			// не группа имени: одиночная фамилия из словаря («Звонок Иванову»)
			foreach ($info as $idx => $x)
			{
				if ($x !== null && $x['d']['l'] && mb_strlen($x['f']) >= 5 && !isset($this->stop[$x['f']]) && !isset($this->known[$x['f']])
					&& !$this->geo($t, $tokens, $idx))
				{
					$roles[$idx] = 'l';
				}
			}
		}
		if (!$roles)
		{
			return [];
		}
		$out = [];
		$fem = false;
		$skey = '';
		// род и падежи группы — по отчеству, иначе по имени: у «Гончаренко» их по самой фамилии не видно,
		// а словарь мог знать только однофамилицу («Анна Гончаренко»)
		$gf = null;
		$gcs = [];
		foreach ($roles as $idx => $r)
		{
			$q = ($r === 'p' || $r === 'f') ? ($info[$idx]['d'][$r] ?: $info[$idx]['h'][$r]) : null;
			if ($q)
			{
				$gf = (bool)$q[1];
				$gcs = array_keys(array_map([DrName::class, 'fold'], DrName::decline($q[0], $r, $gf)), $info[$idx]['f'], true);
				if ($r === 'p')
				{
					break;
				}
			}
		}
		foreach ($roles as $idx => $r)
		{
			if ($r === 'i')
			{
				continue;
			}
			$x = $info[$idx];
			$p = $x['d'][$r] ?? null;
			$own = null;
			if ($r === 'l' && $gf !== null)
			{
				// разбор фамилии в роде и падеже группы; мужчина «Сидорова» при «Ивана Петровича» — родительный от «Сидоров»
				$w = $x['f'];
				$acc = static function ($l, $fem) use ($gf, $gcs, $w) {
					if ($fem !== $gf || (!$gf && DrName::surnameFem($l)))
					{
						return false;
					}
					$forms = array_map([DrName::class, 'fold'], DrName::decline($l, 'l', $fem));
					foreach ($gcs as $c)
					{
						if ($forms[$c] === $w)
						{
							return true;
						}
					}
					return !$gcs;
				};
				$ck = $w . '|' . (int)$gf . '|' . implode(',', $gcs);
				if (!isset($this->gparse[$ck]))
				{
					$q = DrName::parse($w, 'l', function ($l, $fem) use ($acc) {
						return $acc($l, $fem) && $this->inDict($l, 'l', $fem) !== null;
					});
					$this->gparse[$ck] = [$q, $q ?? DrName::parse($w, 'l', $acc)];
				}
				[$q, $own] = $this->gparse[$ck];
				if ($own)
				{
					$forms = array_map([DrName::class, 'fold'], DrName::decline($own[0], 'l', $own[1]));
					foreach ($gcs as $c)
					{
						if ($forms[$c] === $w)
						{
							$own[2] = $c;
							break;
						}
					}
					$p = $q ? $own : null;
				}
			}
			$fake = null;
			if ($p)
			{
				$fake = $this->inDict($p[0], $r, $p[1]);
			}
			if ($fake === null)
			{
				$p = $own ?? $x['h'][$r] ?? null;
				if ($p === null)
				{
					$p = DrName::parse($x['f'], $r, static function () {
						return true;
					}) ?? [$x['f'], $r === 'l' && DrName::surnameFem($x['f']), 0];
				}
				$fake = $r === 'l' ? $this->fk->last($p[0], $p[1]) : ($r === 'f' ? $this->fk->first($p[0])[0] : $this->fk->patr($p[0], $p[1]));
			}
			[$lemma, $pf, $case] = $p;
			// род двойника — по нему самому: у несклоняемой фамилии («Шевченко») род формы ничего не говорит
			$fake = mb_strtolower($fake);
			if ($r === 'l' && $gf !== null)
			{
				// у несклоняемой фамилии одна строка словаря на обоих: род двойника — по группе
				$fake = (string)preg_replace('/(ов|ев|ёв|ин|ын)а$/u', '$1', $fake);
				$fake .= $gf && preg_match('/(ов|ев|ёв|ин|ын)$/u', $fake) ? 'а' : '';
			}
			$ff = $r === 'l' ? DrName::surnameFem($fake) || ($pf && !preg_match('/(ов|ев|ин|ын)$/u', $fake))
				: ($r === 'p' ? DrName::ends($fake, 'на') : DrName::firstFem($fake));
			if ($r === 'l')
			{
				$fem = $ff;
				$skey = DrName::surnameKey($lemma);
			}
			$forms = DrName::decline($fake, $r, $ff);
			$this->hit('name');
			if ($this->collect)
			{
				$this->found[] = $tokens[$idx]['w'];
			}
			$out[$tokens[$idx]['o']] = [strlen($tokens[$idx]['w']), DrName::likeCase($forms[$case] ?? $forms[0], $tokens[$idx]['w'])];
		}
		foreach ($roles as $idx => $r)
		{
			if ($r !== 'i' || $skey === '')
			{
				continue;
			}
			$w = $tokens[$idx]['w'];
			preg_match_all('/\p{Lu}/u', $w, $lm);
			$fi = $this->fk->initials($skey, $fem, $lm[0][0] ?? '', $lm[0][1] ?? '');
			$k = 0;
			$out[$tokens[$idx]['o']] = [strlen($w), (string)preg_replace_callback('/\p{Lu}/u', static function () use (&$k, $fi) {
				return $fi[$k++] ?? '';
			}, $w)];
		}
		return $out;
	}
}

// ============================================================================
// Правила: что отключаем и что обезличиваем
// ============================================================================

/**
 * Группа = единица выбора в интерфейсе и в профиле клиента.
 * Виды правил:
 *   ops       — список операций: ['sql', 'таблица[.КОЛОНКА]', SQL], ['option', модуль, имя, значение],
 *               ['truncate', таблица], ['options_secrets' (, список options_keep)], ['mail_stub'], ['smtp_off']
 *   truncate  — очистка таблиц (имена или шаблоны LIKE с %)
 *   table / tables_like + set — UPDATE колонок; where — условие с алиасом t; join — JOIN с алиасом
 *   columns_rx + method — колонка по имени (regex) в любой таблице
 *   uf_auto   — пользовательские поля по типу и коду
 * Несуществующие таблицы и колонки пропускаются.
 */
function dr_rules(array $cfg): array
{
	$keep = array_values(array_filter(array_map('intval', (array)$cfg['keep_user_ids'])));
	$notKept = $keep ? 't.`ID` NOT IN (' . implode(',', $keep) . ')' : '';
	$agentsWhere = "ACTIVE = 'Y'";
	foreach ((array)$cfg['agents_keep'] as $p)
	{
		$agentsWhere .= ' AND NAME NOT LIKE ' . dr_lit((string)$p);
	}
	$person = ['NAME' => 'first_name', 'LAST_NAME' => ['last_name', 'NAME'], 'SECOND_NAME' => ['patronymic', 'NAME']];
	$fullName = ['FULL_NAME' => ['expr', "TRIM(CONCAT_WS(' ', t.`NAME`, t.`LAST_NAME`))"]];
	$addr = ['ADDRESS' => 'address', 'ADDRESS_2' => 'empty', 'ADDRESS_POSTAL_CODE' => 'digits'];
	$saleProps = ['expr', "CASE WHEN p.`IS_EMAIL` = 'Y' THEN {m:email} WHEN p.`IS_PHONE` = 'Y' THEN {m:phone}"
		. " WHEN p.`IS_PROFILE_NAME` = 'Y' OR p.`IS_PAYER` = 'Y' THEN {m:full_name} WHEN p.`IS_ADDRESS` = 'Y' THEN {m:address}"
		. " WHEN p.`IS_ZIP` = 'Y' THEN {m:digits} WHEN p.`TYPE` IN ('STRING', 'TEXTAREA') THEN {m:text} ELSE {col} END"];
	$salePropsCols = ['IS_EMAIL', 'IS_PHONE', 'IS_PROFILE_NAME', 'IS_PAYER', 'IS_ADDRESS', 'IS_ZIP', 'TYPE'];
	$salePropsJoin = 'INNER JOIN `b_sale_order_props` p ON p.`ID` = t.`ORDER_PROPS_ID`';
	$rqInd = "(COALESCE(t.`ENTITY_TYPE_ID`, 0) = 3 OR CHAR_LENGTH(TRIM(COALESCE(t.`RQ_INN`, ''))) = 12)";
	$companyInd = 'EXISTS (SELECT 1 FROM `b_crm_requisite` r WHERE r.`ENTITY_TYPE_ID` = 4 AND r.`ENTITY_ID` = t.`ID` AND CHAR_LENGTH(TRIM(r.`RQ_INN`)) = 12)';

	return [
		// --- Отключение внешних действий: до обезличивания, чтобы ничего не ушло наружу ---
		['group' => 'Отключение: агенты', 'neutral' => true, 'ops' => [
			['sql', 'b_agent', "UPDATE b_agent SET ACTIVE = 'N' WHERE $agentsWhere"],
			['option', 'main', 'check_agents', 'N'],
		]],
		['group' => 'Отключение: отправка почты', 'neutral' => true, 'ops' => [
			['mail_stub'],
			['smtp_off'],
			['truncate', 'b_event'],
			['sql', 'b_main_mail_sender.OPTIONS', "UPDATE b_main_mail_sender SET OPTIONS = ''"],
		]],
		['group' => 'Отключение: REST-вебхуки и обработчики', 'neutral' => true, 'ops' => [
			['sql', 'b_rest_event.EVENT_HANDLER', "UPDATE b_rest_event SET EVENT_HANDLER = 'https://blocked.devrestore.invalid/'"],
			['truncate', 'b_rest_event_offline'],
		]],
		['group' => 'Отключение: push-сервер, кеш и сессии прода', 'neutral' => true, 'ops' => [
			['settings_dev'],
			['truncate', 'b_pull_channel'],
			['truncate', 'b_pull_push'],
			['truncate', 'b_pull_push_queue'],
		]],
		['group' => 'Отключение: SMS-провайдеры', 'neutral' => true, 'ops' => [
			['sql', 'b_option', "UPDATE b_option SET VALUE = '' WHERE MODULE_ID = 'messageservice' AND CHAR_LENGTH(VALUE) >= 8 AND VALUE NOT REGEXP '^[0-9]+$'"],
		]],
		['group' => 'Отключение: автозапуск бизнес-процессов (вебхуки в действиях БП)', 'neutral' => true, 'default_off' => true, 'ops' => [
			['sql', 'b_bp_workflow_template.AUTO_EXECUTE', 'UPDATE b_bp_workflow_template SET AUTO_EXECUTE = 0'],
		]],
		['group' => 'Отключение: почтовые ящики', 'neutral' => true, 'ops' => [
			['sql', 'b_mail_mailbox.ACTIVE', "UPDATE b_mail_mailbox SET ACTIVE = 'N'"],
		]],
		['group' => 'Отключение: коннекторы открытых линий', 'neutral' => true, 'ops' => [
			['sql', 'b_option', "DELETE FROM b_option WHERE MODULE_ID = 'imconnector'"],
			['truncate', 'b_imconnectors_status'],
		]],
		['group' => 'Отключение: облачные хранилища', 'neutral' => true, 'ops' => [
			['sql', 'b_clouds_file_bucket.SETTINGS', "UPDATE b_clouds_file_bucket SET SETTINGS = ''"],
			['sql', 'b_clouds_file_bucket.ACTIVE', "UPDATE b_clouds_file_bucket SET ACTIVE = 'N'"],
		]],
		['group' => 'Секреты: пароли, токены, ключи API', 'neutral' => true, 'ops' => [
			['options_secrets'],
			['sql', 'b_sale_bizval.PROVIDER_VALUE', "UPDATE b_sale_bizval SET PROVIDER_VALUE = '' WHERE CODE_KEY REGEXP 'PASSWORD|SECRET|TOKEN|API_KEY|PRIVATE_KEY|SECRET_KEY'"],
		]],
		['group' => 'Секреты: пароли, токены, ключи API', 'neutral' => true,
			'columns_rx' => '/^(OATOKEN|OASECRET)$|(^|_)(PASSWORD|PASSWD|SECRET|TOKEN|APIKEY|API_KEY|PRIVATE_KEY|SECRET_KEY|ACCESS_KEY)$/i',
			'method' => 'secret', 'min_len' => 8, 'exclude' => ['b_user', 'b_user_password', 'b_option', 'b_option_site']],
		['group' => 'Сессии, 2FA и токены входа', 'neutral' => true, 'truncate' => [
			'b_user_stored_auth', 'b_user_hit_auth', 'b_user_otp', 'b_user_password', 'b_user_auth_code', 'b_sec_session',
			'b_user_session', 'b_user_device', 'b_user_device_login', 'b_user_profile_history', 'b_user_profile_record',
			// эфемерные токены: доступ CalDAV/CardDAV и блокировки документов на редактировании — на копии не нужны
			'b_dav_tokens', 'b_disk_object_lock',
		]],
		['group' => 'Сессии, 2FA и токены входа', 'neutral' => true, 'ops' => [
			['option', 'security', 'otp_mandatory_using', 'N'],
		]],

		// --- Обезличивание ---
		['group' => 'Пользователи', 'table' => 'b_user', 'where' => $notKept, 'set' => $person + [
			'LOGIN' => 'login', 'EMAIL' => 'email', 'PASSWORD' => 'random_password', 'CHECKWORD' => 'empty', 'CONFIRM_CODE' => 'empty',
			'STORED_HASH' => 'empty', 'PERSONAL_PHONE' => 'phone', 'PERSONAL_MOBILE' => 'phone', 'PERSONAL_FAX' => 'phone',
			'PERSONAL_PAGER' => 'phone', 'WORK_PHONE' => 'phone', 'WORK_FAX' => 'phone', 'WORK_PAGER' => 'phone',
			'PERSONAL_BIRTHDAY' => 'null', 'PERSONAL_BIRTHDATE' => 'empty', 'PERSONAL_PHOTO' => 'null', 'PERSONAL_WWW' => 'url',
			'WORK_WWW' => 'url', 'PERSONAL_ICQ' => 'empty', 'PERSONAL_CITY' => 'empty', 'PERSONAL_STREET' => 'address', 'PERSONAL_MAILBOX' => 'empty',
			'PERSONAL_ZIP' => 'digits', 'PERSONAL_NOTES' => 'text', 'WORK_STREET' => 'address', 'WORK_MAILBOX' => 'empty',
			'WORK_ZIP' => 'digits', 'WORK_NOTES' => 'text', 'WORK_PROFILE' => 'text', 'WORK_COMPANY' => 'company', 'ADMIN_NOTES' => 'text',
		]],
		['group' => 'Пользователи', 'table' => 'b_user', 'where' => "t.`EXTERNAL_AUTH_ID` = 'imconnector'", 'set' => ['XML_ID' => 'im']],
		['group' => 'Пользователи', 'table' => 'b_user_index', 'set' => $person],
		['group' => 'Пользователи', 'table' => 'b_user_phone_auth', 'set' => ['PHONE_NUMBER' => 'phone', 'OTP_SECRET' => 'secret']],
		['group' => 'Пользователи', 'table' => 'b_socialservices_user', 'set' => $person + [
			'EMAIL' => 'email', 'LOGIN' => 'im', 'PERSONAL_PHOTO' => 'empty', 'PERSONAL_WWW' => 'url',
		]],
		['group' => 'Пользователи', 'table' => 'b_consent_user_consent', 'set' => ['IP' => 'ip']],

		['group' => 'CRM: контакты, компании, лиды', 'table' => 'b_crm_contact', 'set' => $person + $fullName + $addr + [
			'BIRTHDATE' => 'null', 'COMMENTS' => 'text', 'SOURCE_DESCRIPTION' => 'text', 'PHOTO' => 'null', 'POST' => 'text',
		]],
		['group' => 'CRM: контакты, компании, лиды', 'table' => 'b_crm_company', 'set' => $addr + [
			'ADDRESS_LEGAL' => 'address', 'BANKING_DETAILS' => 'text', 'COMMENTS' => 'text',
		]],
		// компания с ИНН из 12 цифр — ИП или физлицо: её название — это человек
		['group' => 'CRM: контакты, компании, лиды', 'table' => 'b_crm_company', 'where_if' => ['b_crm_requisite.ENTITY_TYPE_ID' => "NOT $companyInd"], 'set' => ['TITLE' => 'company']],
		['group' => 'CRM: контакты, компании, лиды', 'table' => 'b_crm_company', 'requires' => ['b_crm_requisite.ENTITY_TYPE_ID', 'b_crm_requisite.ENTITY_ID', 'b_crm_requisite.RQ_INN'], 'where' => $companyInd, 'set' => ['TITLE' => 'company_person']],
		['group' => 'CRM: контакты, компании, лиды', 'table' => 'b_crm_lead', 'set' => ['TITLE' => ['title', 'Лид']] + $person + $fullName + $addr + [
			'COMPANY_TITLE' => 'company', 'COMMENTS' => 'text', 'SOURCE_DESCRIPTION' => 'text', 'STATUS_DESCRIPTION' => 'text', 'BIRTHDATE' => 'null',
		]],

		['group' => 'CRM: сделки, предложения, смарт-процессы', 'table' => 'b_crm_deal', 'set' => [
			'TITLE' => ['title', 'Сделка'], 'COMMENTS' => 'text', 'SOURCE_DESCRIPTION' => 'text',
		]],
		['group' => 'CRM: сделки, предложения, смарт-процессы', 'table' => 'b_crm_quote', 'set' => [
			'TITLE' => ['title', 'Предложение'], 'COMMENTS' => 'text', 'CONTENT' => 'text', 'TERMS' => 'text', 'CLIENT_TITLE' => 'company',
			'CLIENT_ADDR' => 'address', 'CLIENT_CONTACT' => 'full_name', 'CLIENT_EMAIL' => 'email', 'CLIENT_PHONE' => 'phone',
			'CLIENT_TP_ID' => 'digits', 'CLIENT_TPA_ID' => 'digits',
		]],
		['group' => 'CRM: сделки, предложения, смарт-процессы', 'tables_like' => 'b_crm_dynamic_items_%', 'set' => [
			'TITLE' => ['title', 'Элемент'], 'COMMENTS' => 'text',
		]],
		// таблицы смарт-процессов, тип которых удалён из b_crm_dynamic_type: в интерфейсе их не открыть,
		// а UF без b_user_field правилам не видны — очищаем целиком вместе с таблицами множественных полей
		['group' => 'CRM: сделки, предложения, смарт-процессы', 'truncate_orphans' => true],

		['group' => 'CRM: средства связи, реквизиты, адреса', 'table' => 'b_crm_field_multi', 'set' => [
			'VALUE' => ['case', 'TYPE_ID', ['EMAIL' => 'email', 'PHONE' => 'phone', 'WEB' => 'url'], 'im'],
		]],
		// реквизиты контакта (ENTITY_TYPE_ID = 3) и с ИНН из 12 цифр — физлицо или ИП, юрлица — по настройке companies
		['group' => 'CRM: средства связи, реквизиты, адреса', 'table' => 'b_crm_requisite', 'where_if' => ['b_crm_requisite.ENTITY_TYPE_ID' => "NOT $rqInd"], 'set' => [
			'NAME' => 'company', 'RQ_COMPANY_NAME' => 'company', 'RQ_COMPANY_FULL_NAME' => 'company',
		]],
		['group' => 'CRM: средства связи, реквизиты, адреса', 'table' => 'b_crm_requisite', 'requires' => ['b_crm_requisite.ENTITY_TYPE_ID'], 'where' => $rqInd, 'set' => [
			'NAME' => 'company_person', 'RQ_COMPANY_NAME' => 'company_person', 'RQ_COMPANY_FULL_NAME' => 'company_person',
		]],
		['group' => 'CRM: средства связи, реквизиты, адреса', 'table' => 'b_crm_requisite', 'set' => [
			'RQ_NAME' => 'full_name', 'RQ_FIRST_NAME' => 'first_name', 'RQ_LAST_NAME' => ['last_name', 'RQ_FIRST_NAME'],
			'RQ_SECOND_NAME' => ['patronymic', 'RQ_FIRST_NAME'],
			'RQ_DIRECTOR' => 'full_name', 'RQ_ACCOUNTANT' => 'full_name', 'RQ_CEO_NAME' => 'full_name', 'RQ_CONTACT' => 'full_name',
			'RQ_EMAIL' => 'email', 'RQ_PHONE' => 'phone', 'RQ_FAX' => 'phone', 'RQ_IDENT_DOC_SER' => 'digits', 'RQ_IDENT_DOC_NUM' => 'digits',
			'RQ_IDENT_DOC_ISSUED_BY' => 'text', 'RQ_IDENT_DOC_DEP_CODE' => 'digits', 'RQ_IDENT_DOC_DATE' => 'null', 'RQ_INN' => 'inn',
			'RQ_KPP' => 'digits', 'RQ_OGRN' => 'digits', 'RQ_OGRNIP' => 'digits', 'RQ_OKPO' => 'digits', 'RQ_SNILS' => 'digits',
			'RQ_BIN' => 'digits', 'RQ_IIN' => 'digits', 'RQ_VAT_ID' => 'digits',
		]],
		['group' => 'CRM: средства связи, реквизиты, адреса', 'table' => 'b_crm_bank_detail', 'set' => [
			'RQ_ACC_NUM' => 'digits', 'RQ_IBAN' => 'digits', 'RQ_ACC_NAME' => 'company', 'COMMENTS' => 'text',
		]],
		['group' => 'CRM: средства связи, реквизиты, адреса', 'table' => 'b_crm_addr', 'set' => [
			'ADDRESS_1' => 'address', 'ADDRESS_2' => 'empty', 'POSTAL_CODE' => 'digits',
		]],
		// поля адреса модуля location: в ядре таблица называется b_location_addr_fld, старое имя оставлено на всякий случай
		['group' => 'CRM: средства связи, реквизиты, адреса', 'table' => 'b_location_addr_fld', 'set' => [
			'VALUE' => ['fixed', '—'], 'VALUE_NORMALIZED' => ['fixed', '—'],
		]],
		['group' => 'CRM: средства связи, реквизиты, адреса', 'table' => 'b_location_address_field', 'optional' => true, 'set' => [
			'VALUE' => ['fixed', '—'], 'VALUE_NORMALIZED' => ['fixed', '—'],
		]],
		['group' => 'CRM: средства связи, реквизиты, адреса', 'table' => 'b_location_address', 'set' => [
			'LATITUDE' => 'null', 'LONGITUDE' => 'null',
		]],

		// SETTINGS и PROVIDER_PARAMS — сериализованные настройки дела: внутри адреса писем, номера, имена участников
		['group' => 'CRM: дела, таймлайн, история', 'table' => 'b_crm_act', 'set' => [
			'SUBJECT' => ['title', 'Дело'], 'DESCRIPTION' => 'text', 'LOCATION' => 'text', 'SETTINGS' => 'ser_text', 'PROVIDER_PARAMS' => 'ser_text',
		]],
		['group' => 'CRM: дела, таймлайн, история', 'table' => 'b_crm_act_comm', 'set' => ['VALUE' => 'contact', 'ENTITY_SETTINGS' => 'empty']],
		['group' => 'CRM: дела, таймлайн, история', 'table' => 'b_crm_timeline', 'set' => ['COMMENT' => 'text', 'SETTINGS' => 'ser_text']],
		['group' => 'CRM: дела, таймлайн, история', 'table' => 'b_crm_button', 'set' => ['SETTINGS' => 'ser_text']],
		['group' => 'CRM: дела, таймлайн, история', 'table' => 'b_crm_timeline_note', 'set' => ['TEXT' => 'text']],
		['group' => 'CRM: дела, таймлайн, история', 'table' => 'b_call_outcome_property', 'set' => ['CONTENT' => 'text']],
		['group' => 'CRM: дела, таймлайн, история', 'table' => 'b_crm_event', 'set' => ['EVENT_TEXT_1' => 'text', 'EVENT_TEXT_2' => 'text']],

		['group' => 'Чаты и открытые линии', 'table' => 'b_im_message', 'set' => ['MESSAGE' => 'text', 'MESSAGE_OUT' => 'text']],
		['group' => 'Чаты и открытые линии', 'table' => 'b_im_message_param', 'set' => ['PARAM_JSON' => 'json_text']],
		['group' => 'Чаты и открытые линии', 'table' => 'b_im_chat', 'set' => ['TITLE' => ['title', 'Чат'], 'DESCRIPTION' => 'text']],
		// чат звонка: номер собеседника — тем же хешем, что и в статистике телефонии, связь звонка и чата сохраняется
		['group' => 'Чаты и открытые линии', 'table' => 'b_im_chat', 'requires' => ['b_im_chat.ENTITY_TYPE'], 'where' => "t.`ENTITY_TYPE` = 'CALL'", 'set' => [
			'CALL_NUMBER' => 'phone', 'ENTITY_ID' => 'phone',
		]],
		['group' => 'Чаты и открытые линии', 'table' => 'b_im_link_calendar', 'set' => ['CALENDAR_TITLE' => ['title', 'Событие']]],
		['group' => 'Чаты и открытые линии', 'truncate' => ['b_imopenlines_log']],

		['group' => 'Почта (письма)', 'table' => 'b_mail_message', 'set' => [
			'SUBJECT' => ['title', 'Письмо'], 'FIELD_FROM' => 'email', 'FIELD_REPLY_TO' => 'email', 'FIELD_TO' => 'email', 'FIELD_CC' => 'email',
			'FIELD_BCC' => 'email', 'BODY' => 'text', 'BODY_HTML' => 'text', 'HEADER' => 'text',
		]],
		['group' => 'Почта (письма)', 'table' => 'b_mail_mailbox', 'set' => ['LOGIN' => 'email', 'EMAIL' => 'email', 'USERNAME' => 'full_name']],
		['group' => 'Почта (письма)', 'table' => 'b_main_mail_sender', 'set' => ['EMAIL' => 'email', 'NAME' => 'full_name']],
		['group' => 'Почта (письма)', 'table' => 'b_mail_contact', 'set' => ['NAME' => 'full_name', 'EMAIL' => 'email']],

		['group' => 'Телефония и SMS', 'table' => 'b_voximplant_statistic', 'set' => [
			'PHONE_NUMBER' => 'contact', 'CALL_LOG' => 'url', 'CALL_RECORD_URL' => 'url', 'COMMENT' => 'text',
		]],
		['group' => 'Телефония и SMS', 'table' => 'b_voximplant_transcript_line', 'set' => ['MESSAGE' => 'text']],
		['group' => 'Телефония и SMS', 'table' => 'b_voximplant_phone', 'set' => ['PHONE_NUMBER' => 'phone']],
		['group' => 'Телефония и SMS', 'table' => 'b_voximplant_statistic_index', 'set' => ['CONTENT' => 'empty']],
		['group' => 'Телефония и SMS', 'table' => 'b_messageservice_message', 'set' => [
			'MESSAGE_TO' => 'contact', 'MESSAGE_BODY' => 'text', 'MESSAGE_HEADERS' => 'text',
		]],
		['group' => 'Телефония и SMS', 'truncate' => ['b_messageservice_incoming_message']],

		['group' => 'Живая лента, форумы, задачи', 'table' => 'b_forum_message', 'set' => [
			'POST_MESSAGE' => 'text', 'POST_MESSAGE_HTML' => 'text', 'POST_MESSAGE_FILTER' => 'text', 'AUTHOR_NAME' => 'full_name',
			'AUTHOR_EMAIL' => 'email', 'AUTHOR_IP' => 'ip', 'AUTHOR_REAL_IP' => 'ip', 'EDITOR_NAME' => 'full_name', 'EDITOR_EMAIL' => 'email',
			'EDIT_REASON' => 'text', 'SERVICE_DATA' => 'json_text',
		]],
		['group' => 'Живая лента, форумы, задачи', 'table' => 'b_forum_topic', 'set' => [
			'TITLE' => ['title', 'Тема'], 'DESCRIPTION' => 'text', 'USER_START_NAME' => 'full_name', 'LAST_POSTER_NAME' => 'full_name',
			'ABS_LAST_POSTER_NAME' => 'full_name', 'TITLE_SEO' => 'empty',
		]],
		['group' => 'Живая лента, форумы, задачи', 'table' => 'b_blog_post', 'set' => ['TITLE' => ['title', 'Пост'], 'DETAIL_TEXT' => 'text', 'PREVIEW_TEXT' => 'text']],
		['group' => 'Живая лента, форумы, задачи', 'table' => 'b_blog_comment', 'set' => [
			'POST_TEXT' => 'text', 'TITLE' => 'empty', 'AUTHOR_NAME' => 'full_name', 'AUTHOR_EMAIL' => 'email', 'AUTHOR_IP' => 'ip', 'AUTHOR_IP1' => 'ip',
		]],
		['group' => 'Живая лента, форумы, задачи', 'table' => 'b_sonet_log', 'set' => ['TITLE' => ['title', 'Событие'], 'MESSAGE' => 'text', 'TEXT_MESSAGE' => 'text']],
		['group' => 'Живая лента, форумы, задачи', 'table' => 'b_sonet_log_comment', 'set' => ['MESSAGE' => 'text', 'TEXT_MESSAGE' => 'text']],
		['group' => 'Живая лента, форумы, задачи', 'table' => 'b_tasks', 'set' => ['TITLE' => ['title', 'Задача'], 'DESCRIPTION' => 'text']],
		['group' => 'Живая лента, форумы, задачи', 'table' => 'b_forum', 'set' => ['LAST_POSTER_NAME' => 'full_name', 'ABS_LAST_POSTER_NAME' => 'full_name']],
		['group' => 'Живая лента, форумы, задачи', 'table' => 'b_blog', 'set' => ['NAME' => ['title', 'Блог'], 'DESCRIPTION' => 'text']],

		['group' => 'Задачи: чек-листы, результаты, история', 'table' => 'b_tasks_checklist_items', 'set' => ['TITLE' => 'text']],
		['group' => 'Задачи: чек-листы, результаты, история', 'table' => 'b_tasks_template_chl_item', 'set' => ['TITLE' => 'text']],
		['group' => 'Задачи: чек-листы, результаты, история', 'table' => 'b_tasks_result', 'set' => ['TEXT' => 'text']],
		['group' => 'Задачи: чек-листы, результаты, история', 'table' => 'b_tasks_effective', 'set' => ['TASK_TITLE' => ['title', 'Задача']]],
		['group' => 'Задачи: чек-листы, результаты, история', 'table' => 'b_tasks_label', 'set' => ['NAME' => ['title', 'Метка']]],
		// история изменений: в FROM/TO_VALUE и заголовки, и описания, и пункты чек-листа, но и сроки, ID, флаги Y/N —
		// трогаем только строки, где есть слово (три буквы подряд), иначе в режиме «целиком» сломается вывод истории
		['group' => 'Задачи: чек-листы, результаты, история', 'table' => 'b_tasks_log', 'requires' => ['b_tasks_log.FROM_VALUE', 'b_tasks_log.TO_VALUE'],
			'where' => "CONCAT(COALESCE(t.`FROM_VALUE`, ''), ' ', COALESCE(t.`TO_VALUE`, '')) REGEXP '[[:alpha:]]{3}'",
			'set' => ['FROM_VALUE' => 'text', 'TO_VALUE' => 'text']],
		['group' => 'Задачи: чек-листы, результаты, история', 'truncate' => ['b_tasks_scorer_event']],

		['group' => 'Календарь', 'table' => 'b_calendar_event', 'set' => [
			'NAME' => ['title', 'Событие'], 'DESCRIPTION' => 'text', 'LOCATION' => 'text', 'SEARCHABLE_CONTENT' => 'empty', 'MEETING' => 'ser_text',
		]],
		['group' => 'Календарь', 'table' => 'b_calendar_section', 'set' => ['NAME' => ['title', 'Календарь'], 'DESCRIPTION' => 'text', 'CAL_DAV_CAL' => 'url']],
		// идентификаторы внешних календарей (часто это e-mail) и данные синхронизации — на копии синхронизации нет
		['group' => 'Календарь', 'table' => 'b_calendar_section_connection', 'set' => ['VENDOR_SECTION_ID' => 'secret']],
		['group' => 'Календарь', 'table' => 'b_calendar_event_connection', 'set' => ['DATA' => 'empty']],
		['group' => 'Календарь', 'table' => 'b_dav_connections', 'set' => ['NAME' => ['title', 'Подключение'], 'SERVER_USERNAME' => 'email']],

		['group' => 'Прочее: отчёты, диск, рассылки, подписи', 'table' => 'b_report', 'set' => ['TITLE' => ['title', 'Отчёт'], 'DESCRIPTION' => 'text']],
		['group' => 'Прочее: отчёты, диск, рассылки, подписи', 'tables_like' => 'b_timeman_report%', 'set' => ['REPORT' => 'text']],
		['group' => 'Прочее: отчёты, диск, рассылки, подписи', 'table' => 'b_ai_history', 'set' => ['RESULT_TEXT' => 'text']],
		['group' => 'Прочее: отчёты, диск, рассылки, подписи', 'table' => 'b_app_password', 'set' => ['COMMENT' => 'text']],
		['group' => 'Прочее: отчёты, диск, рассылки, подписи', 'table' => 'b_sidepanel_toolbar_item', 'set' => ['TITLE' => ['title', 'Вкладка']]],
		// личное хранилище диска называется именем (или e-mail) сотрудника; хранилища групп и общие — не трогаем
		['group' => 'Прочее: отчёты, диск, рассылки, подписи', 'table' => 'b_disk_storage', 'requires' => ['b_disk_storage.ENTITY_TYPE'],
			'where' => "t.`ENTITY_TYPE` LIKE '%User'", 'set' => ['NAME' => 'full_name']],
		['group' => 'Прочее: отчёты, диск, рассылки, подписи', 'table' => 'b_sender_contact', 'set' => ['CODE' => 'contact', 'NAME' => 'full_name']],
		['group' => 'Прочее: отчёты, диск, рассылки, подписи', 'table' => 'b_sender_group_data', 'set' => ['EMAIL' => 'email', 'PHONE' => 'phone', 'NAME' => 'full_name']],
		['group' => 'Прочее: отчёты, диск, рассылки, подписи', 'table' => 'b_sign_member', 'set' => ['COMMUNICATION_VALUE' => 'contact']],
		// история правок лендингов: параметры действия — тексты блоков, которые редактировали (формат определяется по значению)
		['group' => 'Прочее: отчёты, диск, рассылки, подписи', 'table' => 'b_landing_history', 'set' => ['ACTION_PARAMS' => 'json_text']],

		// корзина хранит удалённые сущности целиком (сериализованно) — на копии восстанавливать из неё нечего
		['group' => 'Корзина (удалённые сущности)', 'truncate' => ['b_recyclebin%']],

		// Журнал БП — главный источник для расследований на копии («какое действие что записало, кто согласовал»): не удаляется,
		// а чистится. ACTION_TITLE и ACTION_NAME — заголовки и служебные имена действий из шаблона, их не трогаем.
		// DOCUMENT_NAME задания — TITLE сделки или элемента на момент задания, поэтому чистится как заголовок.
		// PARAMETERS задания — сериализованные поля формы (комментарии, согласующие). Состояние и сам процесс
		// (b_bp_workflow_state, b_bp_workflow_instance) не трогаем: ключ строковый, а WORKFLOW — граф объектов.
		['group' => 'Бизнес-процессы: журнал и задания', 'table' => 'b_bp_tracking', 'set' => ['ACTION_NOTE' => 'text']],
		['group' => 'Бизнес-процессы: журнал и задания', 'table' => 'b_bp_task', 'set' => [
			'NAME' => ['title', 'Задание'], 'DESCRIPTION' => 'text', 'DOCUMENT_NAME' => ['title', 'Документ'], 'PARAMETERS' => 'ser_text',
		]],

		['group' => 'Интернет-магазин', 'table' => 'b_sale_order_props_value', 'join' => $salePropsJoin, 'join_table' => 'b_sale_order_props', 'join_cols' => $salePropsCols, 'set' => ['VALUE' => $saleProps]],
		['group' => 'Интернет-магазин', 'table' => 'b_sale_user_props_value', 'join' => $salePropsJoin, 'join_table' => 'b_sale_order_props', 'join_cols' => $salePropsCols, 'set' => ['VALUE' => $saleProps]],
		['group' => 'Интернет-магазин', 'table' => 'b_sale_user_props', 'set' => ['NAME' => 'full_name']],
		['group' => 'Интернет-магазин', 'table' => 'b_sale_order', 'set' => ['USER_DESCRIPTION' => 'text', 'COMMENTS' => 'text', 'REASON_CANCELED' => 'text']],
		['group' => 'Интернет-магазин', 'table' => 'b_sale_user_cards', 'set' => ['CARD_NUM' => 'digits']],
		['group' => 'Интернет-магазин', 'truncate' => ['b_sale_order_change']],

		['group' => 'Веб-формы', 'table' => 'b_form_result_answer', 'set' => ['USER_TEXT' => 'text', 'USER_TEXT_SEARCH' => 'text']],

		['group' => 'Файлы: имена', 'table' => 'b_file', 'set' => ['ORIGINAL_NAME' => 'filename', 'DESCRIPTION' => 'text']],
		['group' => 'Файлы: имена', 'table' => 'b_disk_object', 'where' => 't.`TYPE` = 2', 'set' => ['NAME' => 'filename']],
		['group' => 'Файлы: имена', 'table' => 'b_disk_version', 'set' => ['NAME' => 'filename']],

		['group' => 'Пользовательские поля (UF) — по типу и коду', 'uf_auto' => true],

		['group' => 'Поисковые индексы', 'columns_rx' => '/^SEARCH_(.+_)?(CONTENT|INDEX|TITLE)$/i', 'method' => 'empty'],
		['group' => 'Поисковые индексы', 'truncate' => [
			'b_search_content%', 'b_search_tags', 'b_search_suggest', 'b_search_phrase', 'b_search_stem', 'b_crm_dp_%',
			'b_sonet_log_index', 'b_disk_object_head_index', 'b_disk_object_extended_index',
		]],

		['group' => 'Логи, очереди, статистика', 'truncate' => [
			'b_event_log', 'b_main_mail_blacklist', 'b_rest_log', 'b_perf_hit', 'b_perf_sql', 'b_perf_sql_backtrace',
			'b_perf_error', 'b_perf_component', 'b_perf_cache', 'b_stat_session', 'b_stat_hit', 'b_stat_guest', 'b_stat_phrase_list',
			'b_stat_referer_list', 'b_stat_searcher_hit', 'b_stat_event_list', 'b_stat_path_cache',
		]],
	];
}

/** Группы правил по порядку: имя => neutral. */
function dr_rule_groups(array $cfg): array
{
	$g = [];
	foreach (dr_rules($cfg) as $r)
	{
		$g[$r['group']] = !empty($r['neutral']);
	}
	return $g;
}

/** Группы, выключенные, пока клиент не выбрал иное. */
function dr_default_off_groups(array $cfg): array
{
	$g = [];
	foreach (dr_rules($cfg) as $r)
	{
		if (!empty($r['default_off']))
		{
			$g[] = $r['group'];
		}
	}
	return array_values(array_unique($g));
}

/**
 * Метод для пользовательского поля или null (поле не трогаем — его покажет проверка утечек).
 * Поля, созданные в интерфейсе Б24, называются UF_CRM_1612345678, поэтому смотрим и на подписи из b_user_field_lang.
 * Сильный признак в коде или подписи (почта, телефон, документ, адрес, ФИО) — структурная замена.
 * Слабые признаки уступают тексту: «Количество адресов», «Карточка склада» — не адрес и не карта.
 * Нераспознанные строковые поля CRM и пользователей идут как текст: в dict — чистка по словарю, в strict — замена целиком,
 * в scrub — вычищаются контакты. Решение по конкретному полю — на странице «Поля клиента» (профиль) или в uf_keep скрипта.
 */
function dr_uf_method(string $field, string $type, string $label = '', string $entity = '', string $mode = 'strict'): ?string
{
	$n = strtoupper($field);
	$l = mb_strtolower($label, 'UTF-8');
	$is = static function (string $rxCode, string $rxLabel) use ($n, $l): bool {
		return (bool)preg_match($rxCode, $n) || ($l !== '' && preg_match($rxLabel . 'u', $l));
	};
	if ($type === 'address')
	{
		return 'address';
	}
	if ($type === 'date' || $type === 'datetime')
	{
		return $is('/BIRTH|ROZHD|(^|_)DR($|_)/', '/рожд|birth/') ? 'null' : null;
	}
	if ($type !== 'string' && $type !== 'string_formatted')
	{
		return null;
	}
	$countable = $l !== '' && preg_match('/колич|кол-во|число|сколько|count/u', $l);
	if ($is('/MAIL/', '/почт|e-?mail|мейл/') && !$countable)
	{
		return 'email';
	}
	if ($is('/PHONE|(^|_)TEL($|_|EFON)|MOBIL/', '/телеф|мобильн|phone|whatsapp|ватсап/') && !$countable)
	{
		return 'phone';
	}
	if ($is('/(^|_)INN($|_)/', '/(^|[^\p{L}])инн([^\p{L}]|$)/'))
	{
		return 'inn';
	}
	if ($is('/PASSP|PASPORT|SNILS|(^|_)(KPP|OGRN|OGRNIP|BIK|OKPO)($|_)|ACCOUNT|SCHET|(^|_)RS($|_)|DOC_NUM|SERIA|SERIES|NOMER_DOC/',
		'/паспорт|снилс|кпп|огрн|(^|\s)бик|окпо|расч[её]тн|лицев|(^|\s)сч[её]т(а|ов)?( (№|номер|банк)|$)|серия|номер док|полис|водит/'))
	{
		return 'digits';
	}
	// карта — документ, только если это номер карты; «Карточка товара», «Карта склада» — нет
	if (preg_match('/CARD|KARTA/', $n) || ($l !== '' && preg_match('/карт[аыу]/u', $l)))
	{
		if ($l === '' ? preg_match('/NUM|NOMER|BANK/', $n) : preg_match('/номер|банк|№/u', $l))
		{
			return 'digits';
		}
	}
	if ($is('/ADDR|ADRES/', '/адрес|address/') && !$countable)
	{
		return 'address';
	}
	if ($is('/BIRTH|ROZHD/', '/рожд|birth/'))
	{
		return 'empty';
	}
	if ($is('/(^|_)FIO($|_)|FULL_?NAME|SURNAME|LAST_?NAME|FIRST_?NAME|FAMIL|IMYA|OTCHEST|PATRON|CONTACT_?NAME|CLIENT_?NAME/',
		'/фио|ф\.и\.о|фамил|(^|\s)имя|отчеств|контактное лицо/'))
	{
		return 'full_name';
	}
	if ($is('/(^|_)(COMPANY|ORG|KOMPAN)/', '/компани|организац/'))
	{
		return 'company';
	}
	if (!preg_match('/^(CRM_|RPA_|USER$)/', strtoupper($entity)) && !$is('/COMMENT|KOMMENT|NOTE|PRIMECH|DESCR|OPISAN/', '/коммент|примечан|заметк|описан/'))
	{
		return null;
	}
	return $mode === 'dict' ? 'clean' : 'text';
}

// ============================================================================
// Сборка задач из правил
// ============================================================================

function dr_resolve_tables(DrDb $db, array $patterns): array
{
	$out = [];
	foreach ($patterns as $p)
	{
		$p = strtolower((string)$p);
		if (strpos($p, '%') !== false)
		{
			$rx = '/^' . str_replace('%', '.*', preg_quote($p, '/')) . '$/';
			foreach ($db->tables() as $lower => $t)
			{
				if (preg_match($rx, $lower))
				{
					$out[$lower] = $t['name'];
				}
			}
		}
		elseif ($db->hasTable($p))
		{
			$out[$p] = $db->tableName($p);
		}
	}
	return array_values($out);
}

/**
 * Задачи для правила: SQL-UPDATE и (в режиме dict) построчная чистка текста в PHP.
 * $cleanMax — таблицы крупнее чистятся не по словарю, а заменой целиком (отметка в $notes).
 */
function dr_update_tasks(DrDb $db, DrAnon $an, array $r, string $group, int $cleanMax, array &$notes): array
{
	$tbl = strtolower($r['table']);
	$meta = $db->columns()[$tbl] ?? null;
	if (!$meta)
	{
		return [];
	}
	if (isset($r['join_table']) && !$db->hasTable($r['join_table']))
	{
		return [];
	}
	$where = (string)($r['where'] ?? '');
	foreach ((array)($r['requires'] ?? []) as $req)
	{
		[$rt, $rc] = explode('.', $req, 2);
		if (!$db->hasColumn($rt, $rc))
		{
			return [];
		}
	}
	foreach ((array)($r['where_if'] ?? []) as $req => $w)
	{
		[$rt, $rc] = explode('.', (string)$req, 2);
		if ($db->hasColumn($rt, $rc))
		{
			$where = $where !== '' ? "($where) AND ($w)" : (string)$w;
		}
	}
	// колонки присоединённой таблицы, которых нет в этой версии модуля, считаем «не отмечено»
	foreach ((array)($r['join_cols'] ?? []) as $jc)
	{
		if (!$db->hasColumn($r['join_table'], $jc))
		{
			array_walk_recursive($r['set'], static function (&$v) use ($jc) {
				$v = is_string($v) ? str_replace('p.`' . $jc . '`', "'N'", $v) : $v;
			});
		}
	}
	$real = $db->tableName($tbl);
	$pk = $db->pk($real);
	$pkq = $pk ? 't.' . dr_qi($pk) : null;
	$uniq = $db->uniqueCols($real);
	$q = static function (string $c) use ($meta) {
		$c = strtoupper($c);
		return isset($meta[$c]) ? 't.' . dr_qi($meta[$c]['name']) : null;
	};
	// построчная чистка — только по числовому ключу и без JOIN; большие таблицы и колонки-дубли — целиком
	$canPhp = $an->mode() === 'dict' && $pk !== null && !isset($r['join']);
	$big = $canPhp && ($db->tables()[$tbl]['rows'] ?? 0) > $cleanMax;
	$strict = $an->mode() === 'dict' ? $an->strict() : $an;
	$sets = [];
	$already = [];
	$names = [];
	$php = [];
	$toStrict = [];
	foreach ($r['set'] as $col => $spec)
	{
		$C = strtoupper((string)$col);
		if (!isset($meta[$C]))
		{
			continue;
		}
		// сериализованное и JSON — только построчно в PHP и во всех режимах: распаковать, почистить строки, упаковать.
		// SQL-замена сломала бы длины s:N и экранирование, а замена целиком — настройки, по которым работает портал.
		$sk = is_string($spec) ? (['ser_text' => 'ser', 'json_text' => 'json'][$spec] ?? null) : null;
		if ($sk !== null)
		{
			if (!dr_is_str_type($meta[$C]['type']))
			{
				continue;
			}
			if ($pk === null || isset($r['join']))
			{
				$notes['struct'][] = $real . '.' . $meta[$C]['name'];
				continue;
			}
			// большая таблица в dict: по словарю долго — внутри только контакты и документы, как в strict
			$lite = $an->mode() === 'dict' && ($db->tables()[$tbl]['rows'] ?? 0) > $cleanMax;
			if ($lite)
			{
				$toStrict[] = $meta[$C]['name'] . ' (внутри — только контакты)';
			}
			$php[$meta[$C]['name']] = ['m' => $sk, 'len' => strpos($meta[$C]['type'], 'text') !== false || $meta[$C]['len'] <= 0 ? 0 : $meta[$C]['len'], 'lite' => $lite];
			continue;
		}
		$m = $an->php($spec, $C);
		$use = $an;
		if ($an->mode() === 'dict' && !is_array($spec) && in_array($C, DrAnon::DUP_TEXT, true))
		{
			$use = $strict;
		}
		elseif ($m !== null && $canPhp && dr_is_str_type($meta[$C]['type']))
		{
			if (!$big)
			{
				$php[$meta[$C]['name']] = ['m' => $m, 'len' => strpos($meta[$C]['type'], 'text') !== false || $meta[$C]['len'] <= 0 ? 0 : $meta[$C]['len']];
				continue;
			}
			if ($m === 'clean')
			{
				$use = $strict;
				$toStrict[] = $meta[$C]['name'];
			}
		}
		// колонка в PRIMARY/UNIQUE (и в составном): фейк от первичного ключа или хеш исходного значения,
		// иначе одинаковая подмена упрётся в ключ и строки останутся с исходным значением
		$unique = isset($uniq[$C]);
		$expr = $use->build($spec, ['col' => $q($C), 'meta' => $meta[$C], 'pk' => $pkq, 'q' => $q, 'already' => $already, 'unique' => $unique]);
		if ($expr === null)
		{
			continue;
		}
		$sets[] = $q($C) . ' = ' . $expr;
		$already[] = $C;
		$names[] = $meta[$C]['name'];
	}
	if ($toStrict)
	{
		$notes[] = $real . ' (~' . $db->tables()[$tbl]['rows'] . ' строк): ' . implode(', ', $toStrict);
	}
	$out = [];
	if ($sets)
	{
		$out[] = [
			'kind' => 'update',
			'label' => $group . ' · ' . $real,
			'table' => $real,
			'cols' => $names,
			'from' => dr_qi($real) . ' t' . (isset($r['join']) ? ' ' . $r['join'] : ''),
			'set' => implode(', ', $sets),
			'where' => $where,
			'pk' => $pkq,
		];
	}
	if ($php)
	{
		$out[] = [
			'kind' => 'clean',
			'label' => $group . ' · ' . $real . ' (текст)',
			'table' => $real,
			'pk' => $pk,
			'cols' => $php,
			'where' => $where,
		];
	}
	return $out;
}

function dr_expand_columns_rx(DrDb $db, array $rule): array
{
	$byTable = [];
	$exclude = array_map('strtolower', (array)($rule['exclude'] ?? []));
	foreach ($db->columns() as $tbl => $cols)
	{
		if (in_array($tbl, $exclude, true))
		{
			continue;
		}
		foreach ($cols as $C => $meta)
		{
			if (!dr_is_str_type($meta['type']) || !preg_match($rule['columns_rx'], $C))
			{
				continue;
			}
			if (!empty($rule['min_len']) && $meta['type'] !== 'text' && strpos($meta['type'], 'text') === false && $meta['len'] < $rule['min_len'])
			{
				continue;
			}
			$byTable[$tbl][$C] = $rule['method'];
		}
	}
	$out = [];
	foreach ($byTable as $tbl => $set)
	{
		$out[] = ['table' => $tbl, 'set' => $set];
	}
	return $out;
}

/** Пользовательские поля: id, entity, field, type, multi, label, tables (где лежит колонка значения). */
function dr_uf_list(DrDb $db): array
{
	if (!$db->hasTable('b_user_field'))
	{
		return [];
	}
	$labels = [];
	if ($db->hasColumn('b_user_field_lang', 'USER_FIELD_ID'))
	{
		$cols = array_values(array_filter(['EDIT_FORM_LABEL', 'LIST_COLUMN_LABEL', 'LIST_FILTER_LABEL'], static function ($c) use ($db) {
			return $db->hasColumn('b_user_field_lang', $c);
		}));
		if ($cols)
		{
			foreach ($db->all('SELECT USER_FIELD_ID, CONCAT_WS(\' \', ' . implode(', ', $cols) . ') L FROM b_user_field_lang') as $r)
			{
				$labels[(int)$r['USER_FIELD_ID']] = trim(($labels[(int)$r['USER_FIELD_ID']] ?? '') . ' ' . $r['L']);
			}
		}
	}
	$colIndex = [];
	foreach ($db->columns() as $tbl => $cols)
	{
		if (preg_match('/^b_(user_field|utm_)/', $tbl))
		{
			continue;
		}
		foreach (array_keys($cols) as $C)
		{
			if (strpos($C, 'UF_') === 0)
			{
				$colIndex[$C][] = $tbl;
			}
		}
	}
	$out = [];
	foreach ($db->all('SELECT ID, ENTITY_ID, FIELD_NAME, USER_TYPE_ID, MULTIPLE FROM b_user_field ORDER BY ENTITY_ID, FIELD_NAME') as $uf)
	{
		$out[] = [
			'id' => (int)$uf['ID'],
			'entity' => (string)$uf['ENTITY_ID'],
			'field' => (string)$uf['FIELD_NAME'],
			'type' => (string)$uf['USER_TYPE_ID'],
			'multi' => $uf['MULTIPLE'] === 'Y',
			'label' => $labels[(int)$uf['ID']] ?? '',
			'tables' => $colIndex[strtoupper((string)$uf['FIELD_NAME'])] ?? [],
		];
	}
	return $out;
}

/** Ключ поля в профиле: СУЩНОСТЬ.ПОЛЕ */
function dr_uf_key(array $uf): string
{
	return strtoupper($uf['entity'] . '.' . $uf['field']);
}

/**
 * Итоговый метод поля: решение из профиля (keep — оставить, вычистив контакты и документы; clean — чистить как текст;
 * replace — заменить целиком), uf_keep скрипта (= keep), иначе автоматически по коду и подписи.
 */
function dr_uf_decide(array $uf, string $mode, array $keep, array $decisions): ?string
{
	$auto = dr_uf_method($uf['field'], $uf['type'], $uf['label'], $uf['entity'], $mode);
	$d = (string)($decisions[dr_uf_key($uf)] ?? (in_array(strtoupper($uf['field']), $keep, true) ? 'keep' : ''));
	if ($d === '' || ($uf['type'] !== 'string' && $uf['type'] !== 'string_formatted'))
	{
		return $d === 'keep' ? null : $auto;
	}
	return ['keep' => 'keep_rx', 'clean' => $mode === 'dict' ? 'clean' : 'text', 'replace' => 'text_strict'][$d] ?? $auto;
}

function dr_expand_uf(DrDb $db, string $mode, array $keep, array $decisions): array
{
	$keep = array_map('strtoupper', $keep);
	$byTable = [];
	$out = [];
	foreach (dr_uf_list($db) as $uf)
	{
		$method = dr_uf_decide($uf, $mode, $keep, $decisions);
		if ($method === null)
		{
			continue;
		}
		foreach ($uf['tables'] as $tbl)
		{
			// множественные значения в b_uts_* сериализованы — их не подделать и не вычистить, только очистить
			$byTable[$tbl][$uf['field']] = $uf['multi'] ? 'empty' : $method;
		}
		$utm = 'b_utm_' . strtolower($uf['entity']);
		if ($uf['multi'] && $db->hasColumn($utm, 'VALUE') && $db->hasColumn($utm, 'FIELD_ID'))
		{
			$out[] = ['table' => $utm, 'where' => 't.`FIELD_ID` = ' . $uf['id'], 'set' => ['VALUE' => $method]];
		}
		// смарт-процессы, RPA и highload-блоки держат множественное поле в своей таблице <таблица сущности>_<поле>: ID, VALUE
		// (колонки в основной таблице у такого поля может и не быть — ищем по окончанию имени)
		$suffix = '_' . strtolower($uf['field']);
		foreach ($uf['multi'] ? $db->tables() : [] as $lower => $t)
		{
			if (substr($lower, -strlen($suffix)) === $suffix && strpos($lower, 'b_utm_') !== 0 && $db->hasColumn($lower, 'VALUE'))
			{
				$out[] = ['table' => $t['name'], 'set' => ['VALUE' => $method]];
			}
		}
	}
	foreach ($byTable as $tbl => $set)
	{
		$out[] = ['table' => $tbl, 'set' => $set];
	}
	return $out;
}

/**
 * Таблицы смарт-процессов без типа: b_crm_dynamic_items_N (и их b_crm_dynamic_items_N_<поле> множественных UF),
 * для которых в b_crm_dynamic_type нет записи. Если тип узнать не по чему — пусто: лучше не тронуть, чем стереть живое.
 */
function dr_dynamic_orphans(DrDb $db): array
{
	$known = [];
	if ($db->hasColumn('b_crm_dynamic_type', 'TABLE_NAME'))
	{
		foreach ($db->all('SELECT TABLE_NAME FROM b_crm_dynamic_type') as $r)
		{
			$known[strtolower((string)$r['TABLE_NAME'])] = true;
		}
	}
	elseif ($db->hasColumn('b_crm_dynamic_type', 'ENTITY_TYPE_ID'))
	{
		foreach ($db->all('SELECT ENTITY_TYPE_ID FROM b_crm_dynamic_type') as $r)
		{
			$known['b_crm_dynamic_items_' . (int)$r['ENTITY_TYPE_ID']] = true;
		}
	}
	else
	{
		return [];
	}
	$out = [];
	foreach ($db->tables() as $lower => $t)
	{
		if (preg_match('/^(b_crm_dynamic_items_\d+)(_.+)?$/', $lower, $m) && !isset($known[$m[1]]))
		{
			$out[] = $t['name'];
		}
	}
	return $out;
}

/**
 * Таблицы правил обезличивания, которых нет в базе: модуль не установлен — или имя в правиле неверное.
 * Отчёт показывает их отдельной строкой: «таблицы нет» не должно выглядеть как «пусто».
 */
function dr_missing_rule_tables(DrDb $db, array $rules, array $skip): array
{
	$out = [];
	foreach ($rules as $r)
	{
		if (!isset($r['table']) || !empty($r['optional']) || in_array((string)($r['group'] ?? ''), $skip, true))
		{
			continue;
		}
		if (!$db->hasTable((string)$r['table']))
		{
			$out[strtolower((string)$r['table'])] = (string)$r['table'];
		}
	}
	return array_values($out);
}

/** Задачи из правил; $notes — таблицы, где текст из-за размера заменён целиком. */
function dr_build_tasks(DrDb $db, DrAnon $an, array $rules, array $skip, array $ufKeep, array $ufDecisions, int $cleanMax, array &$notes): array
{
	$tasks = [];
	foreach ($rules as $rule)
	{
		$g = (string)$rule['group'];
		if (in_array($g, $skip, true))
		{
			continue;
		}
		if (isset($rule['ops']))
		{
			$tasks[] = ['kind' => 'ops', 'label' => $g, 'ops' => $rule['ops']];
			continue;
		}
		if (isset($rule['truncate']) || !empty($rule['truncate_orphans']))
		{
			$list = isset($rule['truncate']) ? dr_resolve_tables($db, (array)$rule['truncate']) : dr_dynamic_orphans($db);
			if ($list)
			{
				$tasks[] = ['kind' => 'truncate', 'label' => $g . ' · очистка таблиц', 'tables' => $list];
			}
			continue;
		}
		if (isset($rule['columns_rx']))
		{
			$expanded = dr_expand_columns_rx($db, $rule);
		}
		elseif (!empty($rule['uf_auto']))
		{
			$expanded = dr_expand_uf($db, $an->mode(), $ufKeep, $ufDecisions);
		}
		else
		{
			$tables = isset($rule['tables_like']) ? dr_resolve_tables($db, [$rule['tables_like']]) : [$rule['table']];
			$expanded = [];
			foreach ($tables as $t)
			{
				$expanded[] = ['table' => $t] + $rule;
			}
		}
		foreach ($expanded as $r)
		{
			foreach (dr_update_tasks($db, $an, $r, $g, $cleanMax, $notes) as $task)
			{
				$tasks[] = $task;
			}
		}
	}
	return $tasks;
}

// ============================================================================
// Режим dict: словарь людей, построчная чистка текста, поиск имён
// ============================================================================

/** Источники словаря: таблица => [имя, фамилия, отчество, условие]. */
function dr_dict_sources(array $cfg): array
{
	$keep = array_values(array_filter(array_map('intval', (array)$cfg['keep_user_ids'])));
	return [
		'b_user' => ['NAME', 'LAST_NAME', 'SECOND_NAME', $keep ? 't.`ID` NOT IN (' . implode(',', $keep) . ')' : ''],
		'b_crm_contact' => ['NAME', 'LAST_NAME', 'SECOND_NAME', ''],
		'b_crm_lead' => ['NAME', 'LAST_NAME', 'SECOND_NAME', ''],
		'b_crm_requisite' => ['RQ_FIRST_NAME', 'RQ_LAST_NAME', 'RQ_SECOND_NAME', ''],
		'b_socialservices_user' => ['NAME', 'LAST_NAME', null, ''],
	];
}

/** Задача построения словаря: SQL с меткой соли (INSERT … SELECT DISTINCT — двойники считает сама БД). */
function dr_dict_task(DrDb $db, DrAnon $an, array $cfg): array
{
	$cs = preg_match('/^\w+$/', $db->cs) ? $db->cs : 'utf8mb4';
	$sql = [
		'DROP TABLE IF EXISTS ' . dr_qi(DR_DICT_TABLE),
		'CREATE TABLE ' . dr_qi(DR_DICT_TABLE) . ' (k CHAR(32) CHARACTER SET ascii NOT NULL, kind CHAR(1) CHARACTER SET ascii NOT NULL,'
			. " fem TINYINT NOT NULL, fake VARCHAR(100) NOT NULL, PRIMARY KEY (k, kind, fem)) ENGINE=InnoDB DEFAULT CHARSET=$cs",
	];
	foreach (dr_dict_sources($cfg) as $tbl => [$fc, $lc, $pc, $where])
	{
		$meta = $db->columns()[$tbl] ?? null;
		if (!$meta)
		{
			continue;
		}
		$q = static function (string $c) use ($meta) {
			$c = strtoupper($c);
			return isset($meta[$c]) ? 't.' . dr_qi($meta[$c]['name']) : null;
		};
		$nameCol = isset($meta[$fc]) ? $fc : null;
		if (!isset($meta[$fc], $meta[$lc]))
		{
			continue;
		}
		// человек — это строка с именем и фамилией; одиночные NAME/LAST_NAME лидов часто мусор («Тендер», «Бухгалтерия»)
		$real = static function (string $col) use ($q): string {
			return 'CHAR_LENGTH(TRIM(' . $q($col) . ")) >= 2 AND TRIM(" . $q($col) . ") NOT REGEXP '[0-9@ ]'";
		};
		$both = $real($fc) . ' AND ' . $real($lc);
		foreach (['f' => $fc, 'l' => $lc, 'p' => $pc] as $kind => $col)
		{
			if ($col === null || !isset($meta[$col]) || !dr_is_str_type($meta[$col]['type']))
			{
				continue;
			}
			$c = ['col' => $q($col), 'meta' => $meta[$col], 'pk' => null, 'q' => $q, 'already' => [], 'unique' => false];
			[$k, $fem, $fake] = $an->dictRow($kind, $c, $kind === 'f' ? null : $nameCol);
			$cond = $both . ($kind === 'p' ? ' AND ' . $real($col) : '') . ($where !== '' ? " AND $where" : '');
			$sql[] = 'INSERT IGNORE INTO ' . dr_qi(DR_DICT_TABLE) . " (k, kind, fem, fake) SELECT DISTINCT $k, '$kind', $fem, $fake FROM "
				. dr_qi($db->tableName($tbl)) . " t WHERE $cond";
		}
	}
	return ['kind' => 'dict', 'label' => 'Словарь людей (ФИО из базы → двойники) для чистки текста', 'sql' => $sql];
}

/** Словарь в память шага. */
function dr_dict_load(DrDb $db): array
{
	static $cache = null;
	if ($cache !== null)
	{
		return $cache;
	}
	if (!$db->tableExists(DR_DICT_TABLE))
	{
		throw new RuntimeException('словарь людей не найден (шаг словаря не выполнен или обработку сбрасывали) — перезапустите обработку');
	}
	$cache = [];
	$r = $db->q('SELECT k, kind, fem, fake FROM ' . dr_qi(DR_DICT_TABLE));
	while ($row = $r->fetch_row())
	{
		$cache[$row[0] . $row[1] . $row[2]] = $row[3];
	}
	$r->free();
	return $cache;
}

function dr_cleaner(DrDb $db, array $run, bool $leak = false): DrCleaner
{
	static $cache = [];
	$key = (string)$run['token'] . ($leak ? ':leak' : '');
	if (!isset($cache[$key]))
	{
		// без словаря (strict, scrub) чистильщик нужен только сериализованному и JSON: там ищутся лишь контакты и документы
		$dict = $leak || ($run['opts']['text_mode'] ?? 'dict') === 'dict';
		$c = new DrCleaner(new DrFakes((string)$run['salt']), $dict ? dr_dict_load($db) : [], $dict, (bool)($run['opts']['single_surname'] ?? true));
		$c->ignoreFakes = $leak;
		$cache[$key] = $c;
	}
	return $cache[$key];
}

function dr_task_dict(DrDb $db, array &$t, float $deadline): void
{
	$t['pos'] = (int)($t['pos'] ?? 0);
	$n = count($t['sql']);
	while ($t['pos'] < $n && microtime(true) < $deadline)
	{
		$db->exec($t['sql'][$t['pos']]);
		$t['pos']++;
	}
	$t['frac'] = $t['pos'] / max(1, $n);
	if ($t['pos'] >= $n)
	{
		$t['res'] = 'основ в словаре: ' . (int)$db->val('SELECT COUNT(*) FROM ' . dr_qi(DR_DICT_TABLE));
		$t['done'] = true;
	}
}

/**
 * Построчная чистка текста в PHP: выборка по ключу пачками ~2 МБ, запись только изменённых строк, пачка — в транзакции.
 * В тексте ошибки — только код MySQL: в запросе персональные данные.
 */
function dr_task_clean(DrDb $db, array &$run, array &$t, float $deadline): void
{
	$cl = dr_cleaner($db, $run);
	$tbl = dr_qi($t['table']);
	$pk = dr_qi($t['pk']);
	$cols = $t['cols'];
	if (!isset($t['cur']))
	{
		$r = $db->one("SELECT MIN(t.$pk) mn, MAX(t.$pk) mx FROM $tbl t" . ($t['where'] !== '' ? " WHERE {$t['where']}" : ''));
		if ($r === null || $r['mn'] === null)
		{
			$t['res'] = 'пусто';
			$t['done'] = true;
			return;
		}
		$t['cur'] = ['min' => (int)$r['mn'], 'max' => (int)$r['mx'], 'last' => (int)$r['mn'] - 1, 'limit' => 200, 'rows' => 0, 'changed' => 0, 'skipped' => 0,
			'broken' => [], 'broken_id' => []];
	}
	$c =& $t['cur'];
	// строки внутри сериализованного и JSON: в dict — по словарю (коды и ссылки — только контакты), иначе только контакты и документы
	$dict = ($run['opts']['text_mode'] ?? '') === 'dict';
	$textFn = static function (string $v, bool $code) use ($cl): string {
		return $code ? $cl->contacts($v) : $cl->clean($v);
	};
	$contactsFn = static function (string $v) use ($cl): string {
		return $cl->contacts($v);
	};
	$sel = [];
	$nonEmpty = [];
	foreach (array_keys($cols) as $col)
	{
		$sel[] = 't.' . dr_qi($col);
		$nonEmpty[] = 't.' . dr_qi($col) . " <> ''";
	}
	$before = $cl->hits;
	$finished = false;
	$first = true;
	while ($first || microtime(true) < $deadline)
	{
		$first = false;
		$where = "t.$pk > {$c['last']} AND (" . implode(' OR ', $nonEmpty) . ')' . ($t['where'] !== '' ? " AND ({$t['where']})" : '');
		$res = $db->q("SELECT t.$pk AS __id, " . implode(', ', $sel) . " FROM $tbl t WHERE $where ORDER BY t.$pk LIMIT " . (int)$c['limit'], false);
		$rows = [];
		$bytes = 0;
		while ($row = $res->fetch_assoc())
		{
			$rows[] = $row;
			foreach ($row as $v)
			{
				$bytes += strlen((string)$v);
			}
		}
		$res->free();
		if (!$rows)
		{
			$finished = true;
			break;
		}
		if (!$db->link->begin_transaction())
		{
			throw new RuntimeException('MySQL ' . $db->link->errno . ' при BEGIN', $db->link->errno);
		}
		try
		{
			foreach ($rows as $row)
			{
				$set = [];
				foreach ($cols as $col => $m)
				{
					$v = $row[$col];
					if ($v === null || $v === '')
					{
						continue;
					}
					if ($m['m'] === 'ser' || $m['m'] === 'json')
					{
						[$new, $st] = dr_struct_clean($v, $m['m'], $dict && empty($m['lite']) ? $textFn : $contactsFn);
						// не распаковалось или упакованное не влезает в колонку — не трогаем, считаем и называем в отчёте
						if ($st === 'broken' || ($new !== null && $m['len'] > 0 && mb_strlen($new) > $m['len']))
						{
							$c['broken'][$col] = ($c['broken'][$col] ?? 0) + 1;
							$c['broken_id'][$col] = $c['broken_id'][$col] ?? (int)$row['__id'];
						}
						elseif ($new !== null)
						{
							$set[] = dr_qi($col) . " = '" . $db->link->real_escape_string($new) . "'";
						}
						continue;
					}
					$new = $m['m'] === 'person' ? $cl->person($v) : ($m['m'] === 'keep_rx' ? $cl->contacts($v) : $cl->clean($v));
					if ($m['len'] > 0 && mb_strlen($new) > $m['len'])
					{
						$new = mb_substr($new, 0, $m['len']);
					}
					if ($new !== $v)
					{
						$set[] = dr_qi($col) . " = '" . $db->link->real_escape_string($new) . "'";
					}
				}
				if (!$set)
				{
					continue;
				}
				try
				{
					$db->exec("UPDATE $tbl SET " . implode(', ', $set) . " WHERE $pk = " . (int)$row['__id'], false);
					$c['changed']++;
				}
				catch (RuntimeException $e)
				{
					if ($e->getCode() !== 1062)
					{
						throw new RuntimeException('MySQL ' . $e->getCode() . ' при записи строки #' . (int)$row['__id'], $e->getCode());
					}
					$c['skipped']++; // двойник совпал с уникальным значением другой строки — строку покажет проверка утечек
				}
			}
			// без исключений mysqli молчит: незакоммиченная пачка не должна считаться почищенной
			if (!$db->link->commit())
			{
				throw new RuntimeException('MySQL ' . $db->link->errno . ' при COMMIT', $db->link->errno);
			}
		}
		catch (Throwable $e)
		{
			@$db->link->rollback();
			throw $e;
		}
		$c['rows'] += count($rows);
		$c['last'] = (int)end($rows)['__id'];
		// пачка ~2 МБ текста: короткие заголовки — тысячами, длинные письма — десятками
		$c['limit'] = max(20, min(5000, (int)(2000000 / max(1, $bytes / count($rows)))));
	}
	foreach ($cl->hits as $k => $v)
	{
		$t['hits'][$k] = ($t['hits'][$k] ?? 0) + $v - ($before[$k] ?? 0);
	}
	$t['frac'] = min(1, ($c['last'] - $c['min'] + 1) / max(1, $c['max'] - $c['min'] + 1));
	if (!$finished)
	{
		unset($c);
		return;
	}
	$hits = [];
	foreach (['name' => 'имён', 'email' => 'e-mail', 'phone' => 'телефонов', 'doc' => 'документов', 'addr' => 'адресов', 'url' => 'ссылок'] as $k => $w)
	{
		if (!empty($t['hits'][$k]))
		{
			$hits[] = $w . ' ' . $t['hits'][$k];
		}
	}
	$t['res'] = implode(', ', array_keys($cols)) . " — строк: {$c['rows']}, изменено: {$c['changed']}" . ($hits ? ' (' . implode(', ', $hits) . ')' : '');
	$broken = [];
	foreach ((array)($c['broken'] ?? []) as $col => $n)
	{
		$broken[] = $col . ' ' . $n;
		$run['report']['struct_broken'][] = $t['table'] . '.' . $col . ': ' . $n . ' (первая — строка #' . $c['broken_id'][$col] . ')';
	}
	if ($broken)
	{
		$t['res'] .= '; не распаковались, оставлены как есть: ' . implode(', ', $broken);
	}
	if ($c['skipped'])
	{
		$t['err'][] = 'конфликт уникального ключа, строк пропущено: ' . $c['skipped'];
	}
	unset($c);
	$t['done'] = true;
}

/** Таблицы и текстовые колонки для проверки утечек: lower(таблица) => name, rows, pk, cols. */
function dr_leak_items(DrDb $db, array $run, array $cfg): array
{
	$exclude = array_merge(['b_search_%', 'b_cache_tag', 'b_perf_%', 'b_option', 'zz_devrestore_%'], (array)$run['truncated'], (array)$cfg['leak_scan_exclude']);
	$skipT = array_flip(array_map('strtolower', dr_resolve_tables($db, $exclude)));
	$out = [];
	$tables = $db->tables();
	foreach ($db->columns() as $tbl => $cols)
	{
		if (isset($skipT[$tbl]) || !isset($tables[$tbl]))
		{
			continue;
		}
		$textCols = array_filter($cols, static function ($m) {
			return dr_is_str_type($m['type']) && ($m['len'] >= 6 || strpos($m['type'], 'text') !== false);
		});
		if ($textCols)
		{
			$out[$tbl] = ['name' => $tables[$tbl]['name'], 'rows' => $tables[$tbl]['rows'], 'pk' => $db->pk($tbl), 'cols' => array_column($textCols, 'name')];
		}
	}
	return $out;
}

/**
 * Справочные слова базы: однословные названия статусов CRM, значения списков инфоблоков, населённые пункты.
 * «Медицина», «Кимры» совпадают с фамилией по форме, но раз они есть в справочнике — это не человек.
 * lower(слово) => true; таблицы нет — пропускаем.
 */
function dr_leak_ref_words(DrDb $db): array
{
	static $cache = null;
	if ($cache !== null)
	{
		return $cache;
	}
	$cache = [];
	foreach ([['b_crm_status', 'NAME'], ['b_iblock_property_enum', 'VALUE'], ['b_location_name', 'NAME']] as [$tbl, $col])
	{
		if (!$db->hasColumn($tbl, $col))
		{
			continue;
		}
		try
		{
			$res = $db->q('SELECT DISTINCT ' . dr_qi($col) . ' AS w FROM ' . dr_qi($tbl) . ' WHERE ' . dr_qi($col) . " NOT LIKE '% %' AND CHAR_LENGTH("
				. dr_qi($col) . ') BETWEEN 3 AND 40 LIMIT 300000', false);
			while ($r = $res->fetch_row())
			{
				$cache[mb_strtolower((string)$r[0])] = true;
			}
			$res->free();
		}
		catch (Throwable $e)
		{
			// справочник недоступен — проверка просто будет строже
		}
	}
	return $cache;
}

/**
 * Поиск имён людей: последние 300 строк каждой таблицы, все текстовые колонки одним запросом.
 * Двойники из наших списков не считаются. Находка — повод добавить правило (готовая строка в отчёте).
 */
function dr_task_leak_names(DrDb $db, array &$run, array &$t, array $cfg, float $deadline): void
{
	if (!isset($t['cur']))
	{
		$t['cur'] = ['items' => array_values(dr_leak_items($db, $run, $cfg)), 'i' => 0];
		$t['found'] = 0;
	}
	$cl = dr_cleaner($db, $run, true);
	$ref = dr_leak_ref_words($db);
	$c =& $t['cur'];
	$n = count($c['items']);
	while ($c['i'] < $n && microtime(true) < $deadline)
	{
		$it = $c['items'][$c['i']++];
		$sel = array_map(static function ($col) {
			return 'LEFT(' . dr_qi($col) . ', 2000) AS ' . dr_qi($col);
		}, $it['cols']);
		try
		{
			$rows = $db->all('SELECT ' . ($it['pk'] ? dr_qi($it['pk']) : 'NULL') . ' AS __id, ' . implode(', ', $sel) . ' FROM ' . dr_qi($it['name'])
				. ($it['pk'] ? ' ORDER BY ' . dr_qi($it['pk']) . ' DESC' : '') . ' LIMIT 300');
		}
		catch (Throwable $e)
		{
			$run['report']['leak_skipped'][] = $it['name'] . ' (имена: ошибка ' . $e->getCode() . ')';
			continue;
		}
		$hits = [];
		foreach ($rows as $row)
		{
			foreach ($it['cols'] as $col)
			{
				$v = (string)$row[$col];
				if ($v === '' || !preg_match('/\p{L}{4}/u', $v))
				{
					continue;
				}
				// сериализованное: смотрим строки внутри (значение обрезано — unserialize не годится)
				$ser = (bool)preg_match('/^(a|s|i|O):\d+:/', $v);
				if ($ser)
				{
					preg_match_all('/s:\d+:"(.*?)";/su', $v, $sm);
					$v = implode(' ; ', $sm[1]);
				}
				// слово, которое в этой же базе — название статуса, значение списка или населённый пункт, — не человек
				$words = array_values(array_filter($cl->names($v), static function ($w) use ($ref) {
					return !isset($ref[mb_strtolower((string)$w)]);
				}));
				if (!$words)
				{
					continue;
				}
				if (!isset($hits[$col]))
				{
					$w = (string)$words[0];
					$hits[$col] = ['n' => 0, 'id' => $row['__id'], 'v' => mb_substr($w, 0, 1) . str_repeat('*', max(2, mb_strlen($w) - 1)), 'ser' => $ser];
				}
				$hits[$col]['n']++;
			}
		}
		foreach ($hits as $col => $h)
		{
			$run['report']['leak_names'][] = ['t' => $it['name'], 'c' => $col, 'n' => $h['n'], 'id' => $h['id'], 'v' => $h['v'], 'of' => count($rows), 'ser' => $h['ser']];
			$t['found']++;
		}
	}
	$t['frac'] = $n ? $c['i'] / $n : 1;
	if ($c['i'] >= $n)
	{
		$t['res'] = "проверено таблиц: $n, колонок с именами: {$t['found']}";
		$t['done'] = true;
		if ($t['found'])
		{
			dr_run_log($run, 'Проверка нашла имена людей в ' . $t['found'] . ' колонках — см. отчёт', 'warn');
		}
	}
	unset($c);
}

/** Удалить словарь и (если есть права) историю запросов performance_schema, где могла остаться соль. */
function dr_task_drop_dict(DrDb $db, array &$run, array &$t): void
{
	$db->exec('DROP TABLE IF EXISTS ' . dr_qi(DR_DICT_TABLE));
	$ps = 0;
	try
	{
		$psOn = (int)$db->val('SELECT @@performance_schema') === 1;
	}
	catch (Throwable $e)
	{
		$psOn = false;
	}
	// в summary_by_digest MySQL 8 хранит пример запроса целиком (QUERY_SAMPLE_TEXT) — вместе с солью
	foreach (['events_statements_history', 'events_statements_history_long', 'events_statements_summary_by_digest'] as $pt)
	{
		try
		{
			$db->q('TRUNCATE TABLE performance_schema.' . $pt);
			$ps++;
		}
		catch (Throwable $e)
		{
			// нет прав: summary_by_digest сам не вытесняется — предупреждаем ниже
		}
	}
	if ($psOn && $ps < 3)
	{
		$run['report']['warn'][] = 'История запросов performance_schema не очищена (нет прав на TRUNCATE): в ней могут остаться запросы с солью до перезапуска MySQL. '
			. 'Выполните под root TRUNCATE для performance_schema.events_statements_summary_by_digest, _history и _history_long или перезапустите MySQL.';
	}
	$t['res'] = 'словарь удалён' . ($ps ? ', история запросов performance_schema очищена' : ($psOn ? ', историю performance_schema очистить не удалось' : ''));
	$t['done'] = true;
}

/** Временные таблицы devrestore, оставшиеся в базе. */
function dr_leftover_tables(DrDb $db): array
{
	return array_column($db->all("SELECT TABLE_NAME n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'zz\\_devrestore\\_%'"), 'n');
}

// ============================================================================
// Определение состояния БД и профиля
// ============================================================================

function dr_detect(DrDb $db): array
{
	$markerRaw = $db->option('devrestore', 'info');
	$marker = $markerRaw ? json_decode($markerRaw, true) : null;
	$serverName = dr_clean_host($db->option('main', 'server_name'));
	$site = null;
	if ($db->hasTable('b_lang'))
	{
		$site = $db->one("SELECT LID, SERVER_NAME FROM b_lang WHERE ACTIVE = 'Y' ORDER BY DEF DESC, SORT ASC LIMIT 1");
	}
	$prod = is_array($marker) && !empty($marker['prod_domain']) ? (string)$marker['prod_domain'] : ($serverName ?: dr_clean_host($site['SERVER_NAME'] ?? ''));
	// Все домены, которые база считает своими: если среди них текущий хост, а отметки обезличивания нет — это может быть прод.
	$domains = [$serverName];
	if ($db->hasTable('b_lang'))
	{
		$domains = array_merge($domains, $db->hasColumn('b_lang', 'SERVER_NAME') ? array_column($db->all('SELECT SERVER_NAME FROM b_lang'), 'SERVER_NAME') : []);
	}
	if ($db->hasTable('b_lang_domain'))
	{
		$domains = array_merge($domains, array_column($db->all('SELECT DOMAIN FROM b_lang_domain'), 'DOMAIN'));
	}
	$domains = array_filter(array_map(static function ($d) {
		return (string)preg_replace('/^www\./', '', dr_clean_host($d));
	}, $domains));
	return [
		'marker' => is_array($marker) ? $marker : null,
		'server_name' => $serverName,
		'prod' => $prod,
		'site' => (string)($site['LID'] ?? 's1'),
		'looks_prod' => !is_array($marker) && (bool)array_intersect($domains, dr_host_variants()),
	];
}

function dr_try_db(): array
{
	try
	{
		$db = DrDb::connect();
		if (!$db->hasTable('b_option'))
		{
			return [$db, null, 'В базе нет таблиц Битрикс (b_option).'];
		}
		return [$db, dr_detect($db), ''];
	}
	catch (Throwable $e)
	{
		return [null, null, $e->getMessage()];
	}
}

function dr_scripts(): array
{
	$out = [];
	foreach ((array)glob(dr_home() . '/scripts/*.php') as $f)
	{
		$b = basename((string)$f);
		$m = dr_script_manifest($b);
		$out[$b] = $m ?: ['title' => $b . ' (не вернул массив)', 'broken' => true];
	}
	ksort($out);
	return $out;
}

function dr_script_manifest(string $file): ?array
{
	static $cache = [];
	$file = basename($file);
	if (array_key_exists($file, $cache))
	{
		return $cache[$file];
	}
	$path = dr_home() . '/scripts/' . $file;
	if (!is_file($path))
	{
		return $cache[$file] = null;
	}
	try
	{
		$m = dr_include_fresh($path);
	}
	catch (Throwable $e)
	{
		$m = null;
	}
	return $cache[$file] = is_array($m) ? $m : null;
}

// ============================================================================
// Фаза A: подготовка восстановления
// ============================================================================

function dr_scan_archives(): array
{
	$groups = [];
	foreach ((array)scandir(DR_ROOT) as $f)
	{
		if (!is_file(DR_ROOT . '/' . $f))
		{
			continue;
		}
		if (preg_match('/^(.+\.(?:tar|enc)(?:\.gz)?)(?:\.(\d+))?$/i', (string)$f, $m))
		{
			$g =& $groups[$m[1]];
			$g['base'] = $m[1];
			$g['files'][] = $f;
			$g['size'] = ($g['size'] ?? 0) + filesize(DR_ROOT . '/' . $f);
			$g['mtime'] = max($g['mtime'] ?? 0, filemtime(DR_ROOT . '/' . $f));
			unset($g);
		}
	}
	uasort($groups, static function ($a, $b) {
		return $b['mtime'] <=> $a['mtime'];
	});
	return array_values($groups);
}

function dr_scan_dumps(): array
{
	$dir = DR_ROOT . '/bitrix/backup';
	$out = [];
	if (is_dir($dir))
	{
		foreach ((array)scandir($dir) as $f)
		{
			if (preg_match('/\.sql(\.\d+)?$/i', (string)$f) && is_file($dir . '/' . $f))
			{
				$out[] = ['name' => $f, 'size' => filesize($dir . '/' . $f), 'mtime' => filemtime($dir . '/' . $f)];
			}
		}
	}
	return $out;
}

function dr_http_get(string $url): string
{
	if (function_exists('curl_init'))
	{
		$ch = curl_init($url);
		curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 120, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_MAXFILESIZE => 5 * 1024 * 1024, CURLOPT_USERAGENT => 'devrestore/' . DR_VERSION]);
		$body = curl_exec($ch);
		$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$err = curl_error($ch);
		curl_close($ch);
		if ($body === false || $code !== 200)
		{
			throw new RuntimeException('Не удалось скачать ' . $url . ': ' . ($err ?: 'HTTP ' . $code));
		}
		return (string)$body;
	}
	$body = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 120, 'user_agent' => 'devrestore/' . DR_VERSION]]));
	if ($body === false)
	{
		throw new RuntimeException('Не удалось скачать ' . $url);
	}
	return $body;
}

function dr_is_restore_script(string $code): bool
{
	return strncmp(ltrim($code), '<?', 2) === 0 && strpos($code, 'class CDBRestore') !== false && strpos($code, '$Step == 5') !== false;
}

/** Сразу после импорта, пока на копии работает база прода: агенты, очередь писем, вебхуки, почтовые ящики. */
const DR_NEUTRAL_SQL = [
	"UPDATE b_agent SET ACTIVE = 'N'",
	"UPDATE b_option SET VALUE = 'N' WHERE MODULE_ID = 'main' AND NAME = 'check_agents'",
	'DELETE FROM b_event',
	"UPDATE b_rest_event SET EVENT_HANDLER = 'https://blocked.devrestore.invalid/'",
	"UPDATE b_mail_mailbox SET ACTIVE = 'N'",
];

const DR_SELFUPDATE_MARK = '/* DEVRESTORE-PATCH-SELFUPDATE: самообновление restore.php выключено — оно стёрло бы вставки devrestore */';

/** restore.php со всеми тремя вставками devrestore (без них после импорта оживут агенты и почта прода). */
function dr_restore_patched(string $code): bool
{
	return substr_count($code, '// DEVRESTORE-PATCH-BEGIN') >= 2 && strpos($code, DR_SELFUPDATE_MARK) !== false && strpos($code, '?phase=after&token=') !== false;
}

/**
 * Три вставки в restore.php:
 *   самообновление — выключить: restore.php сверяет свой размер с версией на сайте Битрикс и, раз пропатченный
 *   файл всегда больше, на каждом открытии без Step скачивает себя заново и молча стирает остальные вставки;
 *   шаг 3, сразу после импорта — отключить агенты, очередь писем и вебхуки, пока на копии работает база прода
 *   (на тестовом сервере cron_events.php иначе успеет разослать письма из очереди прода);
 *   шаг 5 — вернуться в devrestore.php вместо самоудаления и перехода на сайт.
 * Любое место не найдено — null (отказ).
 */
function dr_patch_restore(string $src, string $token, bool $fast = false, ?bool &$fastOk = null): ?string
{
	$fastOk = false;
	$src = (string)preg_replace('~\R?[ \t]*// DEVRESTORE-PATCH-BEGIN.*?// DEVRESTORE-PATCH-END[^\r\n]*~s', '', $src);
	$src = str_replace('false ' . DR_SELFUPDATE_MARK . ' && ', '', $src);
	// условие самообновления: if (filesize(__FILE__) != …) — в любом порядке сравнения и с любыми пробелами
	$src = (string)preg_replace_callback('~(\bif\s*\(\s*)(?=[^\r\n{;]*\bfilesize\s*\(\s*__FILE__\s*\))~', static function ($m) {
		return $m[1] . 'false ' . DR_SELFUPDATE_MARK . ' && ';
	}, $src, 1, $countSelf);
	if (!$countSelf)
	{
		return null;
	}
	$self = DR_SELF;
	$neutralize = "// DEVRESTORE-PATCH-BEGIN (добавлено $self: сразу после импорта отключить внешние действия)\n";
	foreach (DR_NEUTRAL_SQL as $sql)
	{
		$neutralize .= "\t\t\t\$oDB->Query(" . var_export($sql, true) . ", true);\n";
	}
	$neutralize .= "\t\t\t// DEVRESTORE-PATCH-END\n\t\t\t";
	$src = (string)preg_replace_callback('~if\s*\(\s*!\s*\$oDB->ft_index\s*\)~', static function ($m) use ($neutralize) {
		return $neutralize . $m[0];
	}, $src, 1, $count3);
	if (!$count3)
	{
		return null;
	}
	$inject = "\n\t// DEVRESTORE-PATCH-BEGIN (добавлено $self: после восстановления вернуться в него)\n"
		. "\tif (file_exists(\$_SERVER['DOCUMENT_ROOT'] . '/$self'))\n"
		. "\t{\n"
		. "\t\t\$drUrl = '/$self?phase=after&token=$token'\n"
		. "\t\t\t. '&arc_name=' . urlencode((string)(\$_REQUEST['arc_name'] ?? ''))\n"
		. "\t\t\t. '&dump_name=' . urlencode((string)(\$_REQUEST['dump_name'] ?? ''));\n"
		. "\t\twhile (ob_get_level()) { ob_end_clean(); }\n"
		. "\t\theader('Location: ' . \$drUrl);\n"
		. "\t\techo '<script>location.replace(' . json_encode(\$drUrl) . ');</script>';\n"
		. "\t\texit;\n"
		. "\t}\n"
		. "\t// DEVRESTORE-PATCH-END";
	$new = preg_replace_callback('~elseif\s*\(\s*\$Step\s*==\s*5\s*\)\s*\{~', static function ($m) use ($inject) {
		return $m[0] . $inject;
	}, $src, 1, $count);
	if (!$count || $new === null)
	{
		return null;
	}
	if ($fast)
	{
		// Быстрый импорт: шаг 3 с самого начала (настройки подключения к базе уже подставлены restore.php) отдаёт дамп
		// devrestore.php — тот грузит его клиентом mysql в фоне. Места нет — остаётся штатный импорт restore.php.
		$handover = "// DEVRESTORE-PATCH-BEGIN (добавлено $self: дамп грузит клиент mysql, restore.php только распаковывает)\n"
			. "\t\tif (file_exists(\$_SERVER['DOCUMENT_ROOT'] . '/$self'))\n"
			. "\t\t{\n"
			. "\t\t\t\$drUrl = '/$self?phase=import&token=$token'\n"
			. "\t\t\t\t. '&arc_name=' . urlencode((string)(\$_REQUEST['arc_name'] ?? ''))\n"
			. "\t\t\t\t. '&dump_name=' . urlencode((string)(\$_REQUEST['dump_name'] ?? ''));\n"
			. "\t\t\twhile (ob_get_level()) { ob_end_clean(); }\n"
			. "\t\t\theader('Location: ' . \$drUrl);\n"
			. "\t\t\techo '<script>location.replace(' . json_encode(\$drUrl) . ');</script>';\n"
			. "\t\t\texit;\n"
			. "\t\t}\n"
			. "\t\t// DEVRESTORE-PATCH-END\n\t\t";
		$withFast = preg_replace_callback('~SetCurrentProgress\(\s*0\s*\)\s*;\s*\$r\s*=\s*true\s*;~', static function ($m) use ($handover) {
			return $handover . $m[0];
		}, $new, 1, $countFast);
		if ($countFast && $withFast !== null)
		{
			$new = $withFast;
			$fastOk = true;
		}
	}
	return $new;
}

// ============================================================================
// Быстрый импорт: дамп грузит клиент mysql в фоновом процессе PHP
// ============================================================================

/** Файлы фонового импорта рядом с состоянием: задание (job), блокировка (lock), параметры клиента (cnf), вывод (log, out, err). */
function dr_import_file(string $ext): string
{
	return substr(dr_state_file(), 0, -5) . '-import.' . $ext;
}

function dr_import_job(): ?array
{
	for ($try = 0; $try < 3; $try++)
	{
		$raw = @file_get_contents(dr_import_file('job'));
		if ($raw === false)
		{
			return null;
		}
		$j = json_decode($raw, true);
		if (is_array($j))
		{
			return $j;
		}
		usleep(50000); // попали на запись — прочитаем ещё раз
	}
	return null;
}

function dr_import_job_save(array $j): void
{
	$f = dr_import_file('job');
	$json = (string)json_encode($j, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
	// через временный файл: страница читает задание, пока процесс его пишет
	if (file_put_contents($f . '.tmp', $json) === false || !@rename($f . '.tmp', $f))
	{
		@unlink($f . '.tmp');
		file_put_contents($f, $json, LOCK_EX);
	}
}

/** Идёт ли фоновый импорт: его процесс держит блокировку всё время работы, умер — блокировка снята. */
function dr_import_alive(): bool
{
	$h = @fopen(dr_import_file('lock'), 'c');
	if (!$h)
	{
		return false;
	}
	$free = flock($h, LOCK_EX | LOCK_NB);
	if ($free)
	{
		flock($h, LOCK_UN);
	}
	fclose($h);
	return !$free;
}

/** Запустить программу без оболочки и забрать вывод: [код, вывод] или null. */
function dr_cmd_output(array $args): ?array
{
	if (!function_exists('proc_open'))
	{
		return null;
	}
	$proc = @proc_open($args, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
	if (!is_resource($proc))
	{
		return null;
	}
	fclose($pipes[0]);
	$out = (string)stream_get_contents($pipes[1]) . (string)stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	return [proc_close($proc), $out];
}

function dr_path_dirs(): array
{
	return array_values(array_filter(array_map('trim', explode(PATH_SEPARATOR, (string)getenv('PATH')))));
}

/**
 * Клиент mysql и PHP командной строки для быстрого импорта: ['mysql' => путь, 'php' => путь, 'why' => ''],
 * или пути null и причина, почему импорт пойдёт через restore.php.
 */
function dr_import_tools(array $cfg, ?DrDb $db): array
{
	$no = static function (string $why): array {
		return ['mysql' => null, 'php' => null, 'why' => $why];
	};
	if ($cfg['mysql_bin'] === false)
	{
		return $no('выключен в config.php (mysql_bin = false)');
	}
	if (!function_exists('proc_open') || !function_exists('popen'))
	{
		return $no('на сервере запрещены proc_open или popen (disable_functions)');
	}
	$win = DIRECTORY_SEPARATOR === '\\';
	$exe = $win ? '.exe' : '';
	$cands = [];
	if (is_string($cfg['mysql_bin']) && $cfg['mysql_bin'] !== '')
	{
		$cands[] = $cfg['mysql_bin'];
	}
	else
	{
		// рядом с сервером: на своей машине и на тестовом сервере MySQL обычно тут же
		try
		{
			$base = $db ? (string)$db->val('SELECT @@basedir') : '';
		}
		catch (Throwable $e)
		{
			$base = '';
		}
		foreach (array_merge($base !== '' ? [rtrim($base, '/\\') . '/bin'] : [], dr_path_dirs()) as $d)
		{
			foreach (['mysql', 'mariadb'] as $n)
			{
				$cands[] = $d . '/' . $n . $exe;
			}
		}
	}
	$mysql = null;
	foreach (array_unique($cands) as $c)
	{
		$r = @is_file($c) ? dr_cmd_output([$c, '--version']) : null;
		if ($r && $r[0] === 0 && preg_match('/\b(Ver|Distrib)\b/', $r[1]))
		{
			$mysql = $c;
			break;
		}
	}
	if ($mysql === null)
	{
		return $no(is_string($cfg['mysql_bin']) && $cfg['mysql_bin'] !== '' ? 'клиент mysql из config.php (mysql_bin) не запускается: ' . $cfg['mysql_bin']
			: 'клиент mysql не найден ни рядом с сервером MySQL, ни в PATH — укажите путь в mysql_bin в config.php');
	}
	$cands = is_string($cfg['php_bin']) && $cfg['php_bin'] !== '' ? [$cfg['php_bin']] : dr_php_cli_candidates((string)PHP_BINARY, $win);
	foreach (dr_path_dirs() as $d)
	{
		$cands[] = $d . '/php' . $exe;
	}
	foreach (array_unique($cands) as $c)
	{
		$r = @is_file($c) ? dr_cmd_output([$c, '-r', 'echo PHP_VERSION_ID, " ", extension_loaded("mysqli") ? 1 : 0;']) : null;
		if ($r && $r[0] === 0 && preg_match('/^(\d+) 1$/', trim($r[1]), $m) && (int)$m[1] >= 70400)
		{
			return ['mysql' => $mysql, 'php' => $c, 'why' => ''];
		}
	}
	return $no('не найден PHP 7.4+ с mysqli для командной строки — укажите путь в php_bin в config.php');
}

/** Запустить фоновый процесс и не ждать его; вывод — в файл. */
function dr_spawn(array $args, string $log): bool
{
	if (DIRECTORY_SEPARATOR === '\\')
	{
		$q = static function (string $a): string {
			return '"' . str_replace('"', '', $a) . '"';
		};
		// start /B — без окна и без ожидания; stdin от NUL: процесс не держит канал веб-сервера
		$h = @popen('start "" /B ' . implode(' ', array_map($q, $args)) . ' < NUL >> ' . $q($log) . ' 2>&1', 'r');
	}
	else
	{
		$h = @popen('nohup ' . implode(' ', array_map('escapeshellarg', $args)) . ' < /dev/null >> ' . escapeshellarg($log) . ' 2>&1 &', 'r');
	}
	if (!$h)
	{
		return false;
	}
	pclose($h);
	return true;
}

function dr_fwrite_all($h, string $s): bool
{
	for ($len = strlen($s), $done = 0; $done < $len; $done += $w)
	{
		$w = @fwrite($h, $done ? substr($s, $done) : $s);
		if (!$w)
		{
			return false;
		}
	}
	return true;
}

function dr_tail(string $file, int $bytes = 1500): string
{
	$s = is_file($file) ? (string)@file_get_contents($file, false, null, max(0, (int)filesize($file) - $bytes)) : '';
	return trim((string)preg_replace('/[^\P{C}\n]/u', '', $s));
}

/**
 * Импорт дампа клиентом mysql. Сессия — как у restore.php для дампов Битрикс: SQL_MODE '', innodb_strict_mode = 0,
 * без проверок внешних ключей и уникальности, затем _after_connect.sql (кодировка, <DATABASE> — имя базы);
 * строки-дубли пропускаются (INSERT IGNORE — restore.php тоже пропускает ошибку 1062). Данные таблиц из групп
 * очистки не грузятся — их структура остаётся.
 */
function dr_import_run(array &$job): void
{
	$t0 = microtime(true);
	$p = dr_db_params();
	$parts = dr_dump_parts(DR_ROOT . '/bitrix/backup/' . $job['dump']);
	if (!$parts)
	{
		throw new RuntimeException('дамп ' . $job['dump'] . ' не найден в /bitrix/backup');
	}
	$job['total'] = (int)array_sum(array_map('filesize', $parts));
	$job['parts'] = count($parts);
	$rx = dr_like_rx((array)$job['skip']);
	$cnf = dr_import_file('cnf');
	$errFile = dr_import_file('err');
	$outFile = dr_import_file('out');
	try
	{
		if (file_put_contents($cnf, dr_mysql_cnf($p, (string)ini_get('mysqli.default_socket'))) === false)
		{
			throw new RuntimeException('не удалось записать файл параметров клиента mysql');
		}
		@chmod($cnf, 0600);
		// --unbuffered: ответ на первый запрос сразу попадает в файл вывода — по нему видно, что клиент подключился
		$args = [$job['mysql'], '--defaults-extra-file=' . $cnf, '--default-character-set=utf8mb4', '--binary-mode', '--unbuffered', '--max-allowed-packet=1G', '--database=' . $p['db']];
		$proc = proc_open($args, [0 => ['pipe', 'r'], 1 => ['file', $outFile, 'w'], 2 => ['file', $errFile, 'w']], $pipes, DR_ROOT, null, ['bypass_shell' => true]);
		if (!is_resource($proc))
		{
			throw new RuntimeException('не удалось запустить клиент mysql ' . $job['mysql']);
		}
		// файл с паролем клиент читает при старте: удаляем, как только он ответил на первый запрос (или завершился).
		// Чужие соединения с тем же сервером тут ничего не значат — ждём ответа именно этого клиента, не дольше 60 с.
		dr_fwrite_all($pipes[0], "SELECT 'devrestore: клиент подключён';\n");
		for ($i = 0; $i < 600; $i++)
		{
			clearstatcache(true, $outFile);
			if (!proc_get_status($proc)['running'] || (int)@filesize($outFile) > 0)
			{
				break;
			}
			usleep(100000);
		}
	}
	finally
	{
		@unlink($cnf);
	}
	$in = $pipes[0];
	$r = new DrDumpReader($parts);
	$skipped = [];
	$fail = '';
	$put = static function (string $sql) use ($in, &$fail): bool {
		if ($fail === '' && !dr_fwrite_all($in, $sql))
		{
			$fail = 'клиент mysql закрыл поток';
		}
		return $fail === '';
	};
	// UNIQUE_CHECKS не выключаем (restore.php тоже): MariaDB тогда грузит пустую таблицу пакетом, и повтор строки в дампе
	// молча откатывает уже вставленные строки транзакции, а на MySQL в уникальный индекс могли бы попасть дубли
	$head = "SET FOREIGN_KEY_CHECKS = 0;\nSET SQL_MODE = '';\nSET SESSION innodb_strict_mode = 0;\nSET autocommit = 0;\n";
	$after = dirname($parts[0]) . '/' . str_replace('.sql', '_after_connect.sql', basename($parts[0]));
	foreach (is_file($after) ? explode(';', (string)file_get_contents($after)) : [] as $sql)
	{
		$sql = trim(str_replace('<DATABASE>', $p['db'], $sql));
		if ($sql !== '')
		{
			$head .= $sql . ";\n";
		}
	}
	$put($head);
	$sinceCommit = 0;
	$lastSave = microtime(true);
	$neutral = array_flip(array_map('dr_neutral_table', DR_NEUTRAL_SQL));
	$lastIns = '';
	$early = [];
	$edb = null;
	try
	{
		while ($fail === '' && ($st = $r->next()) !== null)
		{
			$h = dr_dump_head($st);
			// строки таблицы агентов (почты, опций…) кончились — отключаем её сразу, не дожидаясь остального дампа
			if ($h && $h[1] !== $lastIns && isset($neutral[$lastIns]) && !isset($early[$lastIns]))
			{
				$ok = dr_import_early($proc, $put, $outFile, $lastIns, count($early) + 1, $edb);
				$early[$lastIns] = $ok ? round(microtime(true) - $t0, 1) : false;
				dr_logf('Импорт: ' . $lastIns . ($ok ? ' загружена и сразу отключена' : ' загружена, отключить сразу не удалось — отключится в конце'));
				$sinceCommit = 0;
			}
			if ($h && $h[0] === 'insert' && $rx !== null && preg_match($rx, $h[1]))
			{
				$skipped[$h[1]] = ($skipped[$h[1]] ?? 0) + strlen($st);
			}
			else
			{
				if ($h && $h[0] === 'insert' && substr_compare($st, 'INSERT INTO', $h[2], 11) === 0)
				{
					$st = substr_replace($st, 'INSERT IGNORE INTO', $h[2], 11);
				}
				$put($st);
				$sinceCommit += strlen($st);
				if ($h && $h[0] === 'insert')
				{
					$lastIns = $h[1];
				}
				if ($sinceCommit > 64 * 1048576)
				{
					$put("COMMIT;\n");
					$sinceCommit = 0;
				}
			}
			if (microtime(true) - $lastSave > 1)
			{
				$job['pos'] = $r->pos;
				$job['skipped_bytes'] = (int)array_sum($skipped);
				$job['beat'] = time();
				dr_import_job_save($job);
				$lastSave = microtime(true);
			}
		}
		$put("COMMIT;\nSET FOREIGN_KEY_CHECKS = 1;\n");
	}
	finally
	{
		@fclose($in);
		$code = proc_close($proc);
	}
	$job['early'] = $early;
	$job['pos'] = $r->pos;
	$job['skipped'] = $skipped;
	$job['skipped_bytes'] = (int)array_sum($skipped);
	$job['tail'] = $r->tail;
	if ($code !== 0 || $fail !== '')
	{
		throw new RuntimeException('клиент mysql завершился с ошибкой (код ' . $code . '): ' . (dr_tail($errFile) ?: $fail));
	}
	$job['import_sec'] = round(microtime(true) - $t0, 1);
	// сразу, в этом же процессе: между концом импорта и отключением агентов и почты нет окна для веб-хита
	$db = DrDb::connect();
	$job['neutral'] = dr_import_neutralize($db);
	$job['fixes'] = dr_import_site_fixes($db, (string)$job['host']);
}

/** Отключить внешние действия прода (то же, что вставка шага 3 в restore.php); таблицы нет — пропускаем. $table — только для неё. */
function dr_import_neutralize(DrDb $db, ?string $table = null): int
{
	$n = 0;
	foreach (DR_NEUTRAL_SQL as $sql)
	{
		if ($table !== null && dr_neutral_table($sql) !== $table)
		{
			continue;
		}
		try
		{
			$db->exec($sql, false);
			$n++;
		}
		catch (Throwable $e)
		{
		}
	}
	return $n;
}

/** Таблица, которую меняет строка DR_NEUTRAL_SQL. */
function dr_neutral_table(string $sql): string
{
	return preg_match('/^(?:UPDATE|DELETE\s+FROM)\s+(\w+)/i', $sql, $m) ? strtolower($m[1]) : '';
}

/**
 * Таблица из DR_NEUTRAL_SQL загружена: в поток — COMMIT и метка; как только клиент до метки дошёл, таблица
 * отключается своим соединением. Так агенты и почта прода не ждут конца импорта. Ошибка тут импорт не роняет:
 * в конце (и при сбое) всё отключается ещё раз.
 */
function dr_import_early($proc, callable $put, string $outFile, string $table, int $n, ?DrDb &$db): bool
{
	$mark = 'devrestore-mark-' . $n;
	if (!$put("COMMIT;\nSELECT '$mark';\n"))
	{
		return false;
	}
	for ($i = 0; $i < 3000; $i++)
	{
		clearstatcache(true, $outFile);
		if (strpos((string)@file_get_contents($outFile), $mark) !== false)
		{
			try
			{
				$db = $db ?: DrDb::connect();
				return dr_import_neutralize($db, $table) > 0;
			}
			catch (Throwable $e)
			{
				return false;
			}
		}
		if (!proc_get_status($proc)['running'])
		{
			return false;
		}
		usleep(100000);
	}
	return false;
}

/** Что делает шаг 4 restore.php для копии на другом хосте: корень сайтов, CDN Битрикс, ограничение хостов. */
function dr_import_site_fixes(DrDb $db, string $host): array
{
	$out = [];
	try
	{
		if ($db->hasTable('b_lang') && $db->exec("UPDATE b_lang SET DOC_ROOT = '' WHERE DOC_ROOT IS NOT NULL AND DOC_ROOT <> '' AND DOC_ROOT <> " . dr_lit(DR_ROOT), false))
		{
			$out[] = 'сброшен корень сайтов (DOC_ROOT)';
		}
		if ($db->hasTable('b_bitrixcloud_option') && $db->val("SELECT 1 FROM b_bitrixcloud_option WHERE NAME = 'cdn_config_active' AND PARAM_VALUE = 1")
			&& (string)$db->val("SELECT PARAM_VALUE FROM b_bitrixcloud_option WHERE NAME = 'cdn_config_domain'") !== $host)
		{
			$db->exec("UPDATE b_bitrixcloud_option SET PARAM_VALUE = 0 WHERE NAME = 'cdn_config_active'", false);
			$db->exec("UPDATE b_bitrixcloud_option SET PARAM_VALUE = " . (time() + 86400 * 3650) . " WHERE NAME = 'cdn_config_expire_time'", false);
			$out[] = 'выключен CDN Битрикс (настроен на домен прода)';
		}
		$hr = $db->hasTable('b_module_to_module') ? $db->one("SELECT ID FROM b_module_to_module WHERE FROM_MODULE_ID = 'main' AND MESSAGE_ID = 'OnPageStart'"
			. ' AND TO_CLASS = ' . dr_lit('Bitrix\\Security\\HostRestriction')) : null;
		if ($hr && strpos((string)$db->option('security', 'restriction_hosts_hosts'), $host) === false)
		{
			$db->exec('DELETE FROM b_module_to_module WHERE ID = ' . (int)$hr['ID'], false);
			$out[] = 'снято ограничение хостов модуля «Проактивная защита» (этого хоста в списке нет)';
		}
	}
	catch (Throwable $e)
	{
		$out[] = 'не выполнено: ' . $e->getMessage();
	}
	return $out;
}

/** Отключить всё из DR_NEUTRAL_SQL после сбоя импорта; число выполненных строк, -1 — база недоступна. */
function dr_import_neutralize_safe(string $why): int
{
	try
	{
		$n = dr_import_neutralize(DrDb::connect());
		dr_logf('Импорт: ' . $why . ' отключены агенты, очередь писем, вебхуки и почтовые ящики (' . $n . ' из ' . count(DR_NEUTRAL_SQL) . ')');
		return $n;
	}
	catch (Throwable $e)
	{
		dr_logf('Импорт: ' . $why . ' отключить агенты и почту не удалось: ' . $e->getMessage());
		return -1;
	}
}

/** Фоновый процесс: php devrestore.php --dr-import=<токен>. Запускается со страницы импорта. */
function dr_import_cli(array $argv): void
{
	$token = '';
	foreach (array_slice($argv, 1) as $a)
	{
		if (strncmp((string)$a, '--dr-import=', 12) === 0)
		{
			$token = substr((string)$a, 12);
		}
	}
	dr_config();
	// второй процесс того же импорта не стартует: блокировку держит первый
	$lock = @fopen(dr_import_file('lock'), 'c');
	if (!$lock || !flock($lock, LOCK_EX | LOCK_NB))
	{
		fwrite(STDERR, "devrestore: импорт уже идёт\n");
		exit(1);
	}
	$job = dr_import_job();
	$state = dr_state_load();
	if ($token === '' || !$job || !hash_equals((string)$job['token'], $token) || !hash_equals((string)($state['token'] ?? ''), $token) || ($job['status'] ?? '') !== 'starting')
	{
		fwrite(STDERR, "devrestore: нет задания импорта с таким токеном\n");
		exit(2);
	}
	$_SERVER['HTTP_HOST'] = (string)$job['host'];
	$job['status'] = 'running';
	$job['beat'] = time();
	dr_import_job_save($job);
	dr_logf('Импорт: клиент mysql, дамп ' . $job['dump']);
	try
	{
		dr_import_run($job);
		$job['status'] = 'done';
		dr_logf('Импорт: готово за ' . dr_duration((float)$job['import_sec']) . ', пропущены данные ' . count((array)$job['skipped']) . ' таблиц ('
			. dr_human_size((float)$job['skipped_bytes']) . '), агенты и почта отключены' . ($job['fixes'] ? '; ' . implode('; ', $job['fixes']) : ''));
	}
	catch (Throwable $e)
	{
		$job['status'] = 'error';
		$job['error'] = $e->getMessage();
		dr_logf('Импорт: ОШИБКА ' . $e->getMessage());
		// в базе часть прода: агенты, письма и вебхуки, которые уже загрузились, не должны ожить, пока человек читает ошибку
		$job['neutral'] = dr_import_neutralize_safe('после ошибки');
	}
	$job['finished'] = time();
	dr_import_job_save($job);
	flock($lock, LOCK_UN);
	exit($job['status'] === 'done' ? 0 : 3);
}

/** Страница импорта (?phase=import) и её опрос (?phase=import_status): запуск фонового процесса, ход, переход к обработке. */
function dr_import_page(array $cfg, array $state, bool $json): void
{
	$token = (string)($_GET['token'] ?? '');
	if ($token === '' || empty($state['token']) || !hash_equals((string)$state['token'], $token))
	{
		if ($json)
		{
			dr_json(['ok' => false, 'error' => 'Ссылка импорта устарела. Обработать базу можно с главной страницы.']);
		}
		dr_page('Импорт', '<div class="alert alert-warning">Ссылка импорта устарела. Обработать базу можно с главной страницы.</div>');
	}
	$job = dr_import_job();
	if ($job && !hash_equals((string)$job['token'], $token))
	{
		$job = null; // задание прошлого восстановления
	}
	$alive = dr_import_alive();
	$status = (string)($job['status'] ?? '');
	if (!$alive && in_array($status, ['starting', 'running'], true) && ($status === 'running' || time() - (int)$job['started'] > 30))
	{
		$status = 'died';
		// процесс убит (например, вместе с php-cgi) и сам ничего не отключил — делаем это здесь, один раз
		if (!isset($job['neutral_died']))
		{
			$job['neutral_died'] = dr_import_neutralize_safe('процесс импорта прервался —');
			dr_import_job_save($job);
		}
	}
	$retry = !$json && isset($_GET['retry']) && in_array($status, ['error', 'died'], true);
	if (!$json && !$alive && ($job === null || $retry))
	{
		$imp = (array)($state['restore']['import'] ?? []);
		$dump = (string)preg_replace('/\.\d+$/', '', basename((string)($_GET['dump_name'] ?? '')));
		if (empty($imp['mysql']) || empty($imp['php']))
		{
			dr_fatal('Быстрый импорт не подготовлен', 'Подготовьте восстановление заново с главной страницы.', 400);
		}
		if (!preg_match('/\.sql$/i', $dump) || !is_file(DR_ROOT . '/bitrix/backup/' . $dump))
		{
			dr_fatal('Дамп не найден', 'В /bitrix/backup нет ' . $dump . '. Вернитесь в restore.php и выберите дамп.', 400);
		}
		$job = [
			'token' => $token, 'dump' => $dump, 'arc' => basename((string)($_GET['arc_name'] ?? '')), 'host' => dr_host(),
			'mysql' => (string)$imp['mysql'], 'skip' => !empty($imp['skip']) ? dr_import_skip_patterns(dr_rules($cfg)) : [],
			'status' => 'starting', 'started' => time(), 'unpacked_sec' => time() - (int)($state['restore']['at'] ?? time()), 'pos' => 0,
			'total' => (int)array_sum(array_map('filesize', dr_dump_parts(DR_ROOT . '/bitrix/backup/' . $dump))),
		];
		dr_import_job_save($job);
		@unlink(dr_import_file('log'));
		if (!dr_spawn([(string)$imp['php'], DR_ROOT . '/' . DR_SELF, '--dr-import=' . $token], dr_import_file('log')))
		{
			$job['status'] = 'error';
			$job['error'] = 'не удалось запустить фоновый процесс ' . $imp['php'];
			dr_import_job_save($job);
		}
		$status = $job['status'];
		dr_logf('Импорт: запуск фонового процесса (' . $imp['php'] . ')');
	}
	$after = '/' . DR_SELF . '?phase=after&token=' . rawurlencode($token) . '&arc_name=' . rawurlencode((string)($job['arc'] ?? '')) . '&dump_name=' . rawurlencode((string)($job['dump'] ?? ''));
	$error = $status === 'died' ? 'фоновый процесс импорта прервался. ' . dr_tail(dr_import_file('log'), 500) : (string)($job['error'] ?? '');
	if ($json)
	{
		dr_json(['ok' => true, 'status' => $status, 'pos' => (int)($job['pos'] ?? 0), 'total' => (int)($job['total'] ?? 0),
			'skipped' => (int)($job['skipped_bytes'] ?? 0), 'sec' => time() - (int)($job['started'] ?? time()), 'error' => $error, 'url' => $after]);
	}
	if ($status === 'done')
	{
		dr_redirect($after);
	}
	$statusUrl = dr_self_url(['phase' => 'import_status', 'token' => $token]);
	$retryUrl = dr_self_url(['phase' => 'import', 'token' => $token, 'retry' => 1, 'dump_name' => (string)($job['dump'] ?? ''), 'arc_name' => (string)($job['arc'] ?? '')]);
	$body = '<div class="card"><h1>Импорт дампа клиентом mysql</h1>'
		. '<p>Дамп <b>' . dr_h((string)($job['dump'] ?? '')) . '</b> (' . dr_h(dr_human_size((float)($job['total'] ?? 0))) . ') грузится в фоне; данные таблиц, которые обработка всё равно очищает, пропускаются.'
		. ' Сразу после импорта будут отключены агенты и почта. Страницу можно закрыть — импорт продолжится; вернуться сюда — по этой же ссылке.</p>'
		. '<div class="progress"><div id="bar" style="width:0"></div></div><p id="lbl" class="muted">Запуск…</p>'
		. '<div id="err" class="alert alert-danger" style="display:none"></div>'
		. '<p id="retry" style="display:none"><a class="btn" href="' . dr_h($retryUrl) . '">Начать импорт заново</a></p></div>';
	$js = 'var U=' . json_encode($statusUrl) . ";\n" . <<<'JS'
(function () {
	var bar = document.getElementById('bar'), lbl = document.getElementById('lbl');
	function mb(b) { return (b / 1048576).toFixed(0) + ' МБ'; }
	function poll() {
		fetch(U, {headers: {'X-Requested-With': 'fetch'}}).then(function (r) { return r.json(); }).then(function (j) {
			if (!j.ok || j.status === 'error' || j.status === 'died') {
				var e = document.getElementById('err');
				e.style.display = '';
				e.textContent = 'Импорт не удался: ' + (j.error || 'неизвестная ошибка');
				document.getElementById('retry').style.display = '';
				return;
			}
			if (j.status === 'done') { location.replace(j.url); return; }
			var f = j.total ? j.pos / j.total : 0;
			bar.style.width = Math.round(f * 100) + '%';
			lbl.textContent = mb(j.pos) + ' из ' + mb(j.total) + (j.skipped ? ', пропущено ' + mb(j.skipped) : '') + ' — ' + j.sec + ' с';
			setTimeout(poll, 1000);
		}).catch(function () { setTimeout(poll, 3000); });
	}
	poll();
})();
JS;
	dr_page('Импорт', $body, $js);
}

/** Строка отчёта об импорте: способ, время, размер, пропущенные данные. */
function dr_import_summary(array $imp): string
{
	if (($imp['mode'] ?? '') === 'mysql')
	{
		$s = 'клиент mysql: ' . dr_duration((float)($imp['import_sec'] ?? 0)) . ', дамп ' . dr_human_size((float)($imp['total'] ?? 0))
			. ((int)($imp['parts'] ?? 1) > 1 ? ' (' . (int)$imp['parts'] . ' тома)' : '');
		if (!empty($imp['skipped']))
		{
			$s .= '; при импорте пропущены данные ' . count((array)$imp['skipped']) . ' таблиц, ' . dr_human_size((float)$imp['skipped_bytes']);
		}
		if (isset($imp['unpacked_sec']))
		{
			$s .= '; распаковка в restore.php (с ожиданием) — ' . dr_duration((float)$imp['unpacked_sec']);
		}
		return $s . (!empty($imp['fixes']) ? '; ' . implode('; ', (array)$imp['fixes']) : '');
	}
	return 'restore.php: распаковка и импорт — ' . dr_duration((float)($imp['sec'] ?? 0)) . ' (от подготовки до возврата, с ожиданием)'
		. (!empty($imp['why']) ? '. Не клиентом mysql: ' . $imp['why'] : '');
}

/** Опции модулей разработки из текущей (локальной) базы — до того, как restore.php её перезапишет. */
function dr_capture_dev_modules(array $cfg): array
{
	$saved = [];
	if (!$cfg['dev_modules'])
	{
		return $saved;
	}
	try
	{
		$db = DrDb::connect();
		foreach ((array)$cfg['dev_modules'] as $module => $names)
		{
			foreach ((array)$names as $name)
			{
				$v = $db->option((string)$module, (string)$name);
				if ($v !== null && $v !== '')
				{
					$saved[$module][$name] = $v;
				}
			}
		}
	}
	catch (Throwable $e)
	{
		// базы ещё нет — сохранять нечего
	}
	return $saved;
}

function dr_action_prepare(array $cfg, array &$state): void
{
	dr_check_csrf($state);
	$host = dr_host();
	if (trim((string)($_POST['confirm_domain'] ?? '')) !== $host)
	{
		dr_fatal('Домен не подтверждён', 'Введённый домен не совпадает с текущим (' . $host . ').', 400);
	}
	[$db, $info] = dr_try_db();
	if ($info && $info['looks_prod'] && empty($_POST['not_prod']))
	{
		dr_fatal('Похоже на боевой сайт', 'В текущей базе server_name совпадает с этим хостом, а отметки devrestore нет. Если это точно не боевой сайт — отметьте подтверждение в форме.', 400);
	}
	// Сначала готовим restore.php в памяти: если не получится, ничего на диске ещё не тронуто.
	$restore = DR_ROOT . '/restore.php';
	$log = [];
	if (($_POST['restore_source'] ?? 'download') === 'download')
	{
		$code = dr_http_get(DR_RESTORE_URL);
		if (!dr_is_restore_script($code))
		{
			dr_fatal('Скачан не restore.php', 'Ответ ' . DR_RESTORE_URL . ' не похож на скрипт восстановления. Положите restore.php в корень вручную и выберите «использовать лежащий».', 502);
		}
		$log[] = 'скачан свежий restore.php (' . dr_human_size(strlen($code)) . ')';
	}
	else
	{
		$code = is_file($restore) ? (string)file_get_contents($restore) : '';
		if (!dr_is_restore_script($code))
		{
			dr_fatal('Нет restore.php', 'В корне сайта нет restore.php от Битрикс.', 400);
		}
	}
	$token = bin2hex(random_bytes(16));
	// быстрый импорт (клиент mysql) — если он есть и не выключен в форме; иначе дамп грузит сам restore.php
	$tools = ($_POST['fast_import'] ?? '1') !== '0' ? dr_import_tools($cfg, $db) : ['mysql' => null, 'php' => null, 'why' => 'выключен в форме подготовки'];
	$patched = dr_patch_restore($code, $token, $tools['mysql'] !== null, $fastOk);
	if ($patched === null)
	{
		// при сбое — отказ: без вставок первая же страница после восстановления запустит агенты и почту прода
		dr_fatal('restore.php не удалось подготовить', 'В этой версии restore.php не найдены места для вставок (самообновление, шаги 3 и 5) — нужна правка devrestore.php. Ничего не изменено.', 500);
	}
	if (file_put_contents($restore, $patched) === false)
	{
		dr_fatal('Не удалось записать restore.php', 'Нет прав на запись в корень сайта.', 500);
	}
	// перечитать с диска: записанное должно совпасть (антивирус, синхронизация папки или чужой процесс могли подменить файл)
	clearstatcache(true, $restore);
	if (!dr_restore_patched((string)@file_get_contents($restore)))
	{
		@unlink($restore);
		dr_fatal('restore.php записан без вставок', 'После записи в restore.php не оказалось вставок devrestore — файл удалён, чтобы восстановление не оживило агенты и почту прода. Подготовьте заново.', 500);
	}
	$log[] = 'restore.php: самообновление выключено, отключение агентов и почты сразу после импорта, возврат сюда после восстановления';
	$import = ['mode' => $fastOk ? 'mysql' : 'restore', 'mysql' => $fastOk ? $tools['mysql'] : null, 'php' => $fastOk ? $tools['php'] : null,
		'skip' => $fastOk && ($_POST['skip_truncated'] ?? ($cfg['import_skip_truncated'] ? '1' : '0')) !== '0',
		'why' => $tools['mysql'] !== null && !$fastOk ? 'в этой версии restore.php не найдено место вставки в шаге 3' : (string)$tools['why']];
	$log[] = $fastOk ? 'импорт дампа: клиент mysql ' . $import['mysql'] . ' в фоне (PHP ' . $import['php'] . '), restore.php только распаковывает'
		. ($import['skip'] ? '; данные очищаемых таблиц не грузятся' : '') : 'импорт дампа: restore.php (' . $import['why'] . ')';

	$archives = dr_scan_archives();
	$keep = (string)($_POST['keep_archive'] ?? '');
	if (!empty($_POST['delete_other_archives']))
	{
		foreach ($archives as $g)
		{
			if ($g['base'] === $keep)
			{
				continue;
			}
			foreach ($g['files'] as $f)
			{
				$log[] = (@unlink(DR_ROOT . '/' . $f) ? 'удалён архив ' : 'НЕ удалось удалить ') . $f;
			}
		}
	}
	if (!empty($_POST['delete_dumps']))
	{
		foreach (dr_scan_dumps() as $d)
		{
			$log[] = (@unlink(DR_ROOT . '/bitrix/backup/' . $d['name']) ? 'удалён дамп ' : 'НЕ удалось удалить ') . $d['name'];
		}
	}
	$state['token'] = $token;
	$state['restore'] = ['arc' => $keep, 'at' => time(), 'dev_modules' => dr_capture_dev_modules($cfg), 'import' => $import];
	@unlink(dr_import_file('job'));
	foreach ($state['restore']['dev_modules'] as $module => $opts)
	{
		$log[] = 'сохранены опции модуля ' . $module . ': ' . implode(', ', array_keys($opts));
	}
	dr_state_save($state);
	foreach ($log as $l)
	{
		dr_logf('Подготовка: ' . $l);
	}
	dr_redirect('/restore.php?lang=ru');
}

// ============================================================================
// Фаза B: запуск обработки
// ============================================================================

/**
 * Режим проверки утечек: quick, full или '' (не проверять). true из старых профилей и формы («отмечено») —
 * режим из config.php, а если там тоже true — quick.
 */
function dr_leak_mode($v, $cfgV = 'quick'): string
{
	if ($v === 'quick' || $v === 'full')
	{
		return $v;
	}
	if ($v === true || $v === 1 || $v === '1')
	{
		return $cfgV === 'full' ? 'full' : 'quick';
	}
	return '';
}

function dr_default_opts(array $cfg, ?array $profile): array
{
	return [
		'scripts' => (array)($profile['scripts'] ?? []),
		'text_mode' => (string)($profile['text_mode'] ?? $cfg['text_mode']),
		'companies' => (string)($profile['companies'] ?? $cfg['companies']),
		'single_surname' => (bool)$cfg['single_surname'],
		'skip_groups' => (array)($profile['skip_groups'] ?? dr_default_off_groups($cfg)),
		'leak_scan' => dr_leak_mode($profile['leak_scan'] ?? $cfg['leak_scan'], $cfg['leak_scan']),
		'cleanup' => true,
		'self_delete' => $cfg['self_delete'] === 'auto' ? !dr_is_loopback() : (bool)$cfg['self_delete'],
	];
}

function dr_build_run(array $cfg, array &$state, array $opts, string $profileKey): void
{
	$db = DrDb::connect();
	$info = dr_detect($db);
	$host = dr_host();
	if ($profileKey === '')
	{
		throw new RuntimeException('Не определён боевой домен (server_name) — укажите имя профиля вручную.');
	}
	if (in_array((string)preg_replace('/^www\./', '', $profileKey), dr_host_variants(), true) || $info['looks_prod'])
	{
		throw new RuntimeException('Домен из базы совпадает с текущим хостом «' . $host . '», отметки обезличивания нет. Обработка запрещена.');
	}
	$warn = [];
	$rxOk = $db->supportsRegexpReplace();
	if ($opts['text_mode'] === 'scrub' && !$rxOk)
	{
		$opts['text_mode'] = 'strict';
		$warn[] = 'Режим «вычищать» требует REGEXP_REPLACE (MySQL 8+ / MariaDB 10.0.5+) — используется «заменять целиком».';
	}
	$opts += ['companies' => (string)$cfg['companies'], 'single_surname' => (bool)$cfg['single_surname']];
	$dict = $opts['text_mode'] === 'dict';
	try
	{
		$logs = $db->one('SELECT @@general_log AS g, @@log_bin AS b');
		if ((int)($logs['g'] ?? 0) === 1)
		{
			$warn[] = 'На сервере MySQL включён general_log: в нём останутся запросы обработки вместе с солью. Очистите его после обработки.';
		}
		if ((int)($logs['b'] ?? 0) === 1)
		{
			$warn[] = 'На сервере MySQL включён binlog: в нём исходный дамп прода и запросы с солью. На машине разработчика выполните после обработки RESET MASTER (или PURGE BINARY LOGS).';
		}
	}
	catch (Throwable $e)
	{
	}
	$salt = bin2hex(random_bytes(16));
	// в задачах — метка, соль подставляет DrDb при выполнении
	$an = new DrAnon(DR_SALT_MARK, $db->cs, $opts['text_mode'], $rxOk, $opts['companies']);
	$profiles = dr_profiles();
	$profile = (array)($profiles[$profileKey] ?? []);

	$rules = dr_rules($cfg);
	$clientSql = [];
	$ufKeep = [];
	$optKeep = [];
	foreach ($opts['scripts'] as $file)
	{
		$m = dr_script_manifest($file);
		if (!$m)
		{
			$warn[] = 'Скрипт ' . $file . ' не найден или не вернул массив — пропущен.';
			continue;
		}
		$title = (string)($m['title'] ?? $file);
		foreach ((array)($m['anonymize'] ?? []) as $r)
		{
			$rules[] = ['group' => 'Скрипт: ' . $title] + (array)$r;
		}
		foreach ((array)($m['sql'] ?? []) as $sql)
		{
			$clientSql[] = ['sql', '', (string)$sql];
		}
		$ufKeep = array_merge($ufKeep, array_map('strval', (array)($m['uf_keep'] ?? [])));
		$optKeep = array_merge($optKeep, array_map('strval', (array)($m['options_keep'] ?? [])));
	}

	// Нераспознанные строковые UF CRM и пользователей идут как текст: в dict чистятся по словарю, в strict заменяются целиком,
	// в scrub — вычищаются. Решения по полям — из профиля (страница «Поля клиента») и uf_keep скриптов.
	$notes = [];
	$tasks = dr_build_tasks($db, $an, $rules, $opts['skip_groups'], $ufKeep, (array)($profile['uf'] ?? []), max(1000, (int)$cfg['clean_max_rows']), $notes);
	// options_keep скриптов: эти опции шаг «Секреты» оставляет с прод-значением (например, ключ платного API для ручных прогонов)
	$optKeep = array_values(array_unique(array_filter(array_map('trim', $optKeep), function ($k) { return strpos($k, '/') > 0; })));
	if ($optKeep)
	{
		foreach ($tasks as $i => $t)
		{
			foreach ($t['kind'] === 'ops' ? $t['ops'] : [] as $j => $op)
			{
				if ($op[0] === 'options_secrets')
				{
					$tasks[$i]['ops'][$j][1] = $optKeep;
				}
			}
		}
	}
	if ($dict)
	{
		array_unshift($tasks, dr_dict_task($db, $an, $cfg));
	}
	if ($clientSql)
	{
		$tasks[] = ['kind' => 'ops', 'label' => 'SQL из скриптов клиента', 'ops' => $clientSql];
	}
	// Проверка — до удаления словаря (по нему ищутся имена) и до отметки (в неё попадает число предупреждений).
	if ($opts['leak_scan'])
	{
		$tasks[] = ['kind' => 'leak', 'label' => $opts['leak_scan'] === 'full' ? 'Проверка на утечки (e-mail и телефоны во всех текстовых полях)'
			: 'Проверка на утечки, быстрая (e-mail и телефоны в колонках вне правил, последние ' . (int)$cfg['leak_quick_rows'] . ' строк)'];
		if ($dict)
		{
			$tasks[] = ['kind' => 'leak_names', 'label' => 'Проверка на утечки: имена людей (последние 300 строк каждой таблицы)'];
		}
	}
	// соль бывает в тексте запросов во всех режимах: история performance_schema чистится всегда
	$tasks[] = ['kind' => 'drop_dict', 'label' => $dict ? 'Удаление словаря людей и истории запросов (в ней соль)' : 'Очистка истории запросов performance_schema (в ней соль)'];
	$tasks[] = ['kind' => 'marker', 'label' => 'Отметка «обезличено» в базе'];
	$tasks[] = ['kind' => 'devsettings', 'label' => 'Dev-настройки: домен сайта, server_name, куки'];
	$tasks[] = ['kind' => 'cleanup', 'label' => 'Уборка: кеш' . ($opts['cleanup'] ? ', архив, дампы, restore.php' : '')];
	$tasks[] = ['kind' => 'kernel', 'label' => 'Ядро: dev-админ, модули разработки, скрипты клиента'];

	// Строковые UF: новые поля известного клиента — в отчёт (получили решение по умолчанию)
	$ufNow = [];
	foreach (dr_uf_list($db) as $uf)
	{
		if (($uf['type'] === 'string' || $uf['type'] === 'string_formatted') && preg_match('/^(CRM_|USER$)/', strtoupper($uf['entity'])))
		{
			$ufNow[] = dr_uf_key($uf);
		}
	}
	$ufNew = isset($profile['uf_known']) ? array_values(array_diff($ufNow, (array)$profile['uf_known'])) : [];

	$truncated = [];
	foreach ($tasks as $t)
	{
		if ($t['kind'] === 'truncate')
		{
			$truncated = array_merge($truncated, $t['tables']);
		}
	}

	// как шла заливка — для отчёта: фоновый импорт клиентом mysql или restore.php (время от подготовки до возврата)
	$import = null;
	if (!empty($state['token']))
	{
		$job = dr_import_job();
		if ($job && hash_equals((string)$job['token'], (string)$state['token']) && ($job['status'] ?? '') === 'done')
		{
			$import = ['mode' => 'mysql'] + array_intersect_key($job, array_flip(['import_sec', 'total', 'parts', 'skipped', 'skipped_bytes', 'unpacked_sec', 'fixes']));
		}
		elseif (!empty($state['restore']['at']))
		{
			$import = ['mode' => 'restore', 'sec' => time() - (int)$state['restore']['at'], 'why' => (string)($state['restore']['import']['why'] ?? '')];
		}
	}
	$state['token'] = '';
	$state['run'] = [
		'token' => bin2hex(random_bytes(16)),
		'import' => $import,
		'salt' => $salt,
		'tasks' => $tasks,
		'i' => 0,
		'log' => [],
		'sent' => 0,
		'started' => time(),
		'host' => $host,
		'prod' => $profileKey,
		'site' => $info['site'],
		'opts' => $opts,
		'truncated' => $truncated,
		'dev_modules' => (array)($state['restore']['dev_modules'] ?? []),
		'arc' => (string)($state['restore']['arc'] ?? ''),
		'server' => $db->server,
		'report' => ['leaks' => [], 'leak_names' => [], 'leak_skipped' => [], 'warn' => $warn, 'strict_tables' => array_values(array_filter($notes, 'is_string')),
			'struct_skipped' => (array)($notes['struct'] ?? []), 'struct_broken' => [], 'uf_new' => $ufNew,
			'missing_tables' => dr_missing_rule_tables($db, $rules, $opts['skip_groups'])],
	];
	dr_run_log($state['run'], 'Старт: ' . $profileKey . ' → ' . $host . ', ' . count($tasks) . ' шагов, БД ' . $db->server . ', текст: ' . dr_text_mode_name($opts['text_mode']));
	foreach ($warn as $w)
	{
		dr_run_log($state['run'], $w, 'warn');
	}

	// профиль дополняется, а не перезаписывается: решения по полям и прочее, что сюда не относится, сохраняются
	$profiles[$profileKey] = array_replace($profile, [
		'scripts' => array_values($opts['scripts']),
		'text_mode' => $opts['text_mode'],
		'companies' => $opts['companies'],
		'skip_groups' => array_values($opts['skip_groups']),
		'leak_scan' => $opts['leak_scan'],
		'uf_known' => $ufNow,
		'dev_hosts' => array_values(array_unique(array_merge((array)($profile['dev_hosts'] ?? []), [$host]))),
		'updated' => date('Y-m-d H:i:s'),
	]);
	dr_profiles_save($profiles);
	dr_state_save($state);
}

function dr_opts_from_post(array $cfg): array
{
	$groups = array_keys(dr_rule_groups($cfg));
	$checked = (array)($_POST['groups'] ?? []);
	$scripts = array_values(array_filter((array)($_POST['scripts'] ?? []), static function ($s) {
		return is_file(dr_home() . '/scripts/' . basename((string)$s));
	}));
	return [
		'scripts' => array_map('basename', $scripts),
		'text_mode' => in_array($_POST['text_mode'] ?? '', ['dict', 'strict', 'scrub'], true) ? (string)$_POST['text_mode'] : 'strict',
		'companies' => ($_POST['companies'] ?? '') === 'all' ? 'all' : 'individuals',
		'single_surname' => (bool)$cfg['single_surname'],
		'skip_groups' => array_values(array_diff($groups, $checked)),
		'leak_scan' => dr_leak_mode((string)($_POST['leak_scan'] ?? ''), $cfg['leak_scan']),
		'cleanup' => !empty($_POST['cleanup']),
		'self_delete' => !empty($_POST['self_delete']),
	];
}

// ============================================================================
// Фаза B: выполнение шагов
// ============================================================================

/** Соль не должна попасть ни в журнал, ни в отчёт: зная её, имена можно подобрать по словарю. */
function dr_redact(array $run, string $msg): string
{
	return empty($run['salt']) ? $msg : str_replace((string)$run['salt'], '***', $msg);
}

/** Пример находки для отчёта: только само совпадение (не окружающий текст с именами), замаскированное. */
function dr_leak_sample(string $s): string
{
	$rx = '/[\p{L}\p{N}._%+-]+@[\p{L}\p{N}.-]+\.\p{L}{2,}|(?:\+7|(?<!\d)8)[ (-]*\d{3}[ )-]*\d{3}[ -]*\d{2}[ -]*\d{2}|(?<!\d)[78][3-9]\d{9}(?!\d)/u';
	if (!preg_match_all($rx, $s, $m))
	{
		return '—';
	}
	foreach ($m[0] as $hit)
	{
		if (stripos($hit, '@example.test') !== false || strpos($hit, '+7000') === 0)
		{
			continue;
		}
		if (strpos($hit, '@') !== false)
		{
			return mb_substr($hit, 0, 1) . '***' . substr($hit, (int)strpos($hit, '@'));
		}
		return '***' . substr((string)preg_replace('/\D/', '', $hit), -2);
	}
	return '—';
}

function dr_run_log(array &$run, string $msg, string $lvl = 'info'): void
{
	$msg = dr_redact($run, $msg);
	$run['log'][] = [$lvl, date('H:i:s ') . $msg];
	dr_logf(strtoupper($lvl) . ' ' . $msg);
}

function dr_step(array &$state, array $cfg, bool $kernel): void
{
	$run =& $state['run'];
	$deadline = microtime(true) + max(3, (int)$cfg['step_seconds']);
	$db = null;
	$total = count($run['tasks']);
	while ($run['i'] < $total && microtime(true) < $deadline)
	{
		$t =& $run['tasks'][$run['i']];
		if ($t['kind'] === 'kernel' && !$kernel)
		{
			unset($t);
			break; // ядро подключается только в отдельном запросе
		}
		$t0 = microtime(true);
		try
		{
			if ($t['kind'] === 'kernel')
			{
				dr_task_kernel($run, $t, $cfg);
			}
			else
			{
				if ($db === null)
				{
					$db = DrDb::connect();
					$db->redact = (string)($run['salt'] ?? '');
					$db->salt = (string)($run['salt'] ?? '');
					try
					{
						// долгие запросы с солью не должны попасть в slow log
						$db->q('SET SESSION long_query_time = 31536000');
					}
					catch (Throwable $e)
					{
					}
				}
				dr_task_run($db, $run, $t, $cfg, $deadline);
			}
		}
		catch (Throwable $e)
		{
			// обрыв соединения: шаг повторяется с той же пачки (UPDATE атомарен, пачка либо применилась, либо нет)
			if (in_array((int)$e->getCode(), [2006, 2013], true) && (int)($t['retries'] ?? 0) < 3)
			{
				$t['retries'] = (int)($t['retries'] ?? 0) + 1;
				$db = null;
				dr_run_log($run, $t['label'] . ': соединение с БД потеряно, повтор ' . $t['retries'], 'warn');
				unset($t);
				continue;
			}
			$t['err'][] = dr_redact($run, $e->getMessage());
			$t['done'] = true;
			dr_run_log($run, $t['label'] . ': ОШИБКА ' . $e->getMessage(), 'error');
		}
		// время шага по всем запросам — для отчёта «этапы и самые долгие шаги»
		$t['sec'] = round((float)($t['sec'] ?? 0) + microtime(true) - $t0, 3);
		$wasKernel = $t['kind'] === 'kernel';
		if (!empty($t['done']))
		{
			$run['i']++;
		}
		unset($t);
		if ($wasKernel)
		{
			break;
		}
	}
	$done = $run['i'] >= $total;
	$cur = $run['tasks'][$run['i']] ?? null;
	$resp = [
		'ok' => true,
		'done' => $done,
		'i' => $run['i'],
		'total' => $total,
		'label' => $cur['label'] ?? '',
		'frac' => (float)($cur['frac'] ?? 0),
		'log' => array_slice($run['log'], (int)$run['sent']),
	];
	$run['sent'] = count($run['log']);
	$selfDelete = false;
	if ($done)
	{
		$resp['report_html'] = dr_report_html($run);
		$selfDelete = !empty($run['opts']['self_delete']);
		dr_finish_run($state, dr_report_html($run, false));
		if ($selfDelete)
		{
			$resp['report_html'] .= '<div class="alert alert-info">Файл ' . dr_h(DR_SELF) . ' удалён с сервера.</div>';
		}
	}
	$logoutHost = $done ? (string)$run['host'] : '';
	unset($run);
	dr_state_save($state);
	define('DR_STEP_DONE', true);
	if ($selfDelete)
	{
		@unlink(__FILE__);
	}
	if ($done)
	{
		$https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
		foreach (dr_logout_headers(array_keys($_COOKIE), dr_host(), $https, (string)ini_get('session.name'), dr_cookie_prefix($logoutHost)) as $line)
		{
			header($line, false);
		}
	}
	dr_json($resp);
}

function dr_finish_run(array &$state, string $html): void
{
	$run =& $state['run'];
	dr_run_log($run, 'Готово за ' . (time() - (int)$run['started']) . ' с');
	$state['last_report'] = ['at' => time(), 'prod' => $run['prod'], 'html' => $html];
	// соль и SQL с ней больше не нужны: без них замену не повторить и не обратить
	$run['tasks'] = array_map(static function ($t) {
		return ['label' => $t['label'], 'res' => $t['res'] ?? '', 'err' => $t['err'] ?? []];
	}, $run['tasks']);
	unset($run['salt'], $run['report']['admin']['password']);
	$run['token'] = '';
	$run['finished'] = time();
	$state['restore'] = [];
	unset($run);
}

function dr_task_run(DrDb $db, array &$run, array &$t, array $cfg, float $deadline): void
{
	switch ($t['kind'])
	{
		case 'ops':
			$res = [];
			foreach ($t['ops'] as $op)
			{
				$r = dr_op($db, $op, $run);
				if ($r !== '')
				{
					$res[] = $r;
				}
			}
			$t['res'] = implode('; ', $res);
			$t['done'] = true;
			break;
		case 'truncate':
			foreach ($t['tables'] as $tbl)
			{
				dr_truncate($db, $tbl);
			}
			$t['res'] = 'очищено таблиц: ' . count($t['tables']);
			$t['done'] = true;
			break;
		case 'update':
			dr_task_update($db, $t, $cfg, $deadline);
			break;
		case 'dict':
			dr_task_dict($db, $t, $deadline);
			break;
		case 'clean':
			dr_task_clean($db, $run, $t, $deadline);
			break;
		case 'leak_names':
			dr_task_leak_names($db, $run, $t, $cfg, $deadline);
			break;
		case 'drop_dict':
			dr_task_drop_dict($db, $run, $t);
			break;
		case 'marker':
			// словарь людей — ключ к двойникам: пока он в базе, отметку не ставим
			foreach (dr_leftover_tables($db) as $lt)
			{
				try
				{
					$db->exec('DROP TABLE IF EXISTS ' . dr_qi($lt));
				}
				catch (Throwable $e)
				{
				}
			}
			$left = dr_leftover_tables($db);
			if ($left)
			{
				$t['err'][] = 'в базе остались временные таблицы ' . implode(', ', $left) . ' — отметка не записана, удалите их и перезапустите обработку';
				$t['res'] = 'отметка НЕ записана';
				$run['no_marker'] = true;
				$t['done'] = true;
				break;
			}
			$errors = 0;
			foreach ($run['tasks'] as $prev)
			{
				$errors += count((array)($prev['err'] ?? []));
			}
			$warnings = count((array)($run['report']['leaks'] ?? [])) + count((array)($run['report']['leak_names'] ?? []));
			$db->setOption('devrestore', 'info', (string)json_encode([
				'errors' => $errors,
				'warnings' => $warnings,
				'prod_domain' => $run['prod'],
				'dev_host' => $run['host'],
				'anonymized_at' => date('Y-m-d H:i:s'),
				'text_mode' => $run['opts']['text_mode'],
				'companies' => $run['opts']['companies'] ?? 'all',
				'skipped_groups' => $run['opts']['skip_groups'],
				'version' => DR_VERSION,
			], JSON_UNESCAPED_UNICODE));
			$t['res'] = 'записана опция devrestore/info' . ($errors ? " — с ошибками ($errors), база считается обезличенной НЕ полностью" : '')
				. ($warnings ? " — с предупреждениями проверки ($warnings)" : '');
			if ($errors)
			{
				dr_run_log($run, "Обезличивание прошло с ошибками ($errors) — см. отчёт", 'error');
			}
			// дальше соль не нужна, а прогон может оборваться на шаге ядра и остаться в state навсегда
			unset($run['salt']);
			$t['done'] = true;
			break;
		case 'devsettings':
			if (!empty($run['no_marker']))
			{
				// иначе server_name станет dev-хостом, база без отметки будет выглядеть боевой и перезапуск откажет
				$t['err'][] = 'пропущено: отметка не записана';
				$t['done'] = true;
				break;
			}
			$t['res'] = dr_devsettings($db, $run);
			$t['done'] = true;
			break;
		case 'cleanup':
			$t['res'] = dr_cleanup($run);
			$t['done'] = true;
			break;
		case 'leak':
			dr_task_leak($db, $run, $t, $cfg, $deadline);
			break;
		default:
			throw new RuntimeException('Неизвестный шаг ' . $t['kind']);
	}
	if (!empty($t['done']))
	{
		dr_run_log($run, $t['label'] . ($t['res'] !== '' ? ' — ' . $t['res'] : ''));
	}
}

function dr_op(DrDb $db, array $op, array &$run): string
{
	switch ($op[0])
	{
		case 'sql':
			$req = (string)$op[1];
			if ($req !== '')
			{
				[$tbl, $col] = array_pad(explode('.', $req, 2), 2, null);
				if (!$db->hasTable($tbl) || ($col !== null && !$db->hasColumn($tbl, $col)))
				{
					return '';
				}
			}
			return $req . ': ' . $db->exec((string)$op[2]) . ' стр.';
		case 'option':
			$db->setOption((string)$op[1], (string)$op[2], (string)$op[3]);
			return $op[1] . '/' . $op[2] . ' = ' . $op[3];
		case 'truncate':
			if (!$db->hasTable((string)$op[1]))
			{
				return '';
			}
			dr_truncate($db, $db->tableName((string)$op[1]));
			return 'очищена ' . $op[1];
		case 'options_secrets':
			return dr_options_secrets($db, (array)($op[1] ?? []));
		case 'mail_stub':
			return dr_mail_stub($run);
		case 'smtp_off':
			return dr_smtp_off();
		case 'settings_dev':
			return dr_settings_dev();
	}
	throw new RuntimeException('Неизвестная операция ' . $op[0]);
}

function dr_truncate(DrDb $db, string $tbl): void
{
	try
	{
		$db->q('TRUNCATE TABLE ' . dr_qi($tbl));
	}
	catch (Throwable $e)
	{
		$db->q('SET FOREIGN_KEY_CHECKS = 0');
		try
		{
			$db->q('DELETE FROM ' . dr_qi($tbl));
		}
		finally
		{
			$db->q('SET FOREIGN_KEY_CHECKS = 1');
		}
	}
}

/**
 * Стирает опции-секреты в b_option и b_option_site. $keep — «MODULE_ID/NAME» из options_keep скриптов клиента
 * (точное совпадение): такие опции остаются с прод-значением, в отчёте — отдельной частью, только имена.
 */
function dr_options_secrets(DrDb $db, array $keep = []): string
{
	$rx = "'(^|_)(pass|password|passwd|secret|appsecret|token|api_?key|apikey|app_?key|private_?key|secret_?key|access_?key|auth_?key|signature_?key|client_?secret)(_|$)'";
	$cond = "LOWER(NAME) REGEXP $rx AND CHAR_LENGTH(VALUE) >= 8 AND VALUE NOT REGEXP '^[0-9]+$'";
	$notKept = $keep ? ' AND CONCAT(MODULE_ID, ' . "'/'" . ', NAME) NOT IN (' . implode(', ', array_map('dr_lit', $keep)) . ')' : '';
	$keepLc = array_map('strtolower', $keep);
	$names = [];
	$kept = [];
	$total = 0;
	foreach (['b_option', 'b_option_site'] as $tbl)
	{
		if (!$db->hasColumn($tbl, 'VALUE'))
		{
			continue;
		}
		foreach ($db->all("SELECT MODULE_ID, NAME FROM $tbl WHERE $cond") as $r)
		{
			$name = $r['MODULE_ID'] . '/' . $r['NAME'];
			// сравнение без учёта регистра — как у NOT IN в обычной (ci) collation базы
			if (in_array(strtolower($name), $keepLc, true))
			{
				$kept[] = $name;
			}
			else
			{
				$names[] = $name;
			}
		}
		$total += $db->exec("UPDATE $tbl SET VALUE = '' WHERE $cond$notKept");
	}
	$names = array_values(array_unique($names));
	$kept = array_values(array_unique($kept));
	$viewer = dr_disk_viewer_fix($db->option('disk', 'default_viewer_service'), $db->option('disk', 'default_viewer_service_boards'), $names);
	foreach ($viewer as $opt => $value)
	{
		$db->setOption('disk', $opt, $value);
	}
	// Опечатку в options_keep видно сразу: имя, которого нет среди найденных секретов, называется отдельно
	$missing = array_values(array_filter($keep, function ($k) use ($kept) { return !in_array(strtolower($k), array_map('strtolower', $kept), true); }));
	return 'очищено опций: ' . $total . ($names ? ' (' . implode(', ', array_slice($names, 0, 25)) . (count($names) > 25 ? ', …' : '') . ')' : '')
		. ($kept ? '; оставлено по скрипту клиента: ' . implode(', ', $kept) : '')
		. ($missing ? '; из options_keep не найдено среди секретов: ' . implode(', ', $missing) : '')
		. ($viewer ? '; просмотр документов Диска переключён на встроенный (облачный редактор привязан к домену прода)' : '');
}

function dr_mail_stub(array &$run): string
{
	$dbconn = DR_ROOT . '/bitrix/php_interface/dbconn.php';
	if (!is_file($dbconn))
	{
		dr_run_log($run, 'Нет dbconn.php — заглушка почты не установлена', 'warn');
		return 'нет dbconn.php';
	}
	$src = (string)file_get_contents($dbconn);
	if (strpos($src, 'DEVRESTORE-MAIL-STUB') !== false)
	{
		return 'заглушка почты уже стоит';
	}
	// Своя custom_mail у клиента (в init.php или во включаемом из него файле) после нашей дала бы «Cannot redeclare».
	foreach (['/bitrix/php_interface', '/local/php_interface'] as $dir)
	{
		$found = dr_find_php_with(DR_ROOT . $dir, '/function\s+custom_mail\s*\(/i');
		if ($found !== null)
		{
			$rel = substr($found, strlen(DR_ROOT));
			dr_run_log($run, 'В ' . $rel . ' уже объявлена custom_mail — заглушку не ставлю, проверьте, что она не шлёт письма', 'warn');
			return 'custom_mail уже есть в ' . $rel;
		}
	}
	$stub = "// DEVRESTORE-MAIL-STUB-BEGIN (добавлено " . DR_SELF . ": письма не отправляются, а пишутся в лог)\n"
		. "if (!function_exists('custom_mail'))\n{\n"
		. "\tfunction custom_mail(\$to, \$subject, \$message, \$additional_headers = '', \$additional_parameters = '', \$context = null)\n\t{\n"
		. "\t\t@file_put_contents(__DIR__ . '/devrestore-mail.log', date('[Y-m-d H:i:s] ') . 'TO: ' . \$to . ' | SUBJECT: ' . \$subject . PHP_EOL, FILE_APPEND);\n"
		. "\t\treturn true;\n\t}\n}\n"
		. "// DEVRESTORE-MAIL-STUB-END\n";
	$src = rtrim($src);
	if (substr($src, -2) === '?>')
	{
		$src = rtrim(substr($src, 0, -2));
	}
	dr_write_php($dbconn, $src . "\n\n" . $stub);
	return 'custom_mail-заглушка в dbconn.php';
}

/** Первый .php в папке (с подпапками), содержимое которого подходит под регулярку. */
function dr_find_php_with(string $dir, string $rx, int $depth = 4): ?string
{
	if (!is_dir($dir) || $depth < 0)
	{
		return null;
	}
	foreach ((array)scandir($dir) as $f)
	{
		if ($f === '.' || $f === '..')
		{
			continue;
		}
		$p = $dir . '/' . $f;
		if (is_dir($p))
		{
			if (($hit = dr_find_php_with($p, $rx, $depth - 1)) !== null)
			{
				return $hit;
			}
		}
		elseif (substr($f, -4) === '.php' && $f !== 'dbconn.php' && filesize($p) < 2000000 && preg_match($rx, (string)file_get_contents($p)))
		{
			return $p;
		}
	}
	return null;
}

function dr_smtp_off(): string
{
	$f = DR_ROOT . '/bitrix/.settings.php';
	if (!is_file($f))
	{
		return '';
	}
	$s = dr_include_fresh($f);
	if (!is_array($s) || empty($s['smtp']['value']['enabled']))
	{
		return '';
	}
	$s['smtp']['value']['enabled'] = false;
	dr_write_php($f, "<?php\nreturn " . var_export($s, true) . ";\n");
	return 'SMTP в .settings.php выключен';
}

/**
 * .settings.php из архива прода указывает на инфраструктуру прода: сетевой кеш (memcache/redis),
 * хранилище сессий, собственный push-сервер, дополнительные подключения к БД. Переводим на локальные значения.
 * (var_export теряет выражения вроде getenv() — для dev-копии это приемлемо.)
 */
function dr_settings_dev(): string
{
	$f = DR_ROOT . '/bitrix/.settings.php';
	if (!is_file($f))
	{
		return '';
	}
	$s = dr_include_fresh($f);
	if (!is_array($s))
	{
		return '';
	}
	$done = [];
	foreach (['cache', 'session', 'pull'] as $key)
	{
		if (isset($s[$key]))
		{
			unset($s[$key]);
			$done[] = $key;
		}
	}
	foreach (array_keys((array)($s['connections']['value'] ?? [])) as $name)
	{
		if ($name !== 'default')
		{
			unset($s['connections']['value'][$name]);
			$done[] = 'connections.' . $name;
		}
	}
	if (!$done)
	{
		return '';
	}
	dr_write_php($f, "<?php\nreturn " . var_export($s, true) . ";\n");
	return '.settings.php: убрано ' . implode(', ', $done) . ' (по умолчанию — файлы и локальная БД)';
}

function dr_task_update(DrDb $db, array &$t, array $cfg, float $deadline): void
{
	$where = $t['where'] !== '' ? '(' . $t['where'] . ')' : '';
	if (!isset($t['cur']))
	{
		$t['rows'] = 0;
		if ($t['pk'])
		{
			$r = $db->one("SELECT MIN({$t['pk']}) mn, MAX({$t['pk']}) mx FROM {$t['from']}" . ($where ? " WHERE $where" : ''));
			if ($r === null || $r['mn'] === null)
			{
				$t['res'] = 'пусто';
				$t['done'] = true;
				return;
			}
			$t['cur'] = ['min' => (int)$r['mn'], 'max' => (int)$r['mx'], 'pos' => (int)$r['mn'], 'batch' => max(100, (int)$cfg['batch_size'])];
		}
		else
		{
			$t['cur'] = ['single' => true];
		}
	}
	$c =& $t['cur'];
	if (!empty($c['single']))
	{
		$t['rows'] += dr_update_exec($db, $t, $where);
		$t['frac'] = 1;
	}
	else
	{
		while ($c['pos'] <= $c['max'] && microtime(true) < $deadline)
		{
			$to = $c['pos'] + $c['batch'] - 1;
			$range = "{$t['pk']} BETWEEN {$c['pos']} AND $to";
			$started = microtime(true);
			$t['rows'] += dr_update_exec($db, $t, $range . ($where ? " AND $where" : ''));
			$spent = microtime(true) - $started;
			$c['pos'] = $to + 1;
			if ($spent > 4 && $c['batch'] > 500)
			{
				$c['batch'] = (int)($c['batch'] / 2);
			}
			elseif ($spent < 0.5 && $c['batch'] < 100000)
			{
				$c['batch'] *= 2;
			}
		}
		$t['frac'] = min(1, ($c['pos'] - $c['min']) / max(1, $c['max'] - $c['min'] + 1));
		if ($c['pos'] <= $c['max'])
		{
			return;
		}
	}
	unset($c);
	$t['res'] = implode(', ', $t['cols']) . ' — строк: ' . $t['rows'];
	if (isset($t['uniq_skip']))
	{
		$t['err'][] = 'конфликт уникального ключа: строк с исходным значением осталось ' . (!empty($t['uniq_more']) ? 'не меньше ' : '') . $t['uniq_skip'];
	}
	$t['done'] = true;
}

function dr_update_exec(DrDb $db, array &$t, string $where): int
{
	$sql = "UPDATE {$t['from']} SET {$t['set']}" . ($where !== '' ? " WHERE $where" : '');
	try
	{
		return $db->exec($sql);
	}
	catch (RuntimeException $e)
	{
		if ($e->getCode() !== 1062)
		{
			throw $e;
		}
		// дубль уникального ключа: обновляем, что можно; каждая пропущенная строка — предупреждение 1062.
		// Пропуск — ошибка: в пропущенных строках остались исходные данные.
		$n = $db->exec(preg_replace('/^UPDATE /', 'UPDATE IGNORE ', $sql));
		$skipped = 0;
		$more = false;
		try
		{
			$total = preg_match('/Warnings:\s*(\d+)/', (string)$db->link->info, $wm) ? (int)$wm[1] : 0;
			$listed = $db->all('SHOW WARNINGS');
			foreach ($listed as $w)
			{
				$skipped += (int)$w['Code'] === 1062 ? 1 : 0;
			}
			// SHOW WARNINGS показывает не больше max_error_count — остальное известно только числом
			$more = $total > count($listed);
		}
		catch (Throwable $e)
		{
			$more = true;
		}
		if ($skipped > 0 || $more)
		{
			$t['uniq_skip'] = ($t['uniq_skip'] ?? 0) + $skipped;
			$t['uniq_more'] = !empty($t['uniq_more']) || $more;
		}
		return $n;
	}
}

function dr_devsettings(DrDb $db, array $run): string
{
	$host = $run['host'];
	$site = $run['site'];
	if ($db->hasTable('b_lang'))
	{
		$set = 'SERVER_NAME = ' . dr_lit($host) . ($db->hasColumn('b_lang', 'DOMAINS') ? ', DOMAINS = ' . dr_lit($host) : '');
		$db->exec("UPDATE b_lang SET $set WHERE LID = " . dr_lit($site));
	}
	if ($db->hasTable('b_lang_domain'))
	{
		$db->exec('DELETE FROM b_lang_domain WHERE LID = ' . dr_lit($site));
		$db->exec('INSERT INTO b_lang_domain (LID, DOMAIN) VALUES (' . dr_lit($site) . ', ' . dr_lit($host) . ')');
	}
	$cookie = dr_cookie_prefix($host);
	$db->setOption('main', 'server_name', $host);
	$db->setOption('main', 'site_name', 'DEV ' . $host);
	$db->setOption('main', 'cookie_name', $cookie);
	$db->setOption('main', 'update_devsrv', 'Y');
	// Сессии, оставшиеся с прежней копии (в них права, токен защиты и кеш старой базы), ядро уничтожит само на первом хите:
	// подпись сессии BX_SESSION_SIGN считается от server_uniq_id и с новым значением не совпадёт.
	// Файлы сессий не трогаем — каталог сессий PHP общий для всех сайтов сервера.
	$db->setOption('main', 'server_uniq_id', md5(random_bytes(16)));
	return "сайт $site → $host, куки $cookie, «установка для разработки» = Y, новый server_uniq_id (сессии прежней копии недействительны)";
}

function dr_cleanup(array $run): string
{
	$done = [];
	foreach (['cache', 'managed_cache', 'stack_cache', 'resize_cache', 'tmp'] as $d)
	{
		if (is_dir(DR_ROOT . '/bitrix/' . $d))
		{
			dr_rrmdir(DR_ROOT . '/bitrix/' . $d);
		}
	}
	$done[] = 'кеш /bitrix/{cache,managed_cache,stack_cache,tmp}';
	// restore.php удаляем всегда: оставленный в корне, он восстанавливает что угодно без авторизации
	foreach (['restore.php', 'bitrixsetup.php'] as $f)
	{
		if (is_file(DR_ROOT . '/' . $f) && !dr_same_path(DR_ROOT . '/' . $f, dr_norm_path(__FILE__)))
		{
			$done[] = (@unlink(DR_ROOT . '/' . $f) ? '' : 'НЕ удалён ') . $f;
		}
	}
	if (empty($run['opts']['cleanup']))
	{
		return implode('; ', $done);
	}
	foreach (dr_scan_archives() as $g)
	{
		foreach ($g['files'] as $f)
		{
			$done[] = (@unlink(DR_ROOT . '/' . $f) ? '' : 'НЕ удалён ') . $f;
		}
	}
	foreach (dr_scan_dumps() as $d)
	{
		$done[] = (@unlink(DR_ROOT . '/bitrix/backup/' . $d['name']) ? '' : 'НЕ удалён ') . 'bitrix/backup/' . $d['name'];
	}
	return implode('; ', $done);
}

/** Наши подставные адреса — не утечка. */
const DR_RX_OWN_FAKES = 'u[0-9a-f]{10}@example[.]test|u[0-9]+@example[.]test|user@example[.]test';

/**
 * Проверка колонки в PHP: последние строки по ключу (или первые без ключа), та же регулярка MySQL, переведённая в PCRE.
 * Возвращает null или ['n' => строк с находкой, 'of' => просмотрено, 'id', 'v' => замаскированный пример].
 */
function dr_leak_scan_php(DrDb $db, string $tbl, string $col, ?string $pk, string $rxMysql): ?array
{
	$pcre = dr_leak_pcre($rxMysql);
	$own = '/' . DR_RX_OWN_FAKES . '/';
	$qc = dr_qi($col);
	$rows = $db->all('SELECT ' . ($pk ? dr_qi($pk) : 'NULL') . " AS id, LEFT($qc, 20000) AS v FROM " . dr_qi($tbl)
		. " WHERE $qc IS NOT NULL AND $qc <> ''" . ($pk ? ' ORDER BY ' . dr_qi($pk) . ' DESC' : '') . ' LIMIT ' . DR_LEAK_PHP_ROWS);
	$out = null;
	foreach ($rows as $r)
	{
		$v = (string)preg_replace($own, '', (string)$r['v']);
		if (@preg_match($pcre, $v, $m) === 1)
		{
			$out = $out ?? ['n' => 0, 'of' => count($rows), 'id' => $r['id'], 'v' => dr_leak_sample($v)];
			$out['n']++;
		}
	}
	return $out;
}

/** Регулярка проверки утечек из синтаксиса MySQL (ICU) в PCRE: классы POSIX — в юникодные, как их понимает MySQL 8. */
function dr_leak_pcre(string $rxMysql): string
{
	return '/' . str_replace(['[:alnum:]', '[:alpha:]', '/'], ['\p{L}\p{N}', '\p{L}', '\/'], $rxMysql) . '/u';
}

/** Сколько строк колонки смотреть в PHP, если REGEXP в MySQL не справился. */
const DR_LEAK_PHP_ROWS = 2000;

function dr_task_leak(DrDb $db, array &$run, array &$t, array $cfg, float $deadline): void
{
	if (!isset($t['cur']))
	{
		$exclude = array_merge(['b_search_%', 'b_cache_tag', 'b_perf_%', 'b_option', 'zz_devrestore_%'], (array)$run['truncated'], (array)$cfg['leak_scan_exclude']);
		$skipT = array_flip(array_map('strtolower', dr_resolve_tables($db, $exclude)));
		$items = [];
		$tables = $db->tables();
		$quick = ($run['opts']['leak_scan'] ?? '') !== 'full';
		// быстрая проверка: колонки, которые правила обработали, пропускаются — кроме сериализованных, что не распаковались
		$done = [];
		foreach ($quick ? (array)$run['tasks'] : [] as $task)
		{
			if (in_array($task['kind'], ['update', 'clean'], true))
			{
				foreach ($task['kind'] === 'clean' ? array_keys($task['cols']) : (array)$task['cols'] as $col)
				{
					$done[strtolower($task['table'] . '.' . $col)] = true;
				}
			}
		}
		foreach ((array)($run['report']['struct_broken'] ?? []) as $b)
		{
			unset($done[strtolower((string)strtok((string)$b, ':'))]);
		}
		$skippedByRules = 0;
		foreach ($db->columns() as $tbl => $cols)
		{
			if (isset($skipT[$tbl]) || !isset($tables[$tbl]))
			{
				continue;
			}
			$textCols = array_filter($cols, static function ($m) {
				return dr_is_str_type($m['type']) && ($m['len'] >= 6 || strpos($m['type'], 'text') !== false);
			});
			if (!$textCols)
			{
				continue;
			}
			if ($done)
			{
				$before = count($textCols);
				$textCols = array_filter($textCols, static function ($m) use ($done, $tbl) {
					return !isset($done[$tbl . '.' . strtolower($m['name'])]);
				});
				$skippedByRules += $before - count($textCols);
				if (!$textCols)
				{
					continue;
				}
			}
			$pk = $db->pk($tbl);
			$sample = !$quick && $tables[$tbl]['rows'] > (int)$cfg['leak_scan_max_rows'];
			if ($sample && !$pk)
			{
				$run['report']['leak_skipped'][] = $tables[$tbl]['name'] . ' (~' . $tables[$tbl]['rows'] . ' строк, без числового ключа)';
				continue;
			}
			if ($sample)
			{
				$run['report']['leak_sampled'][] = $tables[$tbl]['name'] . ' (~' . $tables[$tbl]['rows'] . ' строк)';
			}
			foreach ($textCols as $m)
			{
				$items[] = [$tables[$tbl]['name'], $m['name'], $pk, $sample];
			}
		}
		if (!$items)
		{
			// при сбое — отказ: «проверено 0 колонок» не означает «утечек нет»
			throw new RuntimeException('не найдено ни одной текстовой колонки для проверки — проверка не выполнена');
		}
		$t['cur'] = ['items' => $items, 'i' => 0, 'quick' => $quick ? max(100, (int)$cfg['leak_quick_rows']) : 0, 'by_rules' => $skippedByRules];
		$t['found'] = 0;
	}
	$rxAll = DR_RX_EMAIL . '|' . DR_RX_PHONE_PLUS . '|' . DR_RX_PHONE_8 . '|' . DR_RX_PHONE_BARE . '|' . DR_RX_PHONE_SEP;
	$rxReplace = $db->supportsRegexpReplace();
	$c =& $t['cur'];
	$n = count($c['items']);
	while ($c['i'] < $n && microtime(true) < $deadline)
	{
		[$tbl, $col, $pk, $sample] = $c['items'][$c['i']++] + [3 => false];
		$qc = dr_qi($col);
		// быстрая проверка — последние N строк по ключу (без ключа — первые N)
		$from = dr_qi($tbl);
		if (!empty($c['quick']))
		{
			$from = '(SELECT ' . ($pk ? dr_qi($pk) . ', ' : '') . $qc . ' FROM ' . dr_qi($tbl) . ($pk ? ' ORDER BY ' . dr_qi($pk) . ' DESC' : '') . ' LIMIT ' . (int)$c['quick'] . ') q';
		}
		$rxCol = preg_match(DR_RX_ID_COLUMN, $col) ? DR_RX_EMAIL : $rxAll;
		$rx = dr_lit($rxCol);
		// свои подставные адреса вырезаем из ячейки, а не прячем всю ячейку: рядом может остаться настоящий телефон
		$cond = $rxReplace
			? "REGEXP_REPLACE($qc, " . dr_lit(DR_RX_OWN_FAKES) . ", '') REGEXP $rx"
			: "$qc REGEXP $rx AND $qc NOT LIKE '%example.test%'";
		if ($sample)
		{
			// большая таблица — последние 200 000 записей по ключу: полный REGEXP по ней не уложится в шаг
			$max = (int)$db->val('SELECT MAX(' . dr_qi($pk) . ') FROM ' . dr_qi($tbl));
			$cond .= ' AND ' . dr_qi($pk) . ' > ' . ($max - 200000);
		}
		try
		{
			$hit = $db->one('SELECT ' . ($pk ? dr_qi($pk) : 'NULL') . " AS id, LEFT($qc, 2000) AS v FROM $from WHERE $cond LIMIT 1");
			if ($hit)
			{
				$cnt = (int)$db->val("SELECT COUNT(*) FROM (SELECT 1 FROM $from WHERE $cond LIMIT 1000) x");
				// в отчёт и состояние — только замаскированное совпадение, без окружающего текста
				$run['report']['leaks'][] = ['t' => $tbl, 'c' => $col, 'n' => $cnt, 'id' => $hit['id'], 'v' => dr_leak_sample((string)$hit['v'])];
				$t['found']++;
			}
		}
		catch (Throwable $e)
		{
			// REGEXP не уложился в лимит (длинный текст) или упал — проверяем выборку той же регуляркой в PHP
			try
			{
				$hit = dr_leak_scan_php($db, $tbl, $col, $pk, $rxCol);
				if ($hit)
				{
					$run['report']['leaks'][] = ['t' => $tbl, 'c' => $col, 'n' => $hit['n'], 'id' => $hit['id'], 'v' => $hit['v'], 'php' => $hit['of']];
					$t['found']++;
				}
				$run['report']['leak_php'][] = "$tbl.$col";
			}
			catch (Throwable $e2)
			{
				$run['report']['leak_skipped'][] = "$tbl.$col (ошибка: " . mb_substr($e->getMessage(), 0, 80) . ')';
			}
		}
	}
	$t['frac'] = $n ? $c['i'] / $n : 1;
	if ($c['i'] >= $n)
	{
		$t['res'] = "проверено колонок: $n, с находками: {$t['found']}"
			. (!empty($c['quick']) ? ' (быстрая: последние ' . $c['quick'] . ' строк; колонок, обработанных правилами, пропущено: ' . (int)$c['by_rules'] . ')' : ' (полная)');
		$t['done'] = true;
		if ($t['found'])
		{
			dr_run_log($run, 'Проверка нашла e-mail/телефоны в ' . $t['found'] . ' колонках — см. отчёт', 'warn');
		}
	}
	unset($c);
}

/** Выполняется в запросе с подключённым ядром Битрикс. */
function dr_task_kernel(array &$run, array &$t, array $cfg): void
{
	$ctx = new DevRestoreContext($run['host'], $run['prod'], $run['site'], $cfg, static function (string $lvl, string $msg) use (&$run, &$t) {
		dr_run_log($run, $msg, $lvl);
		if ($lvl === 'error')
		{
			$t['err'][] = $msg;
		}
	});
	$res = [];

	// 1. Модули разработки: вернуть в b_module и восстановить сохранённые опции
	foreach ((array)$cfg['dev_modules'] as $module => $names)
	{
		$module = (string)$module;
		if (!is_dir(DR_ROOT . '/bitrix/modules/' . $module) && !is_dir(DR_ROOT . '/local/modules/' . $module))
		{
			continue;
		}
		$res[] = dr_reinstall_dev_module($ctx, $module, (array)($run['dev_modules'][$module] ?? []));
	}

	// 2. Dev-админ
	$adm = $cfg['dev_admin'];
	$id = (int)($adm['id'] ?? 0);
	if ($id > 0 && class_exists('CUser'))
	{
		if (!CUser::GetByID($id)->Fetch())
		{
			$ctx->log("Пользователь ID=$id не найден — dev-админ не настроен", 'warn');
		}
		else
		{
			$pass = (string)($adm['password'] ?? '');
			$generated = $pass === '';
			if ($generated)
			{
				$pass = 'Dev-' . bin2hex(random_bytes(4)) . '-' . strtoupper(bin2hex(random_bytes(2))) . '!';
			}
			$login = (string)($adm['login'] ?: 'admin');
			$user = new CUser();
			$db = DrDb::connect();
			$hashBefore = (string)$db->val('SELECT PASSWORD FROM b_user WHERE ID = ' . $id);
			if ($user->Update($id, ['LOGIN' => $login, 'PASSWORD' => $pass, 'CONFIRM_PASSWORD' => $pass, 'ACTIVE' => 'Y']))
			{
				$groups = array_map('intval', (array)CUser::GetUserGroup($id));
				if (!in_array(1, $groups, true))
				{
					$groups[] = 1;
					CUser::SetUserGroup($id, $groups);
				}
				// Update вернул true — это ещё не значит, что поля записаны: обработчик OnBeforeUserUpdate клиента
				// (премодерация профилей и т. п.) может вырезать LOGIN или ACTIVE. Верим только базе.
				$fact = dr_admin_fact($db, $id, $login, $ctx);
				$passOk = (string)$db->val('SELECT PASSWORD FROM b_user WHERE ID = ' . $id) !== $hashBefore;
				if (!$passOk && class_exists('\Bitrix\Main\Security\Password') && method_exists('\Bitrix\Main\Security\Password', 'hash'))
				{
					$ctx->log("Пароль dev-админа ID=$id не сменился через CUser::Update — записываю хеш напрямую", 'warn');
					$db->exec('UPDATE b_user SET PASSWORD = ' . dr_lit((string)\Bitrix\Main\Security\Password::hash($pass)) . ' WHERE ID = ' . $id);
					$passOk = (string)$db->val('SELECT PASSWORD FROM b_user WHERE ID = ' . $id) !== $hashBefore;
				}
				if (!$passOk)
				{
					$ctx->log("Пароль dev-админа ID=$id не сменился (его вырезал обработчик OnBeforeUserUpdate?) — войти с паролем из отчёта не выйдет", 'error');
				}
				$run['report']['admin'] = ['id' => $id, 'login' => $fact, 'password' => !$passOk ? '(не сменился — см. ошибки)' : ($generated ? $pass : '(из config.php)')];
				$res[] = "dev-админ ID=$id ($fact)";
			}
			else
			{
				$ctx->log('Не удалось обновить dev-админа: ' . strip_tags((string)$user->LAST_ERROR), 'error');
			}
		}
	}

	// 3. Скрипты клиента
	foreach ((array)$run['opts']['scripts'] as $file)
	{
		$m = dr_script_manifest((string)$file);
		if (!$m || !isset($m['run']) || !is_callable($m['run']))
		{
			continue;
		}
		$ctx->log('Скрипт: ' . ($m['title'] ?? $file));
		try
		{
			($m['run'])($ctx);
			$res[] = 'скрипт ' . $file;
		}
		catch (Throwable $e)
		{
			$ctx->log('Скрипт ' . $file . ' упал: ' . $e->getMessage(), 'error');
		}
	}

	// 4. Сбросить кеш. Входа dev-админом здесь нет: в конце обработки cookie прежней копии стираются вместе с сессией,
	// поэтому вход — заново, кнопкой на странице отчёта
	try
	{
		if (function_exists('BXClearCache'))
		{
			BXClearCache(true);
		}
		if (isset($GLOBALS['CACHE_MANAGER']) && is_object($GLOBALS['CACHE_MANAGER']))
		{
			$GLOBALS['CACHE_MANAGER']->CleanAll();
		}
	}
	catch (Throwable $e)
	{
		$ctx->log('Сброс кеша: ' . $e->getMessage(), 'warn');
	}

	$t['res'] = implode('; ', $res);
	$t['done'] = true;
	dr_run_log($run, $t['label'] . ($t['res'] !== '' ? ' — ' . $t['res'] : ''));
}

/**
 * Логин dev-админа из базы. Расходится с нужным (LOGIN или ACTIVE вырезал обработчик) — пишем напрямую и перечитываем.
 * Возвращает логин, который действительно лежит в b_user: его и показывает отчёт.
 */
function dr_admin_fact(DrDb $db, int $id, string $login, DevRestoreContext $ctx): string
{
	$row = $db->one('SELECT LOGIN, ACTIVE FROM b_user WHERE ID = ' . $id) ?? ['LOGIN' => '', 'ACTIVE' => ''];
	if ((string)$row['LOGIN'] === $login && $row['ACTIVE'] === 'Y')
	{
		return $login;
	}
	$ctx->log("CUser::Update вернул успех, но в базе LOGIN={$row['LOGIN']}, ACTIVE={$row['ACTIVE']} — поля вырезал обработчик"
		. ' OnBeforeUserUpdate; записываю напрямую', 'warn');
	try
	{
		$db->exec('UPDATE b_user SET LOGIN = ' . dr_lit($login) . ", ACTIVE = 'Y' WHERE ID = " . $id);
	}
	catch (RuntimeException $e)
	{
		$ctx->log('Прямая запись логина dev-админа не удалась: ' . $e->getMessage(), 'error');
	}
	$row = $db->one('SELECT LOGIN, ACTIVE FROM b_user WHERE ID = ' . $id) ?? ['LOGIN' => '', 'ACTIVE' => ''];
	if ((string)$row['LOGIN'] !== $login || $row['ACTIVE'] !== 'Y')
	{
		$ctx->log("Dev-админ ID=$id: в базе LOGIN={$row['LOGIN']}, ACTIVE={$row['ACTIVE']} — входите под этим логином или поправьте вручную", 'error');
	}
	return (string)$row['LOGIN'];
}

function dr_reinstall_dev_module(DevRestoreContext $ctx, string $module, array $saved): string
{
	$mm = '\Bitrix\Main\ModuleManager';
	if (!$mm::isModuleInstalled($module))
	{
		$dir = is_dir(DR_ROOT . '/local/modules/' . $module) ? '/local/modules/' : '/bitrix/modules/';
		$installer = DR_ROOT . $dir . $module . '/install/index.php';
		$class = str_replace('.', '_', $module);
		if (is_file($installer))
		{
			require_once $installer;
		}
		if (class_exists($class))
		{
			$obj = new $class();
			// DoInstall часто рисует админскую страницу — вызываем шаги установки напрямую
			foreach (['InstallDB', 'InstallEvents', 'InstallFiles'] as $method)
			{
				if (method_exists($obj, $method))
				{
					ob_start();
					try
					{
						$obj->$method();
					}
					finally
					{
						ob_end_clean();
					}
				}
			}
		}
		if (!$mm::isModuleInstalled($module))
		{
			$mm::registerModule($module);
		}
	}
	foreach ($saved as $name => $value)
	{
		\Bitrix\Main\Config\Option::set($module, (string)$name, (string)$value);
	}
	if ($module === 'delight.mcp')
	{
		\Bitrix\Main\Config\Option::set($module, 'enabled', 'Y');
	}
	$msg = 'модуль ' . $module . ($saved ? ' (опции восстановлены: ' . implode(', ', array_keys($saved)) . ')' : ' (сохранённых опций нет — при необходимости выпустите новый токен)');
	$ctx->log($msg, $saved ? 'info' : 'warn');
	return $msg;
}

/** Контекст для скриптов клиента (функция run в scripts/*.php). */
final class DevRestoreContext
{
	/** Текущий dev-хост, например client.local */
	public $devHost;
	/** Боевой домен из восстановленной базы — ключ профиля */
	public $prodDomain;
	/** ID сайта, которому прописан dev-домен */
	public $siteId;
	public $config;
	private $sink;

	public function __construct(string $devHost, string $prodDomain, string $siteId, array $config, callable $sink)
	{
		$this->devHost = $devHost;
		$this->prodDomain = $prodDomain;
		$this->siteId = $siteId;
		$this->config = $config;
		$this->sink = $sink;
	}

	/** $level: info | ok | warn | error. Пишется в журнал сразу — переживает die() в коде после вызова. */
	public function log(string $message, string $level = 'info'): void
	{
		($this->sink)($level === 'ok' ? 'info' : $level, $message);
	}
}

// ============================================================================
// Отчёт
// ============================================================================

/** $withSecrets = false — для сохранённой копии отчёта: пароль dev-админа показывается только один раз. */
function dr_report_html(array $run, bool $withSecrets = true): string
{
	$h = '<h2>Отчёт</h2><table class="kv">'
		. '<tr><th>Боевой домен (профиль)</th><td>' . dr_h($run['prod']) . '</td></tr>'
		. '<tr><th>Dev-хост</th><td>' . dr_h($run['host']) . '</td></tr>'
		. '<tr><th>Свободный текст</th><td>' . dr_h(dr_text_mode_name($run['opts']['text_mode'])) . '</td></tr>'
		. '<tr><th>Компании</th><td>' . (($run['opts']['companies'] ?? 'all') === 'individuals' ? 'заменены ИП и физлица, юрлица оставлены' : 'заменены все') . '</td></tr>'
		. '<tr><th>Время обработки</th><td>' . dr_h(dr_duration((float)(time() - (int)$run['started']))) . '</td></tr>';
	if (!empty($run['import']))
	{
		$h .= '<tr><th>Импорт</th><td>' . dr_h(dr_import_summary((array)$run['import'])) . '</td></tr>';
	}
	[$stages, $top] = dr_stage_times((array)$run['tasks']);
	foreach ($stages as $name => $sec)
	{
		$h .= '<tr><th>' . dr_h($name) . '</th><td>' . dr_h(dr_duration($sec)) . '</td></tr>';
	}
	$h .= '<tr><th>Самые долгие шаги</th><td>' . dr_h(implode('; ', array_map(static function ($x) {
		return $x[0] . ' — ' . dr_duration($x[1]);
	}, array_filter($top, static function ($x) {
		return $x[1] >= 0.5;
	})))) . '</td></tr></table>';
	if (!empty($run['report']['admin']))
	{
		$a = $run['report']['admin'];
		$h .= '<div class="alert alert-success"><b>Вход на dev-копию:</b> логин <code>' . dr_h($a['login']) . '</code>, пароль <code>' . dr_h($withSecrets ? $a['password'] : '(показан один раз в конце обработки)') . '</code></div>';
	}
	// сессии и cookie прежней копии сброшены (новый server_uniq_id и заголовки итогового ответа) — вход заново
	$h .= '<div class="alert alert-info">Вход и cookie прежней копии сброшены — войдите заново. <a class="btn" href="/auth/">Войти на портал</a>'
		. ' · <a href="/bitrix/admin/">Админка</a> · <a href="/">Сайт</a><br>Если портал открыт и по http, и по https — войдите в том протоколе, в котором работаете.</div>';
	$errors = [];
	$rows = '';
	foreach ($run['tasks'] as $t)
	{
		$err = (array)($t['err'] ?? []);
		foreach ($err as $e)
		{
			$errors[] = $t['label'] . ': ' . $e;
		}
		$rows .= '<tr' . ($err ? ' class="row-err"' : '') . '><td>' . dr_h($t['label']) . '</td><td>' . dr_h($t['res'] ?? '') . ($err ? '<br><b>' . dr_h(implode('; ', $err)) . '</b>' : '') . '</td></tr>';
	}
	$names = (array)($run['report']['leak_names'] ?? []);
	if ($run['opts']['text_mode'] === 'dict' && $errors)
	{
		$h .= '<div class="alert alert-danger"><b>Обезличивание прошло с ошибками</b> — часть текста могла остаться исходной. Ошибки перечислены ниже; исправьте и перезапустите обработку.</div>';
	}
	elseif ($run['opts']['text_mode'] === 'dict')
	{
		$h .= '<div class="alert alert-' . ($names || !empty($run['report']['leaks']) ? 'warning' : 'info') . '"><b>База псевдонимизирована'
			. ($names || !empty($run['report']['leaks']) ? ' с предупреждениями' : '') . '.</b> Люди, контакты и документы в тексте заменены двойниками, сам текст оставлен.'
			. ' Редкие формы имён могут остаться — смотрите проверку ниже; надёжнее всего — режим «заменять целиком».</div>';
	}
	foreach ((array)$run['report']['warn'] as $w)
	{
		$h .= '<div class="alert alert-warning">' . dr_h($w) . '</div>';
	}
	if (!empty($run['report']['strict_tables']))
	{
		$h .= '<div class="alert alert-info">Большие таблицы: текст заменён целиком, а не чищен по словарю (порог clean_max_rows): '
			. dr_h(implode('; ', (array)$run['report']['strict_tables'])) . '</div>';
	}
	if (!empty($run['report']['struct_broken']))
	{
		$h .= '<div class="alert alert-warning">Сериализованные и JSON-значения, которые не распаковались без потерь, оставлены как есть —'
			. ' в них могут остаться персональные данные, проверьте вручную: ' . dr_h(implode('; ', (array)$run['report']['struct_broken'])) . '</div>';
	}
	if (!empty($run['report']['struct_skipped']))
	{
		$h .= '<div class="alert alert-warning">Сериализованные и JSON-колонки не чищены — у таблицы нет числового первичного ключа: '
			. dr_h(implode(', ', (array)$run['report']['struct_skipped'])) . '</div>';
	}
	if (!empty($run['report']['missing_tables']))
	{
		$h .= '<div class="alert alert-info">Таблиц из правил нет в этой базе (' . count($run['report']['missing_tables']) . ') — модуль не установлен'
			. ' или таблица называется иначе; если модуль есть, а таблица под другим именем, это пробел правил: '
			. dr_h(implode(', ', (array)$run['report']['missing_tables'])) . '</div>';
	}
	if (!empty($run['report']['uf_new']))
	{
		$h .= '<div class="alert alert-info">Новые строковые поля (' . count($run['report']['uf_new']) . ') обработаны по умолчанию: '
			. dr_h(implode(', ', array_slice((array)$run['report']['uf_new'], 0, 30))) . (count($run['report']['uf_new']) > 30 ? ', …' : '')
			. '. Проверьте их на странице <a href="' . dr_h(dr_self_url(['phase' => 'fields'])) . '">«Поля клиента»</a>.</div>';
	}
	if ($errors)
	{
		$h .= '<div class="alert alert-danger"><b>Ошибки (' . count($errors) . '):</b><ul><li>' . implode('</li><li>', array_map('dr_h', $errors)) . '</li></ul></div>';
	}
	if (!empty($run['opts']['leak_scan']))
	{
		$leaks = (array)$run['report']['leaks'];
		if ($leaks)
		{
			$h .= '<h3>Проверка на утечки: найдено в ' . count($leaks) . ' колонках</h3>'
				. '<p class="muted">Это могут быть и служебные адреса (шаблоны писем, настройки). Личное — добавьте правилом в скрипт клиента (<code>anonymize</code>) и перезапустите обработку.</p>'
				. '<table class="grid"><tr><th>Таблица.колонка</th><th>Строк</th><th>Пример (ID)</th></tr>';
			foreach ($leaks as $l)
			{
				$h .= '<tr><td>' . dr_h($l['t'] . '.' . $l['c']) . '</td><td>' . (isset($l['php']) ? (int)$l['n'] . ' из ' . (int)$l['php'] . ' последних' : ($l['n'] >= 1000 ? '≥ 1000' : (int)$l['n'])) . '</td><td><code>' . dr_h($l['v']) . '</code>' . ($l['id'] !== null ? ' <span class="muted">#' . dr_h($l['id']) . '</span>' : '') . '</td></tr>';
			}
			$h .= '</table>';
		}
		else
		{
			$h .= '<div class="alert alert-success">Проверка на утечки: e-mail и телефонов в текстовых полях не найдено.</div>';
		}
		if ($names)
		{
			$h .= '<h3>Проверка на утечки: имена людей в ' . count($names) . ' колонках</h3>'
				. '<p class="muted">Последние 300 строк каждой таблицы. Двойники из наших списков не считаются. Если это люди — добавьте правило'
				. ' в <code>anonymize</code> скрипта клиента (готовые строки ниже) или решите поле на странице'
				. ' <a href="' . dr_h(dr_self_url(['phase' => 'fields'])) . '">«Поля клиента»</a>, и перезапустите обработку.</p>'
				. '<table class="grid"><tr><th>Таблица.колонка</th><th>Строк с именами</th><th>Пример (ID)</th></tr>';
			$byTable = [];
			foreach ($names as $l)
			{
				$h .= '<tr><td>' . dr_h($l['t'] . '.' . $l['c']) . '</td><td>' . (int)$l['n'] . ' из ' . (int)$l['of'] . '</td><td><code>' . dr_h($l['v']) . '</code>'
					. ($l['id'] !== null ? ' <span class="muted">#' . dr_h($l['id']) . '</span>' : '')
					. (!empty($l['ser']) ? ' <span class="muted">(сериализовано: правило clean сломает формат — нужен скрипт)</span>' : '') . '</td></tr>';
				if (empty($l['ser']))
				{
					$byTable[$l['t']][] = $l['c'];
				}
			}
			$lines = '';
			foreach ($byTable as $t => $cols)
			{
				$lines .= "['table' => " . var_export($t, true) . ", 'set' => [" . implode(', ', array_map(static function ($c) {
					return var_export($c, true) . " => 'clean'";
				}, $cols)) . "]],\n";
			}
			$h .= '</table>' . ($lines !== '' ? '<p class="muted">Правила для <code>anonymize</code>:</p><pre class="code">' . dr_h($lines) . '</pre>' : '');
		}
		if (!empty($run['report']['leak_sampled']))
		{
			$h .= '<p class="muted">Проверено выборочно (последние 200 000 записей): ' . dr_h(implode(', ', $run['report']['leak_sampled'])) . '</p>';
		}
		if (!empty($run['report']['leak_php']))
		{
			$h .= '<p class="muted">REGEXP в базе не справился — проверено в PHP по последним ' . DR_LEAK_PHP_ROWS . ' строкам ('
				. count($run['report']['leak_php']) . '): ' . dr_h(implode(', ', $run['report']['leak_php'])) . '</p>';
		}
		if (!empty($run['report']['leak_skipped']))
		{
			// не проверенное — не «чисто»: называем каждую колонку на виду, а не под спойлером
			$h .= '<div class="alert alert-warning"><b>Не проверено (' . count($run['report']['leak_skipped']) . '):</b> '
				. dr_h(implode(', ', $run['report']['leak_skipped'])) . '</div>';
		}
	}
	$h .= '<p class="muted">Файлы в /upload (документы, сканы, вложения) обезличиванием не затрагиваются — закройте их от ИИ.</p>';
	$h .= '<details><summary>Шаги (' . count($run['tasks']) . ')</summary><table class="grid">' . $rows . '</table></details>';
	return $h;
}

// ============================================================================
// Страницы
// ============================================================================

function dr_page(string $title, string $body, string $script = ''): void
{
	while (ob_get_level() > 0)
	{
		ob_end_clean();
	}
	header('Content-Type: text/html; charset=utf-8');
	header('Cache-Control: no-store');
	header('X-Robots-Tag: noindex');
	echo '<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
		. '<title>' . dr_h($title) . ' — devrestore</title><style>' . dr_css() . '</style></head><body><div class="container">'
		. '<div class="top"><b>devrestore</b> ' . DR_VERSION . ' · <code>' . dr_h(dr_host()) . '</code> · <a href="' . dr_h(dr_self_url()) . '">на главную</a></div>'
		. $body . '</div>' . ($script !== '' ? '<script>' . $script . '</script>' : '') . '</body></html>';
	exit;
}

function dr_css(): string
{
	return 'body{font-family:"Segoe UI",Tahoma,Verdana,sans-serif;background:#f4f6f9;color:#333;margin:0;padding:24px}'
		. '.container{max-width:920px;margin:0 auto}.top{font-size:13px;color:#6b7280;margin-bottom:12px}'
		. '.card{background:#fff;padding:22px 26px;border-radius:8px;box-shadow:0 4px 15px rgba(0,0,0,.05);margin-bottom:18px}'
		. 'h1{font-size:22px;margin:0 0 14px}h2{font-size:19px;margin:0 0 12px}h3{font-size:16px;margin:18px 0 8px}'
		. '.alert{padding:11px 14px;margin:10px 0;border-radius:6px;border-left:5px solid;font-size:14px}'
		. '.alert-warning{background:#fffbeb;color:#92400e;border-color:#f59e0b}.alert-danger{background:#fef2f2;color:#b91c1c;border-color:#ef4444}'
		. '.alert-success{background:#f0fdf4;color:#15803d;border-color:#22c55e}.alert-info{background:#eff6ff;color:#1d4ed8;border-color:#3b82f6}'
		. '.muted{color:#6b7280;font-size:13px}code{background:#f3f4f6;padding:1px 5px;border-radius:4px}'
		. 'label{display:block;margin:5px 0}fieldset{border:1px solid #e5e7eb;border-radius:6px;margin:12px 0;padding:8px 14px}legend{font-weight:600;color:#4b5563}'
		. 'input[type=text],input[type=password]{padding:8px 10px;font-size:15px;border:1px solid #d1d5db;border-radius:6px;width:320px;max-width:100%}'
		. '.btn{display:inline-block;padding:10px 20px;font-size:15px;font-weight:600;border:0;border-radius:6px;cursor:pointer;background:#2563eb;color:#fff}'
		. '.btn:hover{background:#1d4ed8}.btn-gray{background:#e5e7eb;color:#374151}'
		. 'table{border-collapse:collapse;width:100%;font-size:13px}.grid td,.grid th{border-bottom:1px solid #e5e7eb;padding:5px 6px;text-align:left;vertical-align:top}'
		. '.kv th{text-align:left;width:220px;padding:3px 6px;color:#4b5563}.kv td{padding:3px 6px}.row-err td{background:#fef2f2}'
		. '.checks li{margin:3px 0}.ok{color:#15803d}.bad{color:#b91c1c}.warn{color:#b45309}'
		. '.bar{height:14px;background:#e5e7eb;border-radius:7px;overflow:hidden;margin:10px 0}.bar>div{height:100%;width:0;background:#2563eb;transition:width .3s}'
		. '#dr-log{background:#111827;color:#d1d5db;font:12px/1.5 Consolas,monospace;padding:10px;border-radius:6px;height:260px;overflow:auto;white-space:pre-wrap}'
		. 'pre.code{background:#f3f4f6;padding:8px 10px;border-radius:6px;font-size:12px;white-space:pre-wrap}.fields td{font-size:12px}.fields code{font-size:11px}'
		. '.log-warn{color:#fbbf24}.log-error{color:#f87171}.tasks{font-size:13px;columns:2;padding-left:18px}.tasks li.done{color:#9ca3af}.tasks li.cur{font-weight:600;color:#1d4ed8}';
}

function dr_view_dashboard(array $cfg, array $state): void
{
	$host = dr_host();
	[$db, $info, $dbErr] = dr_try_db();
	$profiles = dr_profiles();
	$checks = [
		['ok', 'Хост <code>' . dr_h($host) . '</code> разрешён'],
		[dr_is_loopback() ? 'ok' : 'warn', dr_is_loopback() ? 'Запуск с этой машины (127.0.0.1)' : 'Удалённый доступ по ключу — файл удалится после обработки'],
		['ok', 'Папка профилей: <code>' . dr_h(dr_home()) . '</code>'],
		[$db ? 'ok' : 'warn', $db ? 'БД: ' . dr_h($db->server) : 'БД недоступна: ' . dr_h($dbErr)],
	];
	if ($info)
	{
		if ($info['marker'] && !empty($info['marker']['errors']))
		{
			$checks[] = ['bad', 'База обработана ' . dr_h($info['marker']['anonymized_at'] ?? '') . ' С ОШИБКАМИ (' . (int)$info['marker']['errors'] . ') — обезличивание неполное, перезапустите обработку'];
		}
		elseif ($info['marker'])
		{
			$pseudo = ($info['marker']['text_mode'] ?? '') === 'dict';
			$warns = (int)($info['marker']['warnings'] ?? 0);
			$checks[] = [$warns ? 'warn' : 'ok', 'База ' . ($pseudo ? 'псевдонимизирована' : 'обезличена') . ' ' . dr_h($info['marker']['anonymized_at'] ?? '')
				. ' (боевой домен ' . dr_h($info['prod']) . ')' . ($warns ? ' — с предупреждениями проверки (' . $warns . '), см. отчёт' : '')];
		}
		elseif ($info['looks_prod'])
		{
			$checks[] = ['bad', 'server_name в базе совпадает с этим хостом, отметки обезличивания нет — похоже на боевой сайт или ручную настройку'];
		}
		else
		{
			$checks[] = ['warn', 'База НЕ обезличена. Боевой домен: <code>' . dr_h($info['prod'] ?: '—') . '</code>' . (isset($profiles[$info['prod']]) ? ' (профиль есть)' : ' (профиля ещё нет)')];
		}
	}
	if ($db && $info && (empty($state['run']['token']) || !empty($state['run']['finished'])))
	{
		try
		{
			$left = dr_leftover_tables($db);
		}
		catch (Throwable $e)
		{
			$left = [];
		}
		if ($left)
		{
			$checks[] = ['bad', 'В базе остались временные таблицы <code>' . dr_h(implode(', ', $left)) . '</code> (словарь людей) — удалите кнопкой «Сбросить» ниже'];
		}
	}
	// restore.php без вставок (положен руками или перезаписал сам себя): открыть его — значит оживить прод на копии
	if (is_file(DR_ROOT . '/restore.php') && !dr_restore_patched((string)@file_get_contents(DR_ROOT . '/restore.php')))
	{
		$checks[] = ['bad', '<b>restore.php в корне без вставок devrestore:</b> если открыть его напрямую, после импорта оживут агенты и почта прода, а возврата сюда не будет. '
			. 'Подготовьте заново кнопкой «Подготовить и открыть restore.php» (можно выбрать «Использовать лежащий в корне»).'];
	}
	$body = '<div class="card"><h1>Восстановление копии с обезличиванием</h1><ul class="checks">';
	foreach ($checks as [$cls, $txt])
	{
		$body .= '<li class="' . $cls . '">' . ($cls === 'ok' ? '✔' : ($cls === 'bad' ? '✖' : '!')) . ' <span style="color:#333">' . $txt . '</span></li>';
	}
	$body .= '</ul></div>';

	if (!empty($left))
	{
		$body .= '<form method="post" class="alert alert-danger"><input type="hidden" name="action" value="cancel"><input type="hidden" name="csrf" value="' . dr_h($state['csrf']) . '">'
			. 'Словарь людей остался от прерванной обработки. <button class="btn btn-gray">Сбросить</button></form>';
	}
	if (!empty($state['run']['token']) && empty($state['run']['finished']))
	{
		$body .= '<form method="post" class="alert alert-warning">Есть незавершённая обработка (' . (int)$state['run']['i'] . ' из ' . count($state['run']['tasks']) . ' шагов) — база обезличена не полностью. '
			. '<a href="' . dr_h(dr_self_url(['phase' => 'run'])) . '">Продолжить</a> или '
			. '<input type="hidden" name="action" value="cancel"><input type="hidden" name="csrf" value="' . dr_h($state['csrf']) . '">'
			. '<button class="btn btn-gray">Сбросить</button> (данные для замены будут стёрты)</form>';
	}

	$body .= dr_view_restore_form($state, $info, dr_import_tools($cfg, $db));
	if ($db && $info)
	{
		$body .= dr_view_process_form($cfg, $state, $info, null, 'Обработать текущую базу', 'Если база уже восстановлена (например, restore.php запускали отдельно) — обезличить её и настроить для разработки.');
		$body .= '<div class="card"><h2>Поля клиента</h2><p class="muted">Строковые пользовательские поля: что с ними делать при обезличивании (оставить, чистить как текст, заменить целиком).'
			. ' Решения хранятся в профиле клиента.</p><a class="btn btn-gray" href="' . dr_h(dr_self_url(['phase' => 'fields'])) . '">Открыть «Поля клиента»</a></div>';
	}
	if (!empty($state['last_report']['html']))
	{
		$body .= '<div class="card"><details><summary>Последний отчёт: ' . dr_h(date('d.m.Y H:i', (int)$state['last_report']['at'])) . ', ' . dr_h($state['last_report']['prod']) . '</summary>'
			. $state['last_report']['html'] . '</details></div>';
	}
	dr_page('Главная', $body);
}

function dr_view_restore_form(array $state, ?array $info, array $tools = []): string
{
	$archives = dr_scan_archives();
	$dumps = dr_scan_dumps();
	$restoreExists = is_file(DR_ROOT . '/restore.php');
	$h = '<form method="post" class="card"><h2>1. Восстановить свежую копию</h2>'
		. '<input type="hidden" name="action" value="prepare"><input type="hidden" name="csrf" value="' . dr_h($state['csrf']) . '">';
	if ($archives)
	{
		$h .= '<fieldset><legend>Архив в корне сайта</legend>';
		foreach ($archives as $i => $g)
		{
			$h .= '<label><input type="radio" name="keep_archive" value="' . dr_h($g['base']) . '"' . ($i === 0 ? ' checked' : '') . '> <b>' . dr_h($g['base']) . '</b>'
				. (count($g['files']) > 1 ? ' (' . count($g['files']) . ' частей)' : '') . ' — ' . dr_human_size($g['size']) . ', ' . date('d.m.Y H:i', $g['mtime']) . ($i === 0 ? ' <span class="ok">самый свежий</span>' : '') . '</label>';
		}
		if (count($archives) > 1)
		{
			$h .= '<label><input type="checkbox" name="delete_other_archives" value="1" checked> Удалить остальные архивы</label>';
		}
		$h .= '</fieldset>';
	}
	else
	{
		$h .= '<div class="alert alert-info">Архивов в корне нет. Можно скачать копию по ссылке в самом restore.php.</div>';
	}
	if ($dumps)
	{
		$names = array_map(static function ($d) {
			return $d['name'];
		}, $dumps);
		$h .= '<fieldset><legend>Старые дампы в /bitrix/backup</legend><p class="muted">' . dr_h(implode(', ', $names)) . '</p>'
			. '<label><input type="checkbox" name="delete_dumps" value="1"' . ($archives ? ' checked' : '') . '> Удалить — иначе restore.php предложит выбрать среди них и легко взять не тот</label></fieldset>';
	}
	$h .= '<fieldset><legend>restore.php</legend>'
		. '<label><input type="radio" name="restore_source" value="download" checked> Скачать свежий с 1c-bitrix.ru</label>'
		. ($restoreExists ? '<label><input type="radio" name="restore_source" value="existing"> Использовать лежащий в корне</label>' : '')
		. '</fieldset>';
	$fast = !empty($tools['mysql']);
	$h .= '<fieldset><legend>Импорт дампа</legend>'
		. ($fast ? '<input type="hidden" name="fast_import" value="0"><label><input type="checkbox" name="fast_import" value="1" checked> Грузить дамп клиентом mysql в фоне — в разы быстрее; restore.php только распакует архив</label>'
			. '<p class="muted">Клиент: <code>' . dr_h((string)$tools['mysql']) . '</code>, PHP: <code>' . dr_h((string)$tools['php']) . '</code></p>'
			. '<input type="hidden" name="skip_truncated" value="0"><label><input type="checkbox" name="skip_truncated" value="1"' . (dr_config()['import_skip_truncated'] ? ' checked' : '') . '>'
			. ' Не загружать данные таблиц, которые обработка всё равно очищает (логи, очереди, поисковый индекс, сессии, корзина) — структура останется</label>'
			: '<div class="alert alert-warning">Дамп загрузит restore.php — это медленнее: ' . dr_h((string)($tools['why'] ?? '')) . '</div>')
		. '</fieldset>';
	if ($info && $info['looks_prod'])
	{
		$h .= '<div class="alert alert-danger">В текущей базе server_name = этот хост, отметки devrestore нет.'
			. '<label><input type="checkbox" name="not_prod" value="1"> Это точно не боевой сайт</label></div>';
	}
	$h .= dr_domain_confirm()
		. '<p class="muted">Дальше откроется restore.php. На последнем экране нажмите «Удалить файлы восстановления» — вы вернётесь сюда, и обработка начнётся сама (для известного клиента) или после выбора настроек.</p>'
		. '<button class="btn">Подготовить и открыть restore.php</button></form>';
	return $h;
}

function dr_domain_confirm(): string
{
	return '<p><label>Для подтверждения введите текущий домен <code>' . dr_h(dr_host()) . '</code>:</label>'
		. '<input type="text" name="confirm_domain" required autocomplete="off" placeholder="' . dr_h(dr_host()) . '"></p>';
}

function dr_view_process_form(array $cfg, array $state, array $info, ?string $token, string $title, string $intro): string
{
	$profiles = dr_profiles();
	$profile = $profiles[$info['prod']] ?? null;
	$opts = dr_default_opts($cfg, $profile);
	$h = '<form method="post" class="card"><h2>' . dr_h($title) . '</h2><p class="muted">' . dr_h($intro) . '</p>'
		. '<input type="hidden" name="action" value="start"><input type="hidden" name="csrf" value="' . dr_h($state['csrf']) . '">'
		. ($token ? '<input type="hidden" name="token" value="' . dr_h($token) . '">' : '');
	$h .= '<table class="kv"><tr><th>Боевой домен (профиль)</th><td>';
	if ($info['prod'] !== '')
	{
		$h .= '<b>' . dr_h($info['prod']) . '</b>' . ($profile ? ' — профиль от ' . dr_h($profile['updated'] ?? '') : ' — новый профиль');
	}
	else
	{
		$h .= '<input type="text" name="profile_key" required placeholder="например bitrix24.example.ru">';
	}
	$h .= '</td></tr><tr><th>Сайт</th><td>' . dr_h($info['site']) . ' → ' . dr_h(dr_host()) . '</td></tr></table>';

	$scripts = dr_scripts();
	$h .= '<fieldset><legend>Скрипты клиента (папка ' . dr_h(dr_home()) . '/scripts)</legend>';
	if (!$scripts)
	{
		$h .= '<p class="muted">Скриптов нет.</p>';
	}
	foreach ($scripts as $file => $m)
	{
		$h .= '<label><input type="checkbox" name="scripts[]" value="' . dr_h($file) . '"' . (in_array($file, $opts['scripts'], true) ? ' checked' : '') . (!empty($m['broken']) ? ' disabled' : '') . '> '
			. '<b>' . dr_h($m['title'] ?? $file) . '</b> <span class="muted">' . dr_h($file) . (!empty($m['description']) ? ' — ' . dr_h($m['description']) : '') . '</span></label>';
	}
	$h .= '</fieldset>';

	$tm = in_array($opts['text_mode'], ['dict', 'strict', 'scrub'], true) ? $opts['text_mode'] : 'strict';
	$h .= '<fieldset><legend>Свободный текст (комментарии, переписка, письма, названия сделок)</legend>'
		. '<label><input type="radio" name="text_mode" value="dict"' . ($tm === 'dict' ? ' checked' : '') . '> Оставлять текст, заменяя людей (по словарю ФИО из базы, во всех падежах), e-mail, телефоны, документы и адреса — для разбора задач</label>'
		. '<label><input type="radio" name="text_mode" value="strict"' . ($tm === 'strict' ? ' checked' : '') . '> Заменять целиком на «[скрыто, N симв.]» — надёжнее всего</label>'
		. '<label><input type="radio" name="text_mode" value="scrub"' . ($tm === 'scrub' ? ' checked' : '') . '> Оставлять текст, вычищая только e-mail и телефоны — имена останутся</label></fieldset>'
		. '<fieldset><legend>Компании</legend>'
		. '<label><input type="radio" name="companies" value="individuals"' . ($opts['companies'] !== 'all' ? ' checked' : '') . '> Заменять ИП и физлиц, юрлица оставлять</label>'
		. '<label><input type="radio" name="companies" value="all"' . ($opts['companies'] === 'all' ? ' checked' : '') . '> Заменять все названия</label>'
		. '<p class="muted">Юрлицо вместе с должностью контакта («директор ООО Ромашка») может указывать на человека.</p></fieldset>';

	$h .= '<fieldset><legend>Что делать</legend><details><summary>Группы правил (' . count(dr_rule_groups($cfg)) . ', снятые запоминаются в профиле)</summary>';
	foreach (dr_rule_groups($cfg) as $g => $neutral)
	{
		$h .= '<label><input type="checkbox" name="groups[]" value="' . dr_h($g) . '"' . (in_array($g, $opts['skip_groups'], true) ? '' : ' checked') . '> ' . dr_h($g) . '</label>';
	}
	$h .= '</details>'
		. '<label><input type="checkbox" name="leak_scan" value="1"' . ($opts['leak_scan'] ? ' checked' : '') . '> Проверка на утечки после обработки</label>'
		. '<label><input type="checkbox" name="cleanup" value="1" checked> Удалить архив, дампы и restore.php</label>'
		. '<label><input type="checkbox" name="self_delete" value="1"' . ($opts['self_delete'] ? ' checked' : '') . '> Удалить ' . dr_h(DR_SELF) . ' в конце' . (dr_is_loopback() ? ' (на локалке обычно не нужно)' : '') . '</label></fieldset>';
	if ($info['looks_prod'])
	{
		$h .= '<div class="alert alert-danger">server_name в базе совпадает с этим хостом — обработка будет запрещена. Восстановите копию прода или проверьте хост.</div>';
	}
	$h .= ($token ? '' : dr_domain_confirm()) . '<button class="btn">Обезличить и настроить</button></form>';
	return $h;
}

function dr_text_mode_name(string $mode): string
{
	switch ($mode)
	{
		case 'dict':
			return 'псевдонимизирован: люди, e-mail, телефоны, документы и адреса заменены двойниками, текст оставлен';
		case 'scrub':
			return 'вычищены e-mail и телефоны, текст оставлен';
	}
	return 'заменён целиком';
}

/** Названия сущностей UF для страницы полей; смарт-процессы — из b_crm_dynamic_type. */
function dr_uf_entity_names(DrDb $db): array
{
	$names = ['CRM_DEAL' => 'Сделки', 'CRM_LEAD' => 'Лиды', 'CRM_CONTACT' => 'Контакты', 'CRM_COMPANY' => 'Компании', 'CRM_QUOTE' => 'Предложения',
		'CRM_INVOICE' => 'Счета (старые)', 'CRM_ACTIVITY' => 'Дела', 'USER' => 'Пользователи'];
	if ($db->hasColumn('b_crm_dynamic_type', 'TITLE'))
	{
		foreach ($db->all('SELECT ID, TITLE FROM b_crm_dynamic_type') as $r)
		{
			$names['CRM_' . (int)$r['ID']] = 'Смарт-процесс «' . $r['TITLE'] . '»';
		}
	}
	return $names;
}

/** Форма значения без самого значения: буквы → а/А, цифры → 9, длина до 40. */
function dr_value_shape(string $v): string
{
	$v = (string)preg_replace('/\s+/u', ' ', trim($v));
	$s = mb_substr($v, 0, 40);
	$s = (string)preg_replace(['/\p{M}/u', '/\p{Lu}/u', '/(?!А)\p{L}/u', '/\p{N}/u'], ['', 'А', 'а', '9'], $s);
	return $s . (mb_strlen($v) > 40 ? '… (' . mb_strlen($v) . ')' : '');
}

/**
 * Строковые UF с заполненностью, формами значений и сигналами (e-mail, телефон, документ, похоже на имя).
 * Значения на экран не выводятся — только формы.
 */
function dr_fields_overview(DrDb $db, array $profile, string $mode): array
{
	$byTable = [];
	$rows = [];
	foreach (dr_uf_list($db) as $uf)
	{
		if ($uf['type'] !== 'string' && $uf['type'] !== 'string_formatted')
		{
			continue;
		}
		$key = dr_uf_key($uf);
		$rows[$key] = $uf + ['auto' => dr_uf_method($uf['field'], $uf['type'], $uf['label'], $uf['entity'], $mode),
			'decision' => (string)($profile['uf'][$key] ?? ''), 'filled' => 0, 'shapes' => [], 'signals' => []];
		foreach ($uf['tables'] as $t)
		{
			$byTable[$t][] = $key;
		}
	}
	// чистильщик без словаря: сигналы — по шаблонам контактов и документов и частым именам
	$cl = new DrCleaner(new DrFakes(bin2hex(random_bytes(8))), []);
	foreach ($byTable as $tbl => $keys)
	{
		$real = $db->tableName($tbl);
		$pk = $db->pk($real);
		$cols = [];
		foreach ($keys as $key)
		{
			$cols[$key] = $db->columns()[$tbl][strtoupper($rows[$key]['field'])]['name'] ?? $rows[$key]['field'];
		}
		$cnt = [];
		foreach ($cols as $key => $col)
		{
			$cnt[] = 'SUM(' . dr_qi($col) . " IS NOT NULL AND " . dr_qi($col) . " <> '') AS " . dr_qi('c' . count($cnt));
		}
		try
		{
			$sum = $db->one('SELECT ' . implode(', ', $cnt) . ' FROM ' . dr_qi($real));
			$sample = $db->all('SELECT ' . implode(', ', array_map('dr_qi', $cols)) . ' FROM ' . dr_qi($real) . ($pk ? ' ORDER BY ' . dr_qi($pk) . ' DESC' : '') . ' LIMIT 300');
		}
		catch (Throwable $e)
		{
			continue;
		}
		$i = 0;
		foreach ($cols as $key => $col)
		{
			$rows[$key]['filled'] += (int)($sum['c' . $i++] ?? 0);
			$shapes = [];
			foreach ($sample as $r)
			{
				$v = (string)($r[$col] ?? '');
				if ($v === '' || preg_match('/^(a|s|i):\d+:/', $v))
				{
					continue;
				}
				$sh = dr_value_shape($v);
				$shapes[$sh] = ($shapes[$sh] ?? 0) + 1;
				$cl->hits = [];
				$cl->contacts($v);
				foreach ($cl->hits as $k => $n)
				{
					$rows[$key]['signals'][$k] = ($rows[$key]['signals'][$k] ?? 0) + 1;
				}
				if ($cl->names($v))
				{
					$rows[$key]['signals']['name'] = ($rows[$key]['signals']['name'] ?? 0) + 1;
				}
			}
			arsort($shapes);
			$rows[$key]['shapes'] = array_slice($shapes, 0, 3, true);
		}
	}
	return $rows;
}

function dr_view_fields(array $cfg, array $state): void
{
	[$db, $info, $dbErr] = dr_try_db();
	if (!$db || !$info)
	{
		dr_page('Поля клиента', '<div class="alert alert-danger">База недоступна: ' . dr_h($dbErr) . '</div>');
	}
	if ($info['prod'] === '')
	{
		dr_page('Поля клиента', '<div class="alert alert-warning">Не определён боевой домен — профиль клиента не найти. Сначала обработайте базу с главной страницы.</div>');
	}
	$profile = (array)(dr_profiles()[$info['prod']] ?? []);
	$mode = (string)($profile['text_mode'] ?? $cfg['text_mode']);
	$rows = dr_fields_overview($db, $profile, $mode);
	$entities = dr_uf_entity_names($db);
	$methodName = ['clean' => 'чистить как текст', 'text' => 'текст (по режиму)', 'email' => 'e-mail', 'phone' => 'телефон', 'digits' => 'номер документа',
		'inn' => 'ИНН: 12 цифр — заменить', 'address' => 'адрес', 'empty' => 'очистить', 'full_name' => 'ФИО', 'company' => 'компания', 'null' => 'очистить'];
	$sigName = ['email' => 'e-mail', 'phone' => 'телефон', 'doc' => 'документ', 'addr' => 'адрес', 'url' => 'соцсеть', 'name' => 'похоже на имя'];
	$h = '<form method="post" class="card"><h1>Поля клиента: ' . dr_h($info['prod']) . '</h1>'
		. '<p class="muted">Строковые пользовательские поля. Значения не показываются — только их форма (буквы → а/А, цифры → 9) по последним 300 записям.'
		. ' «Авто» — решение по коду и подписи поля. «Оставить» — значение остаётся, вычищаются только e-mail, телефоны и номера документов.'
		. ' Решения сохраняются в профиле клиента и применяются при следующей обработке.' . ($info['marker'] ? ' <b>База уже обработана</b> — формы показаны по обезличенным данным.' : '') . '</p>'
		. '<input type="hidden" name="action" value="save_fields"><input type="hidden" name="csrf" value="' . dr_h($state['csrf']) . '">'
		. '<table class="grid fields"><tr><th>Поле</th><th>Заполнено</th><th>Формы значений</th><th>Сигналы</th><th>Решение</th></tr>';
	$entity = null;
	foreach ($rows as $key => $r)
	{
		if ($r['entity'] !== $entity)
		{
			$entity = $r['entity'];
			$h .= '<tr><th colspan="5">' . dr_h($entities[$entity] ?? $entity) . ' <span class="muted">' . dr_h($entity) . '</span></th></tr>';
		}
		$shapes = '';
		foreach ($r['shapes'] as $sh => $n)
		{
			$shapes .= '<code>' . dr_h($sh) . '</code> ×' . (int)$n . '<br>';
		}
		$sig = [];
		foreach ($r['signals'] as $k => $n)
		{
			$sig[] = '<span class="' . ($k === 'name' ? 'warn' : 'bad') . '">' . dr_h($sigName[$k] ?? $k) . ' ×' . (int)$n . '</span>';
		}
		$auto = $r['auto'] === null ? 'не трогать' : ($methodName[$r['auto']] ?? $r['auto']);
		$name = 'uf[' . dr_h($key) . ']';
		$radio = static function (string $v, string $label) use ($r, $name): string {
			return '<label style="display:inline;margin-right:8px"><input type="radio" name="' . $name . '" value="' . $v . '"' . ($r['decision'] === ($v === 'auto' ? '' : $v) ? ' checked' : '') . '> ' . $label . '</label>';
		};
		$h .= '<tr><td><b>' . dr_h($r['label'] !== '' ? $r['label'] : $r['field']) . '</b><br><span class="muted">' . dr_h($r['field']) . ($r['multi'] ? ', множественное' : '') . '</span></td>'
			. '<td>' . (int)$r['filled'] . '</td><td>' . ($shapes ?: '<span class="muted">—</span>') . '</td><td>' . implode('<br>', $sig) . '</td>'
			. '<td>' . $radio('auto', 'авто: ' . dr_h($auto)) . '<br>' . $radio('keep', 'оставить') . $radio('clean', 'чистить') . $radio('replace', 'заменить целиком') . '</td></tr>';
	}
	if (!$rows)
	{
		$h .= '<tr><td colspan="5" class="muted">Строковых пользовательских полей нет.</td></tr>';
	}
	$h .= '</table><p><button class="btn">Сохранить решения</button> <a href="' . dr_h(dr_self_url()) . '">на главную</a></p></form>';
	dr_page('Поля клиента', $h);
}

/** Сохранить решения по полям в профиль (дополняя его). */
function dr_action_save_fields(): void
{
	[$db, $info] = dr_try_db();
	if (!$db || !$info || $info['prod'] === '')
	{
		dr_fatal('Поля клиента', 'База недоступна или не определён боевой домен.', 400);
	}
	if (!empty($info['looks_prod']) || in_array($info['prod'], dr_host_variants(), true))
	{
		dr_fatal('Поля клиента', 'Боевой домен базы совпадает с текущим хостом — профиль не сохраняется.', 400);
	}
	$known = [];
	foreach (dr_uf_list($db) as $uf)
	{
		$known[dr_uf_key($uf)] = true;
	}
	$profiles = dr_profiles();
	$p = (array)($profiles[$info['prod']] ?? []);
	$uf = (array)($p['uf'] ?? []);
	foreach ((array)($_POST['uf'] ?? []) as $key => $v)
	{
		$key = strtoupper((string)$key);
		if (!isset($known[$key]))
		{
			continue;
		}
		if (in_array($v, ['keep', 'clean', 'replace'], true))
		{
			$uf[$key] = $v;
		}
		else
		{
			unset($uf[$key]);
		}
	}
	ksort($uf);
	$p['uf'] = $uf;
	$p['updated'] = date('Y-m-d H:i:s');
	$profiles[$info['prod']] = $p;
	dr_profiles_save($profiles);
	dr_logf('Поля клиента ' . $info['prod'] . ': сохранено решений ' . count($uf));
	dr_redirect(dr_self_url(['phase' => 'fields', 'saved' => 1]));
}

function dr_view_runner(array $state): void
{
	$run = $state['run'];
	$items = '';
	foreach ($run['tasks'] as $i => $t)
	{
		$items .= '<li data-task="' . $i . '"' . ($i < $run['i'] ? ' class="done"' : '') . '>' . dr_h($t['label']) . '</li>';
	}
	$body = '<div class="card"><h1>Обработка: ' . dr_h($run['prod']) . ' → ' . dr_h($run['host']) . '</h1>'
		. '<div class="bar"><div id="dr-bar"></div></div><div id="dr-label" class="muted">Запуск…</div>'
		. '<div id="dr-error"></div><div id="dr-log"></div>'
		. '<details><summary>Шаги (' . count($run['tasks']) . ')</summary><ol class="tasks">' . $items . '</ol></details></div>'
		. '<div class="card" id="dr-report" style="display:none"></div>';
	$js = 'window.DR=' . json_encode(['url' => dr_self_url(), 'token' => $run['token']]) . ';' . <<<'JS'
(function () {
	var bar = document.getElementById('dr-bar'), label = document.getElementById('dr-label'),
		logEl = document.getElementById('dr-log'), errEl = document.getElementById('dr-error'),
		reportEl = document.getElementById('dr-report');
	function addLog(lines) {
		lines.forEach(function (l) {
			var d = document.createElement('div');
			d.className = 'log-' + l[0];
			d.textContent = l[1];
			logEl.appendChild(d);
		});
		logEl.scrollTop = logEl.scrollHeight;
	}
	function fail(msg, details) {
		errEl.innerHTML = '';
		var box = document.createElement('div');
		box.className = 'alert alert-danger';
		box.textContent = msg;
		if (details) {
			var pre = document.createElement('pre');
			pre.style.whiteSpace = 'pre-wrap';
			pre.style.maxHeight = '200px';
			pre.style.overflow = 'auto';
			pre.textContent = String(details).slice(0, 3000);
			box.appendChild(pre);
		}
		var btn = document.createElement('button');
		btn.className = 'btn';
		btn.textContent = 'Повторить шаг';
		btn.onclick = function () { errEl.innerHTML = ''; step(); };
		box.appendChild(document.createElement('br'));
		box.appendChild(btn);
		errEl.appendChild(box);
	}
	function mark(i) {
		document.querySelectorAll('[data-task]').forEach(function (li) {
			var n = +li.getAttribute('data-task');
			li.className = n < i ? 'done' : (n === i ? 'cur' : '');
		});
	}
	function step() {
		var fd = new FormData();
		fd.append('action', 'step');
		fd.append('token', DR.token);
		fetch(DR.url, {method: 'POST', body: fd, credentials: 'same-origin'}).then(function (r) {
			return r.text();
		}).then(function (txt) {
			var j;
			try { j = JSON.parse(txt); } catch (e) {
				var p = txt.lastIndexOf('{"ok"');
				try { j = JSON.parse(txt.slice(p)); } catch (e2) { return fail('Неожиданный ответ сервера', txt); }
			}
			if (j.log) addLog(j.log);
			if (!j.ok) return fail(j.error || 'Ошибка');
			if (j.busy) return setTimeout(step, 1500);
			var pct = Math.min(100, (j.i + (j.done ? 0 : (j.frac || 0))) / Math.max(1, j.total) * 100);
			bar.style.width = pct.toFixed(1) + '%';
			mark(j.i);
			if (j.done) {
				label.textContent = 'Готово';
				reportEl.style.display = '';
				reportEl.innerHTML = j.report_html;
				return;
			}
			label.textContent = (j.i + 1) + ' из ' + j.total + ': ' + j.label + (j.frac ? ' — ' + Math.round(j.frac * 100) + '%' : '');
			setTimeout(step, 30);
		}).catch(function (e) { fail('Сетевая ошибка: ' + e.message); });
	}
	step();
})();
JS;
	dr_page('Обработка', $body, $js);
}

// ============================================================================
// Точка входа
// ============================================================================

if (PHP_SAPI === 'cli')
{
	dr_import_cli((array)($_SERVER['argv'] ?? []));
}
$drCfg = dr_config();
dr_guard($drCfg);
$drState = dr_state_load();
$drAction = (string)($_POST['action'] ?? '');
$drPhase = (string)($_GET['phase'] ?? '');

try
{
	if ($drAction === 'step')
	{
		$drRun = $drState['run'] ?? null;
		if (!$drRun || empty($drRun['token']) || !hash_equals((string)$drRun['token'], (string)($_POST['token'] ?? '')))
		{
			dr_json(['ok' => false, 'error' => 'Обработка не найдена или уже завершена. Откройте ' . DR_SELF . ' заново.']);
		}
		$drLock = fopen(dr_state_file() . '.lock', 'c');
		if (!$drLock || !flock($drLock, LOCK_EX | LOCK_NB))
		{
			dr_json(['ok' => true, 'busy' => true, 'log' => []]);
		}
		$drState = dr_state_load();
		$drKernel = false;
		$drTask = $drState['run']['tasks'][$drState['run']['i']] ?? null;
		if ($drTask && $drTask['kind'] === 'kernel')
		{
			// Ядро подключается на глобальном уровне: include.php Битрикс рассчитывает на глобальные переменные.
			foreach (['NO_KEEP_STATISTIC', 'NO_AGENT_STATISTIC', 'NO_AGENT_CHECK', 'NOT_CHECK_PERMISSIONS', 'DisableEventsCheck', 'STOP_STATISTICS', 'PUBLIC_AJAX_MODE'] as $drConst)
			{
				if (!defined($drConst))
				{
					define($drConst, true);
				}
			}
			// Скрипт клиента или установщик модуля может сделать die() (IncludeAdminFile) — тогда шаг ядра
			// всё равно закрываем с ошибкой и сохраняем состояние (в нём уже пароль dev-админа), чтобы обработка шла дальше.
			register_shutdown_function(static function () {
				if (defined('DR_STEP_DONE') || empty($GLOBALS['drState']['run']['tasks']))
				{
					return;
				}
				$st =& $GLOBALS['drState'];
				$i = (int)$st['run']['i'];
				if (($st['run']['tasks'][$i]['kind'] ?? '') === 'kernel')
				{
					$st['run']['tasks'][$i]['err'][] = 'выполнение прервано (die/exit или фатальная ошибка в ядре, модуле или скрипте клиента)';
					$st['run']['tasks'][$i]['done'] = true;
					$st['run']['i'] = $i + 1;
					dr_run_log($st['run'], 'Шаг ядра прерван — см. отчёт', 'error');
					dr_state_save($st);
				}
			});
			ob_start();
			require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
			ob_end_clean();
			$drKernel = true;
			$drState = dr_state_load();
		}
		dr_step($drState, $drCfg, $drKernel);
	}

	if ($drAction === 'cancel')
	{
		dr_check_csrf($drState);
		if (!empty($drState['run']))
		{
			dr_logf('Обработка сброшена на шаге ' . (int)($drState['run']['i'] ?? 0));
			$drState['run'] = [];
			dr_state_save($drState);
		}
		// словарь людей без соли бесполезен, но и держать его в базе незачем
		try
		{
			DrDb::connect()->exec('DROP TABLE IF EXISTS ' . dr_qi(DR_DICT_TABLE));
		}
		catch (Throwable $e)
		{
			dr_logf('Словарь людей не удалён: ' . $e->getMessage());
		}
		dr_redirect(dr_self_url());
	}

	if ($drAction === 'save_fields')
	{
		dr_check_csrf($drState);
		dr_action_save_fields();
	}

	if ($drPhase === 'fields')
	{
		dr_view_fields($drCfg, $drState);
	}

	if ($drAction === 'prepare')
	{
		dr_action_prepare($drCfg, $drState);
	}

	if ($drAction === 'start')
	{
		dr_check_csrf($drState);
		$drToken = (string)($_POST['token'] ?? '');
		$drByToken = $drToken !== '' && !empty($drState['token']) && hash_equals((string)$drState['token'], $drToken);
		if (!$drByToken && trim((string)($_POST['confirm_domain'] ?? '')) !== dr_host())
		{
			dr_fatal('Домен не подтверждён', 'Введённый домен не совпадает с текущим (' . dr_host() . ').', 400);
		}
		$drDb = DrDb::connect();
		$drInfo = dr_detect($drDb);
		$drKey = $drInfo['prod'] !== '' ? $drInfo['prod'] : dr_clean_host($_POST['profile_key'] ?? '');
		dr_build_run($drCfg, $drState, dr_opts_from_post($drCfg), $drKey);
		dr_view_runner($drState);
	}

	if ($drPhase === 'import' || $drPhase === 'import_status')
	{
		dr_import_page($drCfg, $drState, $drPhase === 'import_status');
	}

	if ($drPhase === 'after')
	{
		$drToken = (string)($_GET['token'] ?? '');
		if ($drToken === '' || empty($drState['token']) || !hash_equals((string)$drState['token'], $drToken))
		{
			dr_page('Восстановление', '<div class="alert alert-warning">Ссылка возврата из restore.php устарела. Обработать базу можно с главной страницы.</div>');
		}
		// фоновый импорт ещё идёт или не удался — обрабатывать недогруженную базу нельзя
		$drJob = dr_import_job();
		if (dr_import_alive() || ($drJob && hash_equals((string)$drJob['token'], $drToken) && ($drJob['status'] ?? '') !== 'done'))
		{
			dr_redirect(dr_self_url(['phase' => 'import', 'token' => $drToken]));
		}
		dr_logf('Возврат из restore.php: ' . basename((string)($_GET['arc_name'] ?? '')) . ' / ' . basename((string)($_GET['dump_name'] ?? '')));
		[$drDb, $drInfo, $drErr] = dr_try_db();
		if (!$drInfo)
		{
			dr_fatal('База недоступна после восстановления', $drErr, 500);
		}
		$drProfile = dr_profiles()[$drInfo['prod']] ?? null;
		if ($drProfile && !$drInfo['looks_prod'] && $drInfo['prod'] !== '')
		{
			// Клиент известен — запускаем сразу с его настройками: чем раньше обезличим, тем лучше.
			dr_build_run($drCfg, $drState, dr_default_opts($drCfg, $drProfile), $drInfo['prod']);
			dr_view_runner($drState);
		}
		dr_page('Восстановление завершено', '<div class="alert alert-success">База восстановлена. Клиент <b>' . dr_h($drInfo['prod'] ?: '?') . '</b> здесь впервые — выберите настройки, они запомнятся.</div>'
			. dr_view_process_form($drCfg, $drState, $drInfo, $drToken, 'Обезличить и настроить', 'Пока база не обработана, не открывайте сайт: агенты и почта ещё не отключены.'));
	}

	if ($drPhase === 'run' && !empty($drState['run']['token']) && empty($drState['run']['finished']))
	{
		dr_view_runner($drState);
	}

	dr_view_dashboard($drCfg, $drState);
}
catch (Throwable $e)
{
	$drMsg = dr_redact((array)($drState['run'] ?? []), $e->getMessage());
	dr_logf('ОШИБКА: ' . $drMsg);
	dr_fatal('Ошибка', $drMsg, 500);
}
