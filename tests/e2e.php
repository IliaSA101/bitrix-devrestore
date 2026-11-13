<?php
/**
 * Сквозной тест devrestore.php на одноразовой MySQL/MariaDB.
 *
 *   php tests/e2e.php --port=3399 [--php=путь/к/php для веб-сервера] [--name=mysql80] [--work=папка] [--src=копия devrestore.php] [--http=порт]
 *
 * Создаёт базу drtest_<name> (root без пароля), сайт-заглушку с ядром из tests/stub, поднимает php -S
 * и проходит: подготовка → «восстановление» → возврат из restore.php → обработка → проверки SELECT-ами;
 * затем повторный прогон, автозапуск для известного клиента, режим scrub и отказы защиты.
 * Вывод: одна строка на проверку, OK/FAIL; код выхода 1 при любом FAIL.
 */

$opt = getopt('', ['port:', 'php::', 'name::', 'work::', 'src::', 'http::']);
$dbPort = (int)($opt['port'] ?? 0) ?: die("--port обязателен\n");
$phpBin = $opt['php'] ?? PHP_BINARY;
$name = $opt['name'] ?? 'db' . $dbPort;
$work = rtrim(str_replace('\\', '/', $opt['work'] ?? sys_get_temp_dir()), '/') . '/devrestore-e2e-' . $name;
$projectRoot = str_replace('\\', '/', dirname(__DIR__));
$host = 'acme.local';
$prodHost = 'bitrix24.acme.ru';
$fails = 0;
$checks = 0;

function ok(string $title, bool $cond, string $detail = ''): void
{
	global $fails, $checks;
	$checks++;
	if (!$cond)
	{
		$fails++;
	}
	echo ($cond ? 'OK   ' : 'FAIL ') . $title . ($cond || $detail === '' ? '' : ' — ' . $detail) . PHP_EOL;
}

function rrmdir(string $d): void
{
	if (!is_dir($d))
	{
		return;
	}
	foreach (scandir($d) as $f)
	{
		if ($f !== '.' && $f !== '..')
		{
			is_dir("$d/$f") ? rrmdir("$d/$f") : unlink("$d/$f");
		}
	}
	rmdir($d);
}

/** .settings.php как сейчас на диске: у CLI с opcache повторный include в пределах revalidate_freq отдаёт старую копию */
function settings_now(string $file): array
{
	if (function_exists('opcache_invalidate'))
	{
		opcache_invalidate($file, true);
	}
	return include $file;
}

function put(string $path, string $content): void
{
	if (!is_dir(dirname($path)))
	{
		mkdir(dirname($path), 0777, true);
	}
	file_put_contents($path, $content);
}

// ---------------------------------------------------------------- база

$dbName = 'drtest_' . preg_replace('/\W/', '_', $name);
$db = new mysqli('127.0.0.1', 'root', '', '', $dbPort);
$db->set_charset('utf8mb4');
$db->query("DROP DATABASE IF EXISTS `$dbName`");
$db->query("CREATE DATABASE `$dbName` CHARACTER SET utf8mb4");
$db->select_db($dbName);

function q(string $sql)
{
	global $db;
	$r = $db->query($sql);
	if ($r === false)
	{
		throw new RuntimeException($db->error . ' :: ' . $sql);
	}
	return $r;
}

/** Строковый литерал SQL для seed. */
function lit(string $v): string
{
	global $db;
	return "'" . $db->real_escape_string($v) . "'";
}

function v(string $sql)
{
	$row = q($sql)->fetch_row();
	return $row ? $row[0] : null;
}

function col(string $sql): array
{
	return array_map(static function ($r) {
		return $r[0];
	}, q($sql)->fetch_all());
}

/** Схема и данные «боевой» базы. Как restore.php: пересоздаёт только свои таблицы. */
function seed_prod(): void
{
	global $prodHost;
	$tables = [
		'b_option' => "MODULE_ID varchar(50) NOT NULL, NAME varchar(100) NOT NULL, VALUE mediumtext, DESCRIPTION varchar(255), SITE_ID char(2), PRIMARY KEY (MODULE_ID, NAME)",
		'b_option_site' => "MODULE_ID varchar(50) NOT NULL, NAME varchar(100) NOT NULL, SITE_ID char(2) NOT NULL, VALUE mediumtext, PRIMARY KEY (MODULE_ID, NAME, SITE_ID)",
		'b_module' => "ID varchar(50) NOT NULL PRIMARY KEY, DATE_ACTIVE datetime NULL",
		'b_agent' => "ID int NOT NULL AUTO_INCREMENT PRIMARY KEY, NAME text, ACTIVE char(1) NOT NULL DEFAULT 'Y'",
		'b_lang' => "LID char(2) NOT NULL PRIMARY KEY, SORT int NOT NULL DEFAULT 100, DEF char(1) NOT NULL DEFAULT 'N', ACTIVE char(1) NOT NULL DEFAULT 'Y', NAME varchar(50), SERVER_NAME varchar(255), DOC_ROOT varchar(255)",
		'b_lang_domain' => "LID char(2) NOT NULL, DOMAIN varchar(255) NOT NULL, PRIMARY KEY (LID, DOMAIN)",
		'b_user' => "ID int NOT NULL AUTO_INCREMENT PRIMARY KEY, LOGIN varchar(50) NOT NULL, PASSWORD varchar(255) NOT NULL, CHECKWORD varchar(255), EMAIL varchar(255), NAME varchar(50), LAST_NAME varchar(50), SECOND_NAME varchar(50), PERSONAL_PHONE varchar(255), PERSONAL_MOBILE varchar(255), PERSONAL_BIRTHDAY date NULL, PERSONAL_PHOTO int NULL, WORK_COMPANY varchar(255), PERSONAL_NOTES text, EXTERNAL_AUTH_ID varchar(255), XML_ID varchar(255), ACTIVE char(1) NOT NULL DEFAULT 'Y', UNIQUE KEY ix_login (LOGIN)",
		'b_user_field' => "ID int NOT NULL AUTO_INCREMENT PRIMARY KEY, ENTITY_ID varchar(50), FIELD_NAME varchar(50), USER_TYPE_ID varchar(50), MULTIPLE char(1) NOT NULL DEFAULT 'N'",
		'b_uts_crm_contact' => "VALUE_ID int NOT NULL PRIMARY KEY, UF_CRM_PHONE varchar(255), UF_CRM_CODE varchar(255), UF_CRM_EMAILS text, UF_CRM_BIRTH date NULL",
		'b_utm_crm_contact' => "ID int NOT NULL AUTO_INCREMENT PRIMARY KEY, VALUE_ID int NOT NULL, FIELD_ID int NOT NULL, VALUE text, VALUE_INT int NULL",
		'b_crm_contact' => "ID int NOT NULL PRIMARY KEY, NAME varchar(50), LAST_NAME varchar(50), SECOND_NAME varchar(50), FULL_NAME varchar(100), BIRTHDATE date NULL, COMMENTS text, POST varchar(255), ADDRESS text",
		'b_crm_lead' => "ID int NOT NULL PRIMARY KEY, TITLE varchar(255), NAME varchar(50), LAST_NAME varchar(50), FULL_NAME varchar(100), COMPANY_TITLE varchar(255), COMMENTS text",
		'b_crm_deal' => "ID int NOT NULL PRIMARY KEY, TITLE varchar(255), COMMENTS text, OPPORTUNITY decimal(18,2)",
		'b_crm_field_multi' => "ID int NOT NULL AUTO_INCREMENT PRIMARY KEY, ENTITY_ID varchar(16), ELEMENT_ID int, TYPE_ID varchar(16), VALUE_TYPE varchar(50), VALUE varchar(250)",
		'b_crm_addr' => "TYPE_ID int NOT NULL, ENTITY_TYPE_ID int NOT NULL, ENTITY_ID int NOT NULL, ADDRESS_1 varchar(1024), CITY varchar(128), POSTAL_CODE varchar(16), PRIMARY KEY (TYPE_ID, ENTITY_TYPE_ID, ENTITY_ID)",
		'b_crm_requisite' => "ID int NOT NULL PRIMARY KEY, RQ_INN varchar(15), RQ_FIRST_NAME varchar(50), RQ_LAST_NAME varchar(50), ENTITY_TYPE_ID int NULL, ENTITY_ID int NULL, RQ_COMPANY_NAME varchar(255)",
		'b_crm_company' => "ID int NOT NULL PRIMARY KEY, TITLE varchar(255), COMMENTS text",
		'b_crm_dynamic_type' => "ID int NOT NULL PRIMARY KEY, TITLE varchar(255), ENTITY_TYPE_ID int, TABLE_NAME varchar(64)",
		// v2.6: смарт-процесс, тип которого удалён (сирота), и таблицы множественных UF смарт-процессов
		'b_crm_dynamic_items_190' => "ID int NOT NULL PRIMARY KEY, TITLE varchar(255), PHONE varchar(50), EMAIL varchar(100), MANAGER_NAME varchar(100)",
		'b_crm_dynamic_items_190_uf_crm_9_phones' => "ID int NOT NULL, VALUE text",
		'b_crm_dynamic_items_128_uf_crm_3_phones' => "ID int NOT NULL, VALUE text",
		// v2.6: токены в первичном и уникальном ключе, адреса модуля location
		'b_mail_user_relations' => "TOKEN varchar(32) NOT NULL PRIMARY KEY, USER_ID int, ENTRY_ID int",
		'b_dav_tokens' => "TOKEN varchar(64) NOT NULL PRIMARY KEY, USER_ID int",
		'b_disk_object_lock' => "ID int NOT NULL AUTO_INCREMENT PRIMARY KEY, TOKEN varchar(128) NOT NULL, OBJECT_ID int, UNIQUE KEY ux_token (TOKEN)",
		'b_location_name' => "ID int NOT NULL PRIMARY KEY, LOCATION_ID int, LANGUAGE_ID char(2), NAME varchar(100)",
		'b_location_addr_fld' => "ADDRESS_ID int NOT NULL, TYPE int NOT NULL, VALUE varchar(1024), VALUE_NORMALIZED varchar(1024), PRIMARY KEY (ADDRESS_ID, TYPE)",
		'b_crm_dynamic_items_128' => "ID int NOT NULL PRIMARY KEY, TITLE varchar(255), UF_CRM_3_CLIENT_FIO varchar(255), UF_CRM_3_NOTE varchar(255)",
		'b_crm_timeline' => "ID int NOT NULL PRIMARY KEY, COMMENT text NULL, SETTINGS text",
		// v2.8: JSON-параметры сообщений чата; история лендингов без первичного ключа — сериализованное там не чистится
		'b_im_message_param' => "ID int NOT NULL PRIMARY KEY, MESSAGE_ID int, PARAM_NAME varchar(100), PARAM_JSON text",
		'b_landing_history' => "LANDING_ID int NOT NULL, ACTION varchar(50), ACTION_PARAMS text",
		'b_im_message' => "ID int NOT NULL AUTO_INCREMENT PRIMARY KEY, CHAT_ID int, MESSAGE text, MESSAGE_OUT text NULL",
		'b_sale_order_props' => "ID int NOT NULL PRIMARY KEY, NAME varchar(255), TYPE varchar(20), CODE varchar(50), IS_EMAIL char(1) DEFAULT 'N', IS_PHONE char(1) DEFAULT 'N', IS_PROFILE_NAME char(1) DEFAULT 'N', IS_PAYER char(1) DEFAULT 'N', IS_ADDRESS char(1) DEFAULT 'N', IS_ZIP char(1) DEFAULT 'N'",
		'b_sale_order_props_value' => "ID int NOT NULL AUTO_INCREMENT PRIMARY KEY, ORDER_ID int, ORDER_PROPS_ID int, NAME varchar(255), VALUE varchar(500), CODE varchar(50)",
		'b_event' => "ID int NOT NULL AUTO_INCREMENT PRIMARY KEY, EVENT_NAME varchar(255), C_FIELDS longtext",
		'b_search_content' => "ID int NOT NULL PRIMARY KEY, BODY longtext",
		'b_rest_event' => "ID int NOT NULL PRIMARY KEY, EVENT_HANDLER varchar(255)",
		'b_rest_ap' => "ID int NOT NULL PRIMARY KEY, USER_ID int, PASSWORD varchar(50)",
		'b_mail_mailbox' => "ID int NOT NULL PRIMARY KEY, ACTIVE char(1), LOGIN varchar(255), PASSWORD varchar(255), EMAIL varchar(255)",
		'b_file' => "ID int NOT NULL PRIMARY KEY, ORIGINAL_NAME varchar(255), FILE_NAME varchar(255), DESCRIPTION varchar(255)",
		'b_pull_push' => "ID int NOT NULL PRIMARY KEY, DEVICE_TOKEN varchar(255)",
		'b_hlbd_clients' => "ID int NOT NULL PRIMARY KEY, UF_FIO varchar(255)",
		'b_user_field_lang' => "USER_FIELD_ID int NOT NULL, LANGUAGE_ID char(2) NOT NULL, EDIT_FORM_LABEL varchar(255), LIST_COLUMN_LABEL varchar(255), PRIMARY KEY (USER_FIELD_ID, LANGUAGE_ID)",
		'b_user_phone_auth' => "USER_ID int NOT NULL PRIMARY KEY, PHONE_NUMBER varchar(25) NOT NULL, UNIQUE KEY ix_phone (PHONE_NUMBER)",
		'b_pull_channel' => "ID int NOT NULL PRIMARY KEY, CHANNEL_ID varchar(50)",
		'b_bp_tracking' => "ID int NOT NULL AUTO_INCREMENT PRIMARY KEY, WORKFLOW_ID varchar(32), TYPE int, ACTION_NAME varchar(128), ACTION_TITLE varchar(255), ACTION_NOTE text",
		'b_bp_task' => "ID int NOT NULL AUTO_INCREMENT PRIMARY KEY, WORKFLOW_ID varchar(32), ACTIVITY varchar(128), NAME varchar(128), DESCRIPTION text, DOCUMENT_NAME varchar(255), PARAMETERS text",
	];
	foreach ($tables as $t => $def)
	{
		$charset = $t === 'b_crm_contact' ? 'cp1251' : 'utf8mb4';
		q("DROP TABLE IF EXISTS `$t`");
		q("CREATE TABLE `$t` ($def) ENGINE=InnoDB DEFAULT CHARSET=$charset");
	}
	q("INSERT INTO b_option (MODULE_ID, NAME, VALUE) VALUES
		('main','server_name','$prodHost'), ('main','smtp_password','SuperSecret123'), ('main','check_agents','Y'),
		('main','server_uniq_id','0123456789abcdef0123456789abcdef'),
		('disk','disk_onlyoffice_b24_secretKey','OnlyOfficeKey-1234'), ('disk','default_viewer_service','onlyoffice'),
		('disk','disk_boards_b24_secretKey','BoardsKey-123456'), ('disk','default_viewer_service_boards','boards'),
		('main','cookie_name','BITRIX_SM'), ('main','new_user_password_length','6'), ('imconnector','list','telegrambot'),
		('acme.api','API_KEY','KeepMe-12345'), ('acme.api','backfill_token','TokenMe-12345'), ('acme.api','secret_key','WipeMe-123456')");
	q("INSERT INTO b_option_site VALUES ('main','api_token','s1','abcdefghijkl'), ('acme.api','api_key','s1','SiteKeep-1234')");
	q("INSERT INTO b_module (ID) VALUES ('main'), ('crm')");
	q("INSERT INTO b_agent (NAME, ACTIVE) VALUES ('CCrmAgent::Run();','Y'), ('CSearch::Agent();','Y'), ('Old::Agent();','N')");
	q("INSERT INTO b_lang (LID, SORT, DEF, ACTIVE, NAME, SERVER_NAME) VALUES ('s1', 1, 'Y', 'Y', 'Портал', '$prodHost'), ('s2', 2, 'N', 'Y', 'Магазин', 'shop.acme.ru')");
	q("INSERT INTO b_lang_domain VALUES ('s1', '$prodHost'), ('s2', 'shop.acme.ru')");
	q("INSERT INTO b_user (ID, LOGIN, PASSWORD, EMAIL, NAME, LAST_NAME, SECOND_NAME, PERSONAL_PHONE, PERSONAL_BIRTHDAY, WORK_COMPANY, PERSONAL_NOTES, EXTERNAL_AUTH_ID, XML_ID) VALUES
		(1, 'director', 'hash1', 'ivan.petrov@acme.ru', 'Иван', 'Петров', 'Сергеевич', '+7 (916) 123-45-67', '1980-05-01', 'ООО Акме', 'любит рыбалку', NULL, NULL),
		(2, 'maria', 'hash2', 'maria.sidorova@acme.ru', 'Мария', 'Сидорова', 'Петровна', '8 916 765-43-21', NULL, '', '', NULL, NULL),
		(3, 'nikita', 'hash3', 'nikita@acme.ru', 'Никита', 'Смирнов', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
		(4, 'empty', 'hash4', NULL, NULL, '', NULL, '', NULL, NULL, NULL, NULL, NULL),
		(5, 'imconnector_5', 'hash5', '', 'Telegram', 'Гость', NULL, NULL, NULL, NULL, NULL, 'imconnector', 'imconnector|telegram|987654321')");
	q("INSERT INTO b_user_field (ID, ENTITY_ID, FIELD_NAME, USER_TYPE_ID, MULTIPLE) VALUES
		(1, 'CRM_CONTACT', 'UF_CRM_PHONE', 'string', 'N'), (2, 'CRM_CONTACT', 'UF_CRM_CODE', 'string', 'N'),
		(3, 'CRM_CONTACT', 'UF_CRM_EMAILS', 'string', 'Y'), (4, 'CRM_CONTACT', 'UF_CRM_BIRTH', 'date', 'N'),
		(5, 'CRM_3', 'UF_CRM_3_CLIENT_FIO', 'string', 'N'), (11, 'CRM_3', 'UF_CRM_3_NOTE', 'string', 'N'), (13, 'CRM_3', 'UF_CRM_3_PHONES', 'string', 'Y')");
	// поля, созданные в интерфейсе Б24: код ни о чём не говорит, смысл — в подписи
	q("INSERT INTO b_user_field (ID, ENTITY_ID, FIELD_NAME, USER_TYPE_ID, MULTIPLE) VALUES (6, 'CRM_CONTACT', 'UF_CRM_1612345678', 'string', 'N'), (7, 'CRM_CONTACT', 'UF_CRM_1700000000', 'string', 'N')");
	q("INSERT INTO b_user_field_lang VALUES (6, 'ru', 'Паспорт (серия, номер)', 'Паспорт'), (7, 'ru', '', '')");
	q('ALTER TABLE b_uts_crm_contact ADD UF_CRM_1612345678 varchar(255), ADD UF_CRM_1700000000 varchar(255)');
	q("INSERT INTO b_uts_crm_contact VALUES (1000000001, '+7 916 111-22-33', 'VIP-42', 'a:1:{i:0;s:13:\"maria@home.ru\";}', '1990-01-01', '4510 123456', 'Мария Ивановна, муж Пётр')");
	// v2: поля с подписями-ловушками и поле, решение по которому принимают на странице «Поля клиента»
	q("INSERT INTO b_user_field (ID, ENTITY_ID, FIELD_NAME, USER_TYPE_ID, MULTIPLE) VALUES (8, 'CRM_CONTACT', 'UF_CRM_1700000001', 'string', 'N'),
		(9, 'CRM_CONTACT', 'UF_CRM_1700000002', 'string', 'N'), (10, 'CRM_CONTACT', 'UF_CRM_1700000003', 'string', 'N')");
	q("INSERT INTO b_user_field_lang VALUES (8, 'ru', 'Номер тендера', ''), (9, 'ru', 'Количество адресов', ''), (10, 'ru', 'Карточка склада', '')");
	q('ALTER TABLE b_uts_crm_contact ADD UF_CRM_1700000001 varchar(255), ADD UF_CRM_1700000002 varchar(255), ADD UF_CRM_1700000003 varchar(255)');
	q("UPDATE b_uts_crm_contact SET UF_CRM_1700000001 = 'код 15047, тел. +7 912 345-67-89, Гончаренко А. В.', UF_CRM_1700000002 = '3 адреса: Тверь', UF_CRM_1700000003 = 'СК-12'");
	// v2.3: слаг из многих частей через дефис ронял разбор имён по памяти (1 ГБ на пяти частях)
	q("INSERT INTO b_user_field (ID, ENTITY_ID, FIELD_NAME, USER_TYPE_ID, MULTIPLE) VALUES (12, 'CRM_CONTACT', 'UF_CRM_1700000004', 'string', 'N')");
	q("INSERT INTO b_user_field_lang VALUES (12, 'ru', '', '')");
	q('ALTER TABLE b_uts_crm_contact ADD UF_CRM_1700000004 varchar(255)');
	q("UPDATE b_uts_crm_contact SET UF_CRM_1700000004 = 'https://tenderland.ru/tender/Postavka-Meditsinskikh-Izdeliy-Dlya-Nuzhd-Goroda-Moskvy-V-Godu-Lot-Sem Шприц-Инъекционный-Трехкомпонентный-Одноразовый-Стерильный-Луер'");
	q("INSERT INTO b_user_phone_auth VALUES (1, '+79161234567'), (2, '+79167654321')");
	q("INSERT INTO b_pull_channel VALUES (1, 'prodchannel')");
	// WORKFLOW_ID и ACTIVITY с 11 цифрами внутри hex: проверка утечек не должна принять их за телефон
	q("INSERT INTO b_bp_tracking VALUES (1, '5f89161234567abc', 1, 'A12345_approve', 'Согласование договора', 'Согласовал Петров Иван Сергеевич, тел. +7 916 123-45-67, договор 7654321')");
	// WORKFLOW_ID задания: 11 цифр между дефисами проходят границы — спасает только правило колонок *_ID
	q("INSERT INTO b_bp_task VALUES (1, 'wf-89161234567', 'A5f89161234567ab_approve', 'Утвердить договор Сидоровой Марии', 'Позвоните Петрову Ивану: +7 916 123-45-67',"
		. " 'Тендер 7654321: звонок Сидоровой', " . lit(serialize(['USER' => 'maria', 'COMMENT' => 'Перезвонить Сидоровой, +7 916 123-45-67', 'LIST' => []])) . ')');
	// JSON как у Bitrix Json::encode (HEX-флаги, \u-экранирование); большое целое и не-JSON — битые, не трогаются
	q('INSERT INTO b_im_message_param VALUES (1, 10, ' . lit('ATTACH') . ', ' . lit(json_encode(['ATTACH' => ['CONTACT' => 'Звонок Сидоровой "Заря" & Ко, +7 916 123-45-67',
		'URL' => 'https://example.com/a/b'], 'OPTS' => new stdClass()], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)) . '),'
		. " (2, 10, 'BIG', '{\"ID\":12345678901234567890,\"PHONE\":\"+79161234567\"}'), (3, 10, 'CODE', '{\"CODE\":\"lead_12\"}')");
	q("INSERT INTO b_landing_history VALUES (1, 'EDIT_TEXT', '{\"text\":\"Позвоните +79161234567\"}')");
	q("INSERT INTO b_utm_crm_contact (VALUE_ID, FIELD_ID, VALUE) VALUES (1000000001, 3, 'maria@home.ru'), (1000000001, 2, 'not-multiple-field')");
	q("INSERT INTO b_crm_contact (ID, NAME, LAST_NAME, SECOND_NAME, FULL_NAME, BIRTHDATE, COMMENTS, POST, ADDRESS) VALUES
		(1000000001, 'Мария', 'Сидорова', 'Петровна', 'Мария Сидорова', '1990-01-01', 'Клиентка из Твери, звонить Сидоровой Марии', 'Директор', 'Тверь, ул. Ленина, 5'),
		(1000000005, 'Илья', 'Кузнецов', '', 'Илья Кузнецов', NULL, '', 'Инженер', NULL),
		(1000099999, '', '', NULL, '', NULL, NULL, NULL, '')");
	q("INSERT INTO b_crm_lead VALUES (7, 'Звонок от +7 916 123-45-67', 'Иван', 'Петров', 'Иван Петров', 'ИП Петров', 'перезвонить'),
		(8, 'Лид с сайта', 'Анна', 'Гончаренко', 'Анна Гончаренко', 'ИП Гончаренко А. В.', ''), (9, 'Лид 2', '', '', '', 'ООО Ромашка', ''),
		(10, 'Лид 3', '', 'Тендер', 'Тендер', '', ''), (12, 'Лид 4', 'Олег', 'Кимры', 'Олег Кимры', '', '')");
	// фамилия, совпадающая с городом из справочника местоположений: город в тексте — не утечка имени
	q("INSERT INTO b_location_name VALUES (1, 1, 'ru', 'Кимры'), (2, 2, 'ru', 'Великие Луки')");
	q("INSERT INTO b_crm_deal VALUES (9, 'Поставка для ООО Ромашка', 'см. письмо ivan@acme.ru', 150000.00),
		(10, 'Тендер 7654321: звонок Сидоровой', 'Звонил Петрову Ивану, договорились. Копия Сидоровой М. П., тел. 8 (4012) 12-34-56', 0),
		(11, 'Поставка в Орловская область', '15047 : 28.10.2026 16:49:17', 0)");
	q("INSERT INTO b_crm_company VALUES (500, 'ООО Ромашка', 'пишите иван@почта.рф или ivan@mаil.ru'), (501, 'Гончаренко Андрей Викторович', ''), (503, 'Петренко Ольга Ивановна', '')");
	q("INSERT INTO b_crm_dynamic_type VALUES (3, 'Тендеры', 128, 'b_crm_dynamic_items_128')");
	q("INSERT INTO b_crm_dynamic_items_190 VALUES (1, 'Кандидат', '+7 916 555-66-77', 'candidate@mail.ru', 'Петров Иван Сергеевич')");
	q("INSERT INTO b_crm_dynamic_items_190_uf_crm_9_phones VALUES (1, '+7 916 555-66-78')");
	q("INSERT INTO b_crm_dynamic_items_128_uf_crm_3_phones VALUES (1, '+7 916 222-33-44'), (1, '')");
	q("INSERT INTO b_mail_user_relations VALUES ('a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6', 1, 1), ('ffeeddccbbaa99887766554433221100', 2, 2), ('0123456789abcdef0123456789abcdef', 3, 3)");
	q("INSERT INTO b_dav_tokens VALUES ('davtoken1234567890', 1), ('davtoken0987654321', 2)");
	q("INSERT INTO b_disk_object_lock (TOKEN, OBJECT_ID) VALUES ('locktoken-1111111111', 1), ('locktoken-2222222222', 2)");
	q("INSERT INTO b_location_addr_fld VALUES (1, 300, 'Тверь, ул. Ленина, 5, кв. 12', 'тверь ленина 5 12'), (2, 300, 'Москва, Тверская, 1', 'москва тверская 1')");
	q("INSERT INTO b_crm_field_multi (ENTITY_ID, ELEMENT_ID, TYPE_ID, VALUE_TYPE, VALUE) VALUES
		('CONTACT', 1000000001, 'PHONE', 'WORK', '+7 (916) 123-45-67'), ('LEAD', 7, 'PHONE', 'WORK', '8 916 123 45 67'),
		('CONTACT', 1000000001, 'EMAIL', 'WORK', 'maria.sidorova@acme.ru'), ('CONTACT', 1000000001, 'WEB', 'WORK', 'https://maria.ru'),
		('CONTACT', 1000000001, 'IM', 'TELEGRAM', 'imol|telegram|555')");
	q("INSERT INTO b_crm_addr VALUES (1, 3, 1000000001, 'ул. Ленина, 5, кв. 12', 'Тверь', '170000'), (6, 3, 1000000005, '', 'Москва', NULL)");
	q("INSERT INTO b_crm_requisite (ID, RQ_INN, RQ_FIRST_NAME, RQ_LAST_NAME, ENTITY_TYPE_ID, ENTITY_ID, RQ_COMPANY_NAME) VALUES (1, '7701234567', 'Мария', 'Сидорова', NULL, NULL, NULL),
		(2, '500100732259', '', '', 4, 501, 'Гончаренко Андрей Викторович'), (3, '7707083893', '', '', 4, 500, 'ООО Ромашка'),
		(4, '500100732260', '', '', 4, 502, 'Пасека «Заря»')");
	q("INSERT INTO b_crm_dynamic_items_128 VALUES (1, 'Заявка Иванова', 'Иванов Иван Иванович', 'Связаться с Сидоровой Марией Петровной')");
	q("INSERT INTO b_crm_timeline VALUES (1, 'Клиент просил перезвонить', 'a:1:{s:5:\"PHONE\";s:12:\"+79161234567\";}')");
	$rows = [];
	for ($i = 2; $i <= 3001; $i++)
	{
		$rows[] = "($i, 'Звонок Сидоровой Марии №$i', NULL)";
	}
	q('INSERT INTO b_crm_timeline VALUES ' . implode(',', $rows));
	// длина s:N не совпадает — значение битое: не трогаем, называем в отчёте, проверка утечек его находит
	q("INSERT INTO b_crm_timeline VALUES (3003, 'Ошибка формата', 'a:1:{s:4:\"MAIL\";s:99:\"broken@client.ru\";}')");
	q("INSERT INTO b_crm_timeline VALUES (3002, 'Звонок Сидоровой Марии по https://tenderland.ru/tender/Postavka-Meditsinskikh-Izdeliy-Dlya-Nuzhd-Goroda-Moskvy-V-Godu-Lot-Sem', NULL)");
	$rows = [];
	for ($i = 1; $i <= 25000; $i++)
	{
		$msg = $i % 10 === 0 ? 'Позвоните мне +7 916 123-45-67 или пишите ivan@mail.ru, Иван' : ($i % 97 === 0 ? '' : "Сообщение номер $i от клиента");
		$rows[] = "($i, " . ($i % 50) . ", '$msg', " . ($i % 3 ? 'NULL' : "'out $i'") . ')';
		if (count($rows) === 2500)
		{
			q('INSERT INTO b_im_message (ID, CHAT_ID, MESSAGE, MESSAGE_OUT) VALUES ' . implode(',', $rows));
			$rows = [];
		}
	}
	q("INSERT INTO b_sale_order_props (ID, NAME, TYPE, CODE, IS_EMAIL, IS_PAYER) VALUES (1, 'E-mail', 'STRING', 'EMAIL', 'Y', 'N'), (2, 'ФИО', 'STRING', 'FIO', 'N', 'Y'), (3, 'Местоположение', 'LOCATION', 'LOCATION', 'N', 'N'), (4, 'Комментарий', 'TEXTAREA', 'COMMENT', 'N', 'N')");
	q("INSERT INTO b_sale_order_props_value (ORDER_ID, ORDER_PROPS_ID, NAME, VALUE, CODE) VALUES (100, 1, 'E-mail', 'buyer@mail.ru', 'EMAIL'), (100, 2, 'ФИО', 'Петров Пётр', 'FIO'), (100, 3, 'Местоположение', '0000073738', 'LOCATION'), (100, 4, 'Комментарий', 'Позвонить +7 916 000-11-22', 'COMMENT')");
	q("INSERT INTO b_event (EVENT_NAME, C_FIELDS) VALUES ('NEW_USER', 'a:1:{s:5:\"EMAIL\";s:13:\"maria@home.ru\";}'), ('SALE_NEW_ORDER', 'x')");
	q("INSERT INTO b_search_content VALUES (1, 'Иван Петров ivan.petrov@acme.ru')");
	q("INSERT INTO b_rest_event VALUES (1, 'https://hooks.acme.ru/handler?token=abc')");
	q("INSERT INTO b_rest_ap VALUES (1, 1, 'abcdef1234567890')");
	q("INSERT INTO b_mail_mailbox VALUES (1, 'Y', 'ivan.petrov@acme.ru', 'mailpass123', 'ivan.petrov@acme.ru')");
	q("INSERT INTO b_file VALUES (1, 'Паспорт Иванова.pdf', 'abc123.pdf', 'скан паспорта'), (2, 'README', 'r', '')");
	q("INSERT INTO b_pull_push VALUES (1, 'abcdefghijklmnopqrstuvwxyz')");
	q("INSERT INTO b_hlbd_clients VALUES (1, 'Сидорова Мария')");
}

/** Локальная база до восстановления: прошлая dev-копия + мост MCP + своя таблица, которой нет в дампе. */
function seed_local(): void
{
	global $host;
	seed_prod();
	q("UPDATE b_option SET VALUE = '$host' WHERE MODULE_ID = 'main' AND NAME = 'server_name'");
	q("INSERT INTO b_option (MODULE_ID, NAME, VALUE) VALUES ('delight.mcp', 'jwt_secret_key', 'LOCALSECRET'), ('delight.mcp', 'enabled', 'Y'),
		('devrestore', 'info', '{\"prod_domain\":\"bitrix24.acme.ru\"}')");
	q("INSERT INTO b_module (ID) VALUES ('delight.mcp')");
	q('DROP TABLE IF EXISTS zz_legacy_contacts');
	q('CREATE TABLE zz_legacy_contacts (id int PRIMARY KEY, email varchar(255)) DEFAULT CHARSET=utf8mb4');
	q("INSERT INTO zz_legacy_contacts VALUES (1, 'legacy@client.ru')");
	q('DROP TABLE IF EXISTS zz_legacy_people');
	q('CREATE TABLE zz_legacy_people (id int PRIMARY KEY, note varchar(255)) DEFAULT CHARSET=utf8mb4');
	// имена не из списков двойников: в проверке утечек двойники не считаются
	q("INSERT INTO zz_legacy_people VALUES (1, 'Менеджер Гончаренко Злата Тарасовна')");
	q('DROP TABLE IF EXISTS zz_legacy_towns');
	q('CREATE TABLE zz_legacy_towns (id int PRIMARY KEY, note varchar(255)) DEFAULT CHARSET=utf8mb4');
	q("INSERT INTO zz_legacy_towns VALUES (1, 'Доставка в Кимры до склада')");
}

// ---------------------------------------------------------------- сайт

rrmdir($work);
$docroot = "$work/site/public";
// папка профилей по умолчанию — .devrestore рядом с корнем сайта: в коде DR_HOME пуст, тест ничего не подменяет
$home = "$work/site/.devrestore";
mkdir("$home/scripts", 0777, true);

function build_site(): void
{
	global $docroot, $dbPort, $dbName;
	put("$docroot/bitrix/.settings.php", "<?php\nreturn " . var_export([
		'connections' => ['value' => ['default' => ['className' => '\\Bitrix\\Main\\DB\\MysqliConnection', 'host' => "127.0.0.1:$dbPort", 'database' => $dbName, 'login' => 'root', 'password' => '', 'options' => 2]], 'readonly' => true],
		'smtp' => ['value' => ['enabled' => true, 'debug' => false]],
		'cache' => ['value' => ['type' => 'memcache', 'memcache' => ['host' => 'cache.prod.acme.ru', 'port' => 11211]]],
		'pull' => ['value' => ['path_to_publish' => 'https://push.acme.ru/bitrix/pub/', 'signature_key' => 'PRODSIGN']],
	], true) . ";\n");
	put("$docroot/bitrix/php_interface/dbconn.php", "<?php\n\$DBDebug = false;\ndefine('BX_UTF', true);\n?>\n");
	put("$docroot/bitrix/modules/main/include/prolog_before.php", file_get_contents(__DIR__ . '/stub/prolog_before.php'));
	put("$docroot/bitrix/modules/delight.mcp/install/index.php", "<?php\nclass delight_mcp\n{\n\tpublic function InstallDB()\n\t{\n\t\t\\Bitrix\\Main\\ModuleManager::registerModule('delight.mcp');\n\t\t\\Bitrix\\Main\\Config\\Option::set('delight.mcp', 'jwt_secret_key', 'NEWSECRET');\n\t\techo 'install html';\n\t}\n}\n");
	put("$docroot/bitrix/managed_cache/MYSQL/b_option/x.php", '<?php // кеш настроек');
	put("$docroot/bitrix/cache/s1/menu.php", 'x');
	put("$docroot/bitrix/backup/old_dump.sql", 'old');
	put("$docroot/bitrix/backup/old_dump_after_connect.sql", 'old');
	put("$docroot/old.tar.gz", 'old');
	touch("$docroot/old.tar.gz", time() - 86400 * 3);
	put("$docroot/new.tar.gz", 'new');
	put("$docroot/new.tar.gz.1", 'new part');
	put("$docroot/restore.php", fake_restore(true));
}

/**
 * Дамп текущей базы в формате резервной копии Битрикс: по строке на оператор (DROP, CREATE, INSERT пачками),
 * тома режутся посреди строк, рядом _after_connect.sql. Свои таблицы локальной базы (zz_) в дамп не идут.
 */
function make_dump(string $base, int $parts): array
{
	global $db;
	$tables = array_values(array_filter(col('SHOW TABLES'), static function ($t) {
		return strpos($t, 'zz_') !== 0;
	}));
	$out = '-- Started: ' . date('c') . "\n";
	foreach ($tables as $t)
	{
		$out .= "DROP TABLE IF EXISTS `$t`;\n" . q("SHOW CREATE TABLE `$t`")->fetch_row()[1] . ";\n";
		$res = q("SELECT * FROM `$t`");
		$rows = [];
		while (($r = $res->fetch_row()) !== null)
		{
			$rows[] = '(' . implode(',', array_map(static function ($v) use ($db) {
				return $v === null ? 'NULL' : "'" . $db->real_escape_string((string)$v) . "'";
			}, $r)) . ')';
			if (count($rows) === 300)
			{
				$out .= "INSERT INTO `$t` VALUES " . implode(',', $rows) . ";\n";
				$rows = [];
			}
		}
		if ($rows)
		{
			$out .= "INSERT INTO `$t` VALUES " . implode(',', $rows) . ";\n";
		}
	}
	// повтор строки, как бывает в дампах живой базы: restore.php пропускает ошибку дубля, импорт — тоже
	$out .= "INSERT INTO `b_option` VALUES " . explode("
", explode('INSERT INTO `b_option` VALUES ', $out, 2)[1], 2)[0] . "
";
	$size = (int)ceil(strlen($out) / $parts);
	for ($i = 0; $i < $parts; $i++)
	{
		put($base . '.sql' . ($i ? '.' . $i : ''), substr($out, $i * $size, $size));
	}
	put($base . '_after_connect.sql', "SET NAMES 'utf8mb4';\nSET @dr_after_connect = '<DATABASE>';\n");
	return $tables;
}

/** Минимальный restore.php: шаг 3 (конец импорта, $oDB->Query) и шаг 5 — те места, куда встраивается devrestore. */
function fake_restore(bool $withAnchors, bool $selfUpdate = true): string
{
	global $dbPort, $dbName;
	$step3 = $withAnchors ? "\t\tif (!\$oDB->ft_index)\n\t\t{\n\t\t\t\$oDB->Query('SELECT 1');\n\t\t}\n" : '';
	// самообновление как в настоящем restore.php: размер не совпал с версией на сайте Битрикс — «скачать» себя заново,
	// то есть перезаписаться чистой версией без вставок devrestore
	$update = $selfUpdate ? "if (!\$Step && \$_SERVER['REQUEST_METHOD'] == 'GET')\n{\n\tif (filesize(__FILE__) != trim('1'))\n\t{\n"
		. "\t\tfile_put_contents(__FILE__, '<?php echo 1;');\n\t\techo 'UPDATE_SUCCESS';\n\t\texit;\n\t}\n}\n" : '';
	return "<?php\n// заглушка restore.php для e2e\nob_start();\n\$Step = intval(\$_REQUEST['Step'] ?? 0);\n" . $update
		. "if (\$Step == 1)\n{\n\techo 'step1';\n}\n"
		. "elseif (\$Step == 3)\n{\n" . ($withAnchors ? "\tif (!isset(\$_REQUEST['d_pos'])) // start\n\t{\n\t\tSetCurrentProgress(0);\n\t\t\$r = true;\n\t}\n" : '')
		. "\t\$oDB = new CDBRestore();\n\tif (true)\n\t{\n$step3\t\techo 'finish';\n\t}\n}\n"
		. ($withAnchors ? "elseif (\$Step == 5)\n{\n\techo 'files deleted, going to /';\n}\n" : '')
		. "class CDBRestore\n{\n\tpublic \$ft_index = true;\n\tpublic function Query(\$sql, \$ignore = false)\n\t{\n"
		. "\t\t\$l = new mysqli('127.0.0.1', 'root', '', '$dbName', $dbPort);\n\t\treturn @\$l->query(\$sql) ?: (\$ignore ? true : false);\n\t}\n}\n"
		. "function SetCurrentProgress(\$c) {}\n// \$Step == 5\n";
}

build_site();
$src = file_get_contents($opt['src'] ?? "$projectRoot/devrestore.php");
ok('DR_HOME в коде пуст: папка профилей — .devrestore рядом с корнем сайта', strpos($src, "const DR_HOME = '';") !== false);
put("$docroot/devrestore.php", $src);
put("$home/scripts/acme.php", <<<'PHP'
<?php
return [
	'title' => 'ACME: тестовый скрипт',
	'anonymize' => [['table' => 'b_hlbd_clients', 'set' => ['UF_FIO' => 'full_name']]],
	'uf_keep' => ['UF_CRM_CODE'],
	'options_keep' => ['acme.api/API_KEY'],
	'sql' => ["INSERT INTO b_option (MODULE_ID, NAME, VALUE) VALUES ('acme', 'sql_ran', 'Y') ON DUPLICATE KEY UPDATE VALUE = 'Y'"],
	'run' => function (DevRestoreContext $ctx) {
		\Bitrix\Main\Config\Option::set('acme', 'ran', $ctx->prodDomain . '>' . $ctx->devHost);
		$ctx->log('acme ok', 'ok');
	},
];
PHP);
put("$home/scripts/demo-module.php", <<<'PHP'
<?php
// Скрипт клиента с установкой своего модуля: в заглушке модуля нет — шаг ядра должен закрыться с ошибкой
return [
	'title' => 'Демо: модуль demo.module',
	'options_keep' => ['acme.api/backfill_token', 'acme.api/NO_SUCH'],
	'run' => function (DevRestoreContext $ctx) {
		$moduleId = 'demo.module';
		if (\Bitrix\Main\ModuleManager::isModuleInstalled($moduleId))
		{
			$ctx->log("Модуль {$moduleId} уже установлен");
			return;
		}
		$installPath = $_SERVER['DOCUMENT_ROOT'] . "/local/modules/{$moduleId}/install/index.php";
		if (!file_exists($installPath))
		{
			$ctx->log("Файл установки модуля не найден: {$installPath}", 'error');
		}
	},
];
PHP);
put("$home/scripts/zz-die.php", "<?php\nreturn ['title' => 'Скрипт, который делает die()', 'run' => function (DevRestoreContext \$ctx) {\n\t\$ctx->log('zz-die: сейчас будет die', 'warn');\n\tdie('admin page html');\n}];\n");
seed_local();

// ---------------------------------------------------------------- веб-сервер

$httpPort = (int)($opt['http'] ?? 0) ?: 18000 + $dbPort % 1000;
// DEVRESTORE_HOME указывает на общую папку, которой пока нет: до её появления действует .devrestore рядом с корнем
$sharedHome = "$work/shared-home";
$server = proc_open([$phpBin, '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'error_reporting=-1', '-S', "127.0.0.1:$httpPort", '-t', $docroot], [1 => ['file', "$work/server.log", 'a'], 2 => ['file', "$work/server.log", 'a']], $pipes,
	null, ['DEVRESTORE_HOME' => $sharedHome] + getenv());
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $httpPort); $i++)
{
	usleep(100000);
}

function http(string $method, string $path, array $data = [], string $h = null, string $extra = ''): array
{
	global $httpPort, $host;
	$ctx = stream_context_create(['http' => [
		'method' => $method,
		'header' => 'Host: ' . ($h ?? $host) . "\r\nContent-Type: application/x-www-form-urlencoded\r\n" . $extra,
		'content' => $data ? http_build_query($data) : '',
		'ignore_errors' => true,
		'follow_location' => 0,
		'timeout' => 300,
	]]);
	$body = (string)@file_get_contents("http://127.0.0.1:$httpPort$path", false, $ctx);
	$GLOBALS['lastHeaders'] = $http_response_header ?? [];
	$status = 0;
	$location = '';
	foreach ($http_response_header ?? [] as $line)
	{
		if (preg_match('~^HTTP/\S+ (\d+)~', $line, $m))
		{
			$status = (int)$m[1];
		}
		if (stripos($line, 'Location:') === 0)
		{
			$location = trim(substr($line, 9));
		}
	}
	return [$status, $body, $location];
}

function csrf(string $html): string
{
	return preg_match('/name="csrf" value="([0-9a-f]+)"/', $html, $m) ? $m[1] : '';
}

function groups(string $html): array
{
	preg_match_all('/name="groups\[\]" value="([^"]+)"/', $html, $m);
	return array_map(static function ($g) {
		return html_entity_decode($g, ENT_QUOTES, 'UTF-8');
	}, $m[1]);
}

function state(): array
{
	global $home;
	$f = glob("$home/state/*.json");
	return $f ? json_decode(file_get_contents($f[0]), true) : [];
}

/** Прогоняет шаги до конца. Возвращает [журнал, отчёт, соль, ответы]. $afterFirst — вызывается после первого шага. */
/** Сколько запросов с солью помнит performance_schema; null — недоступна (MariaDB по умолчанию). */
function ps_salt(string $salt): ?int
{
	try
	{
		return (int)v("SELECT COUNT(*) FROM performance_schema.events_statements_summary_by_digest WHERE QUERY_SAMPLE_TEXT LIKE '%$salt%'")
			+ (int)v("SELECT COUNT(*) FROM performance_schema.events_statements_history_long WHERE SQL_TEXT LIKE '%$salt%'");
	}
	catch (Throwable $e)
	{
		return null;
	}
}

function run_steps(string $html, ?callable $afterFirst = null): array
{
	if (!preg_match('/window\.DR=(\{.*?\});/', $html, $m))
	{
		return [[], '', '', ['нет window.DR: ' . substr(strip_tags($html), 0, 300)]];
	}
	$token = json_decode($m[1], true)['token'];
	$log = [];
	$salt = state()['run']['salt'] ?? '';
	$notJson = 0;
	for ($i = 0; $i < 400; $i++)
	{
		// браузер с cookie прежней копии: сессия, вход Битрикс по префиксу сайта и по умолчанию, ключ devrestore
		[$st, $body] = http('POST', '/devrestore.php', ['action' => 'step', 'token' => $token], null,
			"Cookie: PHPSESSID=oldsession123; BITRIX_SM_ACME_LOCAL_UIDH=oldhash; BITRIX_SM_LOGIN=olduser; dr_key=keepme\r\n");
		$j = json_decode($body, true);
		if (!is_array($j))
		{
			// прерванный шаг (die в скрипте клиента): что осталось в состоянии, если пользователь так и бросит вкладку
			$GLOBALS['saltOnBreak'] = array_key_exists('salt', (array)(state()['run'] ?? []));
			// как кнопка «Повторить шаг» в браузере: один раз после прерванного запроса
			if (++$notJson > 1)
			{
				return [$log, '', $salt, ['не JSON: ' . substr($body, 0, 500)]];
			}
			$log[] = ['retry', substr($body, 0, 100)];
			continue;
		}
		$notJson = 0;
		if ($afterFirst)
		{
			$afterFirst();
			$afterFirst = null;
		}
		$log = array_merge($log, $j['log'] ?? []);
		if (!$j['ok'])
		{
			return [$log, '', $salt, [$j['error']]];
		}
		if (!empty($j['done']))
		{
			$GLOBALS['doneHeaders'] = $GLOBALS['lastHeaders'];
			return [$log, $j['report_html'], $salt, []];
		}
	}
	return [$log, '', $salt, ['не закончилось за 400 шагов']];
}

function errors_in(array $log): array
{
	return array_values(array_filter($log, static function ($l) {
		return $l[0] === 'error';
	}));
}

try
{
	// ============================================================ отказы защиты
	[$st] = http('GET', '/devrestore.php', [], 'evil.example.com');
	ok('чужой хост → 403', $st === 403, "статус $st");
	rename($home, "$home-off");
	[$st, $body] = http('GET', '/devrestore.php');
	ok('без папки профилей запуск запрещён и папка не создаётся', $st === 403 && strpos($body, 'не разрешён') !== false && !is_dir($home), "статус $st");
	rename("$home-off", $home);
	mkdir($sharedHome);
	[$st] = http('GET', '/devrestore.php');
	ok('DEVRESTORE_HOME: общая папка есть — берётся она, а не .devrestore рядом с корнем', $st === 200 && is_file("$sharedHome/config.php"), "статус $st");
	rrmdir($sharedHome);
	[$st] = http('GET', '/devrestore.php', [], null, "X-Forwarded-For: 8.8.8.8\r\n");
	ok('за прокси (X-Forwarded-For) без ключа → 403', $st === 403, "статус $st");
	[$st, $body] = http('GET', '/devrestore.php');
	ok('главная открывается', $st === 200 && strpos($body, 'Восстановить свежую копию') !== false, "статус $st");
	$csrf = csrf($body);
	ok('в форме есть csrf', $csrf !== '');
	[$st] = http('POST', '/devrestore.php', ['action' => 'prepare', 'confirm_domain' => $host, 'csrf' => 'bad']);
	ok('подготовка без верного csrf → 400', $st === 400, "статус $st");
	[$st] = http('POST', '/devrestore.php', ['action' => 'prepare', 'confirm_domain' => 'other.local', 'csrf' => $csrf]);
	ok('подготовка без подтверждения домена → 400', $st === 400, "статус $st");
	ok('после отказов архивы на месте', is_file("$docroot/old.tar.gz") && is_file("$docroot/new.tar.gz"));
	put("$docroot/restore.php", fake_restore(false));
	[$st] = http('POST', '/devrestore.php', ['action' => 'prepare', 'confirm_domain' => $host, 'csrf' => $csrf, 'keep_archive' => 'new.tar.gz',
		'delete_other_archives' => '1', 'delete_dumps' => '1', 'restore_source' => 'existing']);
	clearstatcache();
	[, $body] = http('GET', '/devrestore.php');
	ok('главная: restore.php без вставок — красное предупреждение', strpos($body, 'restore.php в корне без вставок devrestore') !== false);
	put("$docroot/restore.php", fake_restore(true, false));
	[$st, $body] = http('POST', '/devrestore.php', ['action' => 'prepare', 'confirm_domain' => $host, 'csrf' => $csrf, 'keep_archive' => 'new.tar.gz',
		'delete_other_archives' => '1', 'delete_dumps' => '1', 'restore_source' => 'existing']);
	ok('restore.php без самообновления (места третьей вставки) → отказ, ничего не удалено', $st === 500 && strpos($body, 'самообновление') !== false && is_file("$docroot/old.tar.gz"), "статус $st");
	put("$docroot/restore.php", fake_restore(false));
	ok('restore.php без мест для вставок → отказ, ничего не удалено', $st === 500 && is_file("$docroot/old.tar.gz") && glob("$docroot/bitrix/backup/*.sql") && strpos(file_get_contents("$docroot/restore.php"), 'DEVRESTORE') === false, "статус $st");
	put("$docroot/restore.php", fake_restore(true));

	// ============================================================ фаза A
	[$st, , $loc] = http('POST', '/devrestore.php', ['action' => 'prepare', 'confirm_domain' => $host, 'csrf' => $csrf, 'keep_archive' => 'new.tar.gz',
		'delete_other_archives' => '1', 'delete_dumps' => '1', 'restore_source' => 'existing']);
	ok('подготовка → редирект в restore.php', $st === 302 && $loc === '/restore.php?lang=ru', "статус $st, $loc");
	ok('старый архив удалён, свежий с частями оставлен', !is_file("$docroot/old.tar.gz") && is_file("$docroot/new.tar.gz") && is_file("$docroot/new.tar.gz.1"));
	ok('старые дампы удалены', !glob("$docroot/bitrix/backup/*.sql"));
	$restoreSrc = file_get_contents("$docroot/restore.php");
	ok('restore.php: самообновление выключено вставкой', strpos($restoreSrc, 'if (false /* DEVRESTORE-PATCH-SELFUPDATE') !== false);
	// повторная подготовка лежащего (уже пропатченного) файла: вставки не задваиваются
	[$st] = http('POST', '/devrestore.php', ['action' => 'prepare', 'confirm_domain' => $host, 'csrf' => $csrf, 'keep_archive' => 'new.tar.gz', 'restore_source' => 'existing']);
	$again = file_get_contents("$docroot/restore.php");
	ok('повторная подготовка: вставки не задвоились', $st === 302 && substr_count($again, 'DEVRESTORE-PATCH') === 7 && substr_count($restoreSrc, 'DEVRESTORE-PATCH') === 7,
		substr_count($restoreSrc, 'DEVRESTORE-PATCH') . ' → ' . substr_count($again, 'DEVRESTORE-PATCH'));
	// первое открытие restore.php браузером (GET без Step): самообновление не должно стереть вставки
	[, $rbody] = http('GET', '/restore.php?lang=ru');
	clearstatcache();
	$restoreSrc = file_get_contents("$docroot/restore.php");
	ok('открытие restore.php: файл не перезаписал себя, вставки на месте', strpos($rbody, 'UPDATE_SUCCESS') === false && strpos($restoreSrc, 'DEVRESTORE-PATCH-BEGIN') !== false, substr($rbody, 0, 100));
	[, $body] = http('GET', '/devrestore.php');
	ok('главная: у пропатченного restore.php предупреждения нет', strpos($body, 'restore.php в корне без вставок devrestore') === false);
	$st0 = state();
	ok('restore.php пропатчен токеном', strpos($restoreSrc, 'DEVRESTORE-PATCH-BEGIN') !== false && strpos($restoreSrc, $st0['token']) !== false);
	ok('ключ delight.mcp сохранён до восстановления', ($st0['restore']['dev_modules']['delight.mcp']['jwt_secret_key'] ?? '') === 'LOCALSECRET');

	ok('restore.php: шаг 3 отдаёт дамп devrestore (клиент mysql найден рядом с сервером)', strpos($restoreSrc, '?phase=import&token=' . $st0['token']) !== false
		&& ($st0['restore']['import']['mode'] ?? '') === 'mysql' && !empty($st0['restore']['import']['skip']), json_encode($st0['restore']['import'] ?? null, JSON_UNESCAPED_UNICODE));

	// «Восстановление»: restore.php распаковал архив — в /bitrix/backup многотомный дамп прода; база ещё локальная.
	// Дамп пересоздаёт свои таблицы, чужая zz_legacy_contacts остаётся.
	seed_prod();
	$dumped = make_dump("$docroot/bitrix/backup/new", 3);
	foreach ($dumped as $t)
	{
		q("DROP TABLE `$t`");
	}
	ok('тестовый дамп: три тома и _after_connect.sql', is_file("$docroot/bitrix/backup/new.sql.2") && is_file("$docroot/bitrix/backup/new_after_connect.sql") && count($dumped) > 40, count($dumped) . ' таблиц');
	[$st, , $loc] = http('GET', '/restore.php?Step=3&arc_name=new.tar.gz&dump_name=new.sql&lang=ru');
	ok('шаг 3 restore.php передаёт дамп в devrestore', $st === 302 && strpos($loc, '/devrestore.php?phase=import&token=' . $st0['token']) === 0, "статус $st, $loc");
	$statusUrl = '/devrestore.php?phase=import_status&token=' . $st0['token'];
	$pollImport = static function () use ($statusUrl): ?array {
		$imp = null;
		for ($i = 0; $i < 600; $i++)
		{
			[, $raw] = http('GET', $statusUrl, [], null, "X-Requested-With: fetch\r\n");
			$imp = json_decode($raw, true);
			if (!in_array($imp['status'] ?? '', ['starting', 'running'], true))
			{
				break;
			}
			usleep(200000);
		}
		return $imp;
	};
	$neutralized = static function (): bool {
		return (int)v("SELECT COUNT(*) FROM b_agent WHERE ACTIVE='Y'") === 0 && (int)v('SELECT COUNT(*) FROM b_event') === 0
			&& v('SELECT EVENT_HANDLER FROM b_rest_event') === 'https://blocked.devrestore.invalid/' && v("SELECT VALUE FROM b_option WHERE MODULE_ID='main' AND NAME='check_agents'") === 'N'
			&& v('SELECT ACTIVE FROM b_mail_mailbox') === 'N';
	};
	$early = static function (?array $job): bool {
		$e = (array)($job['early'] ?? []);
		ksort($e);
		return array_keys($e) === ['b_agent', 'b_event', 'b_mail_mailbox', 'b_option', 'b_rest_event'] && !in_array(false, $e, true);
	};

	// сначала дамп обрывается ошибкой в конце: в базе уже прод — агенты и почта отключены, есть «заново»
	$bad = "INSERT INTO `zz_no_such_table` VALUES (1);\n";
	file_put_contents("$docroot/bitrix/backup/new.sql.2", $bad, FILE_APPEND);
	[$st, $body] = http('GET', $loc);
	$imp = $pollImport();
	$jobErr = json_decode((string)@file_get_contents(glob("$home/state/*-import.job")[0] ?? ''), true);
	ok('импорт с ошибкой: статус «ошибка», причина от клиента mysql', ($imp['status'] ?? '') === 'error' && strpos($imp['error'] ?? '', 'клиент mysql завершился с ошибкой') === 0
		&& strpos($imp['error'] ?? '', 'zz_no_such_table') !== false, json_encode($imp, JSON_UNESCAPED_UNICODE));
	ok('импорт с ошибкой: агенты, очередь писем, вебхуки и почтовые ящики отключены', $neutralized() && ($jobErr['neutral'] ?? 0) === 5
		&& strpos((string)@file_get_contents(glob("$home/logs/*.txt")[0] ?? ''), 'Импорт: после ошибки отключены агенты') !== false, json_encode($jobErr['neutral'] ?? null));
	ok('импорт с ошибкой: таблицы агентов, опций, писем, вебхуков и ящиков отключены по ходу импорта', $early($jobErr), json_encode($jobErr['early'] ?? null));
	[$st, $body] = http('GET', $loc);
	ok('импорт с ошибкой: страница показывает ошибку и «Начать импорт заново»', $st === 200 && strpos($body, 'Начать импорт заново') !== false);
	file_put_contents("$docroot/bitrix/backup/new.sql.2", substr(file_get_contents("$docroot/bitrix/backup/new.sql.2"), 0, -strlen($bad)));
	$loc = '/devrestore.php?' . http_build_query(['phase' => 'import', 'token' => $st0['token'], 'retry' => 1, 'dump_name' => 'new.sql', 'arc_name' => 'new.tar.gz']);

	[$st, $body] = http('GET', $loc);
	$job1 = json_decode((string)@file_get_contents(glob("$home/state/*-import.job")[0] ?? ''), true);
	ok('«заново»: страница импорта запустила фоновый процесс', $st === 200 && strpos($body, 'Импорт дампа клиентом mysql') !== false && in_array($job1['status'] ?? '', ['starting', 'running', 'done'], true)
		&& ($job1['started'] ?? 0) >= ($jobErr['started'] ?? PHP_INT_MAX) && empty($job1['error']), "статус $st " . json_encode($job1, JSON_UNESCAPED_UNICODE));
	[$st] = http('GET', $loc);
	$job2 = json_decode((string)@file_get_contents(glob("$home/state/*-import.job")[0] ?? ''), true);
	ok('повторное открытие страницы импорта не запускает второй процесс', in_array($st, [200, 302], true) && ($job2['started'] ?? 0) === ($job1['started'] ?? -1));
	$imp = $pollImport();
	ok('импорт клиентом mysql завершён', ($imp['status'] ?? '') === 'done', json_encode($imp, JSON_UNESCAPED_UNICODE) . ' ' . @file_get_contents(glob("$home/state/*-import.log")[0] ?? ''));
	// агенты и почта — тем же процессом сразу после импорта, до перехода к обработке
	ok('сразу после импорта агенты, очередь писем, вебхуки и почтовые ящики отключены', $neutralized());
	ok('после импорта в базе прод: server_name, 3003 записи таймлайна, кириллица в таблице cp1251', v("SELECT VALUE FROM b_option WHERE MODULE_ID='main' AND NAME='server_name'") === $prodHost
		&& (int)v('SELECT COUNT(*) FROM b_crm_timeline') === 3003 && v('SELECT NAME FROM b_crm_contact WHERE ID = 1000000001') === 'Мария' && v('SELECT COUNT(*) FROM zz_legacy_contacts') > 0);
	$jobDone = json_decode((string)file_get_contents(glob("$home/state/*-import.job")[0]), true);
	ok('данные очищаемых таблиц не загружены, структура есть', (int)v('SELECT COUNT(*) FROM b_search_content') === 0 && isset($jobDone['skipped']['b_search_content'])
		&& !isset($jobDone['skipped']['b_crm_timeline']) && $jobDone['parts'] === 3 && $jobDone['pos'] === $jobDone['total'], json_encode($jobDone['skipped'] ?? null));
	ok('файл с паролем для клиента mysql удалён', !glob("$home/state/*-import.cnf"));
	// задание импорта не завершено — обработка не начинается, возвращает на импорт
	$jobFile = glob("$home/state/*-import.job")[0];
	file_put_contents($jobFile, json_encode(['status' => 'error', 'error' => 'тест'] + $jobDone));
	[$st, , $loc2] = http('GET', '/devrestore.php?phase=after&token=' . $st0['token'] . '&arc_name=new.tar.gz&dump_name=new.sql');
	[, $body] = http('GET', '/devrestore.php?phase=import&token=' . $st0['token']);
	file_put_contents($jobFile, json_encode($jobDone));
	ok('импорт: таблицы агентов, опций, писем, вебхуков и ящиков отключены по ходу импорта', $early($jobDone), json_encode($jobDone['early'] ?? null));
	// процесс убит (вместе с php-cgi): задание «идёт», блокировки нет — страница сама отключает агенты и почту
	q("UPDATE b_agent SET ACTIVE = 'Y'");
	file_put_contents($jobFile, json_encode(['status' => 'running'] + $jobDone));
	[, $raw] = http('GET', $statusUrl, [], null, "X-Requested-With: fetch\r\n");
	$died = json_decode((string)file_get_contents($jobFile), true);
	file_put_contents($jobFile, json_encode($jobDone));
	ok('процесс импорта убит: статус «прервался», агенты отключены страницей', (json_decode($raw, true)['status'] ?? '') === 'died' && (int)v("SELECT COUNT(*) FROM b_agent WHERE ACTIVE='Y'") === 0
		&& ($died['neutral_died'] ?? 0) === 5, $raw);
	ok('обработка до конца импорта не начинается: возврат на импорт, там ошибка и «заново»', $st === 302 && strpos($loc2, 'phase=import&token=' . $st0['token']) !== false
		&& !empty(state()['token']) && strpos($body, 'Начать импорт заново') !== false, "статус $st, $loc2");
	// возвращаем, чтобы полный прогон devrestore проверялся по-честному
	q("UPDATE b_agent SET ACTIVE = 'Y'");
	q("UPDATE b_option SET VALUE = 'Y' WHERE MODULE_ID = 'main' AND NAME = 'check_agents'");
	q("INSERT INTO b_event (EVENT_NAME, C_FIELDS) VALUES ('NEW_USER', 'x')");
	q("UPDATE b_rest_event SET EVENT_HANDLER = 'https://hooks.acme.ru/handler?token=abc'");

	[$st, , $loc] = http('GET', '/devrestore.php?phase=import&token=' . $st0['token']);
	ok('импорт готов — страница импорта ведёт к обработке', $st === 302 && strpos($loc, '/devrestore.php?phase=after&token=' . $st0['token']) === 0 && $loc === ($imp['url'] ?? ''), "статус $st, $loc");
	[$st, $body] = http('GET', $loc);
	ok('новый клиент: показана форма настроек', $st === 200 && strpos($body, 'впервые') !== false && strpos($body, $prodHost) !== false, "статус $st");
	$allGroups = groups($body);
	ok('в форме есть группы правил', count($allGroups) >= 20, count($allGroups) . ' групп');
	[$st] = http('POST', '/devrestore.php', ['action' => 'start', 'csrf' => csrf($body), 'token' => 'wrong', 'groups' => $allGroups]);
	ok('запуск с чужим токеном и без домена → 400', $st === 400, "статус $st");

	// ============================================================ фаза B
	[$st, $body] = http('POST', '/devrestore.php', ['action' => 'start', 'csrf' => csrf($body), 'token' => $st0['token'], 'groups' => $allGroups,
		'scripts' => ['acme.php'], 'text_mode' => 'strict', 'leak_scan' => '1', 'cleanup' => '1']);
	ok('запуск обработки', $st === 200 && strpos($body, 'window.DR') !== false, "статус $st " . substr(strip_tags($body), 0, 300));
	[$log, $report, $salt, $err] = run_steps($body);
	ok('обработка дошла до конца', !$err, implode('; ', $err));
	ok('в журнале нет ошибок', !errors_in($log), json_encode(errors_in($log), JSON_UNESCAPED_UNICODE));
	ok('соль была во время обработки', strlen($salt) === 32);

	// --- отключение внешних действий
	ok('агенты выключены', (int)v("SELECT COUNT(*) FROM b_agent WHERE ACTIVE='Y'") === 0);
	ok('check_agents = N', v("SELECT VALUE FROM b_option WHERE MODULE_ID='main' AND NAME='check_agents'") === 'N');
	ok('очередь писем b_event пуста', (int)v('SELECT COUNT(*) FROM b_event') === 0);
	ok('заглушка custom_mail в dbconn.php', substr_count(file_get_contents("$docroot/bitrix/php_interface/dbconn.php"), 'DEVRESTORE-MAIL-STUB-BEGIN') === 1);
	exec(escapeshellarg($phpBin) . ' -l ' . escapeshellarg("$docroot/bitrix/php_interface/dbconn.php"), $o, $code);
	ok('dbconn.php после заглушки валиден', $code === 0, implode(' ', $o));
	$settings = settings_now("$docroot/bitrix/.settings.php");
	ok('SMTP в .settings.php выключен, подключение цело', $settings['smtp']['value']['enabled'] === false && $settings['connections']['value']['default']['database'] === $dbName);
	ok('вебхук заблокирован', v('SELECT EVENT_HANDLER FROM b_rest_event') === 'https://blocked.devrestore.invalid/');
	ok('почтовый ящик выключен, пароль стёрт', v("SELECT CONCAT(ACTIVE, '|', PASSWORD) FROM b_mail_mailbox") === 'N|');
	ok('опции-секреты стёрты', v("SELECT VALUE FROM b_option WHERE NAME='smtp_password'") === '' && v("SELECT VALUE FROM b_option_site WHERE NAME='api_token'") === '');
	ok('обычные опции не тронуты', v("SELECT VALUE FROM b_option WHERE NAME='new_user_password_length'") === '6');
	$keepRow = v("SELECT CONCAT(o.VALUE, '|', s.VALUE, '|', t.VALUE, '|', w.VALUE) FROM b_option o, b_option_site s, b_option t, b_option w WHERE o.MODULE_ID = 'acme.api' AND o.NAME = 'API_KEY'"
		. " AND s.MODULE_ID = 'acme.api' AND s.NAME = 'api_key' AND t.MODULE_ID = 'acme.api' AND t.NAME = 'backfill_token' AND w.MODULE_ID = 'acme.api' AND w.NAME = 'secret_key'");
	ok('options_keep: опция и её копия по сайту уцелели, соседние секреты стёрты, в отчёте только имена', $keepRow === 'KeepMe-12345|SiteKeep-1234||'
		&& strpos($report, 'оставлено по скрипту клиента: acme.api/API_KEY') !== false && strpos($report, 'KeepMe') === false && strpos($report, 'не найдено среди секретов') === false,
		$keepRow . ' ' . (preg_match('/очищено опций[^<]*/u', $report, $mm) ? $mm[0] : 'строки «Секреты» нет'));
	$bp = q("SELECT CONCAT_WS('|', t.ACTION_NAME, t.ACTION_TITLE, t.ACTION_NOTE, k.NAME, k.DESCRIPTION, k.DOCUMENT_NAME) FROM b_bp_tracking t, b_bp_task k")->fetch_all();
	$bpText = (string)($bp[0][0] ?? '');
	ok('журнал и задания БП: строки на месте, ФИО и телефона нет, заголовок действия не тронут', count($bp) === 1
		&& strpos($bpText, 'A12345_approve|Согласование договора|') === 0 && !preg_match('/Петров Иван Сергеевич|Петрову Ивану|Сидоровой Марии|звонок Сидоровой|123-45-67/u', $bpText), $bpText);
	ok('коннекторы ОЛ отвязаны', (int)v("SELECT COUNT(*) FROM b_option WHERE MODULE_ID='imconnector'") === 0);
	ok('секретные колонки стёрты, устройства push очищены', v('SELECT PASSWORD FROM b_rest_ap') === '' && (int)v('SELECT COUNT(*) FROM b_pull_push') === 0);

	// --- пользователи
	$origEmails = "'ivan.petrov@acme.ru','maria.sidorova@acme.ru','nikita@acme.ru'";
	ok('исходных e-mail пользователей нет', (int)v("SELECT COUNT(*) FROM b_user WHERE EMAIL IN ($origEmails)") === 0);
	ok('исходных ФИО нет', (int)v("SELECT COUNT(*) FROM b_user WHERE CONCAT_WS(' ', NAME, LAST_NAME, SECOND_NAME) IN ('Иван Петров Сергеевич', 'Мария Сидорова Петровна')") === 0);
	$u2 = q('SELECT * FROM b_user WHERE ID = 2')->fetch_assoc();
	ok('женщина осталась женщиной (имя, фамилия, отчество)', (in_array(mb_substr($u2['NAME'], -1), ['а', 'я'], true) || $u2['NAME'] === 'Любовь') && mb_substr($u2['LAST_NAME'], -1) === 'а' && mb_substr($u2['SECOND_NAME'], -2) === 'на', json_encode($u2, JSON_UNESCAPED_UNICODE));
	$u3 = q('SELECT NAME, LAST_NAME FROM b_user WHERE ID = 3')->fetch_assoc();
	ok('Никита — мужчина', mb_substr($u3['LAST_NAME'], -1) !== 'а', json_encode($u3, JSON_UNESCAPED_UNICODE));
	ok('логины user<ID>, dev-админ = admin', col('SELECT LOGIN FROM b_user ORDER BY ID') === ['admin', 'user2', 'user3', 'user4', 'user5']);
	ok('пароли не боевые', (int)v("SELECT COUNT(*) FROM b_user WHERE PASSWORD LIKE 'hash%'") === 0);
	ok('NULL и пустые остаются как были', q('SELECT EMAIL, NAME, LAST_NAME, PERSONAL_PHONE FROM b_user WHERE ID = 4')->fetch_row() === [null, null, '', '']);
	ok('день рождения и заметки стёрты', v('SELECT PERSONAL_BIRTHDAY FROM b_user WHERE ID = 1') === null && strpos((string)v('SELECT PERSONAL_NOTES FROM b_user WHERE ID = 1'), '[скрыто') === 0);
	ok('телефоны вида +7000…', (bool)preg_match('/^\+7000\d{7}$/', (string)v('SELECT PERSONAL_PHONE FROM b_user WHERE ID = 1')));
	ok('imconnector XML_ID обезличен', strpos((string)v('SELECT XML_ID FROM b_user WHERE ID = 5'), 'im_') === 0);

	// --- CRM
	$c1 = q('SELECT * FROM b_crm_contact WHERE ID = 1000000001')->fetch_assoc();
	ok('cp1251-контакт: кириллица читается, имя заменено', [$c1['NAME'], $c1['LAST_NAME'], $c1['SECOND_NAME']] !== ['Мария', 'Сидорова', 'Петровна'] && preg_match('/^[А-ЯЁ][а-яё]+$/u', $c1['NAME']) && strpos($c1['FULL_NAME'], $c1['NAME']) === 0 && strpos($c1['NAME'], '?') === false, json_encode($c1, JSON_UNESCAPED_UNICODE));
	ok('FULL_NAME собран из новых имени и фамилии', $c1['FULL_NAME'] === $c1['NAME'] . ' ' . $c1['LAST_NAME']);
	ok('strict: должность заменена целиком (в ней бывают имена и контакты)', strpos((string)$c1['POST'], '[скрыто') === 0, (string)$c1['POST']);
	ok('пустой контакт остался пустым', q('SELECT NAME, LAST_NAME, SECOND_NAME, ADDRESS FROM b_crm_contact WHERE ID = 1000099999')->fetch_row() === ['', '', null, '']);
	$phones = col("SELECT VALUE FROM b_crm_field_multi WHERE TYPE_ID = 'PHONE' ORDER BY ID");
	ok('один телефон в разных записях → один фейк', count($phones) === 2 && $phones[0] === $phones[1] && strpos($phones[0], '+7000') === 0, implode(' / ', $phones));
	ok('совпадает и с телефоном пользователя', $phones[0] === v('SELECT PERSONAL_PHONE FROM b_user WHERE ID = 1'));
	ok('e-mail в средствах связи → @example.test', (bool)preg_match('/^u[0-9a-f]{10}@example\.test$/', (string)v("SELECT VALUE FROM b_crm_field_multi WHERE TYPE_ID = 'EMAIL'")));
	ok('сайт и мессенджер заменены', v("SELECT VALUE FROM b_crm_field_multi WHERE TYPE_ID = 'WEB'") === 'https://example.test/' && strpos((string)v("SELECT VALUE FROM b_crm_field_multi WHERE TYPE_ID = 'IM'"), 'im_') === 0);
	ok('заголовок лида без телефона', v('SELECT TITLE FROM b_crm_lead') === 'Лид #7');
	ok('сделка: заголовок и комментарий заменены, сумма цела', q('SELECT TITLE, OPPORTUNITY FROM b_crm_deal')->fetch_row() === ['Сделка #9', '150000.00'] && strpos((string)v('SELECT COMMENTS FROM b_crm_deal'), 'ivan@') === false);
	ok('адрес без числового PK заменён', strpos((string)v('SELECT ADDRESS_1 FROM b_crm_addr WHERE ENTITY_ID = 1000000001'), 'Тестовск') !== false && v('SELECT CITY FROM b_crm_addr WHERE ENTITY_ID = 1000000001') === 'Тверь');
	$rq4 = (string)v('SELECT RQ_COMPANY_NAME FROM b_crm_requisite WHERE ID = 4');
	ok('реквизиты: ИНН-12 без имени в названии — всё равно ИП-двойник', strpos($rq4, 'ИП ') === 0 && strpos($rq4, 'Заря') === false, $rq4);
	$inn = col('SELECT RQ_INN FROM b_crm_requisite ORDER BY ID');
	ok('ИНН: 10 цифр (юрлицо) остался, 12 цифр — другие цифры той же длины', $inn[0] === '7701234567' && $inn[1] !== '500100732259' && preg_match('/^\d{12}$/', $inn[1]), implode(' | ', $inn));
	$comp = col('SELECT TITLE FROM b_crm_company ORDER BY ID');
	ok('strict: юрлицо оставлено, компания-человек без реквизита заменена', $comp[0] === 'ООО Ромашка' && strpos($comp[2], 'ООО «Компания ') === 0, implode(' | ', $comp));
	ok('смарт-процесс: заголовок и UF-ФИО', v('SELECT TITLE FROM b_crm_dynamic_items_128') === 'Элемент #1' && v('SELECT UF_CRM_3_CLIENT_FIO FROM b_crm_dynamic_items_128') !== 'Иванов Иван Иванович');
	// v2.6
	ok('смарт-процесс без типа (сирота) очищен вместе с таблицей множественного поля, живой тип не тронут',
		(int)v('SELECT COUNT(*) FROM b_crm_dynamic_items_190') === 0 && (int)v('SELECT COUNT(*) FROM b_crm_dynamic_items_190_uf_crm_9_phones') === 0
		&& (int)v('SELECT COUNT(*) FROM b_crm_dynamic_items_128') === 1 && strpos($report, 'b_crm_dynamic_items_190') !== false);
	$spPhones = col('SELECT VALUE FROM b_crm_dynamic_items_128_uf_crm_3_phones ORDER BY VALUE');
	ok('множественное UF смарт-процесса в своей таблице: телефон заменён, пустое осталось пустым', $spPhones[0] === '' && strpos((string)$spPhones[1], '+7000') === 0, implode(' | ', $spPhones));
	$tok = col('SELECT TOKEN FROM b_mail_user_relations ORDER BY USER_ID');
	ok('токен в PRIMARY KEY: все строки заменены, разными значениями той же длины',
		count($tok) === 3 && count(array_unique($tok)) === 3 && !array_intersect($tok, ['a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6', 'ffeeddccbbaa99887766554433221100', '0123456789abcdef0123456789abcdef'])
		&& array_map('strlen', $tok) === [32, 32, 32] && strpos($report, 'конфликт уникального ключа') === false, implode(' | ', $tok));
	ok('эфемерные токены CalDAV и блокировок диска очищены', (int)v('SELECT COUNT(*) FROM b_dav_tokens') === 0 && (int)v('SELECT COUNT(*) FROM b_disk_object_lock') === 0);
	// --- v2.8: сериализованное и JSON
	$tl1 = @unserialize((string)v('SELECT SETTINGS FROM b_crm_timeline WHERE ID = 1'), ['allowed_classes' => false]);
	ok('ser: телефон внутри сериализованного таймлайна заменён, значение распаковывается', is_array($tl1) && strpos((string)($tl1['PHONE'] ?? ''), '+7000') === 0,
		(string)v('SELECT SETTINGS FROM b_crm_timeline WHERE ID = 1'));
	$bpp = @unserialize((string)v('SELECT PARAMETERS FROM b_bp_task WHERE ID = 1'), ['allowed_classes' => false]);
	ok('ser в strict: в задании БП имя осталось, телефон заменён, ключи и пустой массив на месте', is_array($bpp) && $bpp['USER'] === 'maria'
		&& strpos($bpp['COMMENT'], 'Перезвонить Сидоровой, ') === 0 && strpos($bpp['COMMENT'], '123-45-67') === false && $bpp['LIST'] === [],
		(string)v('SELECT PARAMETERS FROM b_bp_task WHERE ID = 1'));
	$pj = (string)v('SELECT PARAM_JSON FROM b_im_message_param WHERE ID = 1');
	$pjd = json_decode($pj);
	ok('json: телефон заменён, {} и экранирование исходника (' . '\\u, ' . '\\/) сохранены', $pjd instanceof stdClass
		&& strpos($pj, '123-45-67') === false && strpos($pj, '"OPTS":{}') !== false && strpos($pj, '\u0417\u0432') !== false && strpos($pj, '\u0022\u0417') !== false && strpos($pj, '\u0026') !== false
		&& strpos($pj, 'https:\/\/example.com\/a\/b') !== false, $pj);
	ok('битое не тронуто: большое целое в JSON, неверная длина s:N, JSON без изменений',
		v('SELECT PARAM_JSON FROM b_im_message_param WHERE ID = 2') === '{"ID":12345678901234567890,"PHONE":"+79161234567"}'
		&& v('SELECT PARAM_JSON FROM b_im_message_param WHERE ID = 3') === '{"CODE":"lead_12"}'
		&& v('SELECT SETTINGS FROM b_crm_timeline WHERE ID = 3003') === 'a:1:{s:4:"MAIL";s:99:"broken@client.ru";}');
	ok('отчёт называет битые значения', (bool)preg_match('/не распаковались без потерь[^<]*b_crm_timeline\.SETTINGS: 1 \(первая — строка #3003\)[^<]*b_im_message_param\.PARAM_JSON: 1/u', $report)
		|| (bool)preg_match('/не распаковались без потерь[^<]*b_im_message_param\.PARAM_JSON: 1[^<]*b_crm_timeline\.SETTINGS: 1 \(первая — строка #3003\)/u', $report));
	ok('таблица без числового ключа: сериализованное не чищено, отчёт это называет', strpos($report, 'нет числового первичного ключа: b_landing_history.ACTION_PARAMS') !== false
		&& v('SELECT ACTION_PARAMS FROM b_landing_history') === '{"text":"Позвоните +79161234567"}');
	ok('адреса модуля location (b_location_addr_fld) заменены', col('SELECT CONCAT(VALUE, VALUE_NORMALIZED) FROM b_location_addr_fld') === ['——', '——']);
	ok('отчёт называет таблицы правил, которых нет в базе', (bool)preg_match('/Таблиц из правил нет в этой базе \((\d+)\)[^<]*b_calendar_event/u', $report)
		&& !preg_match('/Таблиц из правил нет[^<]*b_location_(addr_fld|address_field)/u', $report));
	ok('dev-админ: логин вырезан обработчиком OnBeforeUserUpdate — записан напрямую, в отчёте логин из базы',
		v('SELECT LOGIN FROM b_user WHERE ID = 1') === 'admin' && strpos($report, 'логин <code>admin</code>') !== false
		&& strpos(implode(' | ', array_column($log, 1)), 'вырезал обработчик OnBeforeUserUpdate') !== false);

	// --- пользовательские поля
	$uts = q('SELECT * FROM b_uts_crm_contact')->fetch_assoc();
	ok('UF-телефон заменён, UF-код не тронут', strpos($uts['UF_CRM_PHONE'], '+7000') === 0 && $uts['UF_CRM_CODE'] === 'VIP-42', json_encode($uts, JSON_UNESCAPED_UNICODE));
	ok('множественное UF: сериализованное очищено, b_utm заменено, чужое поле не тронуто', $uts['UF_CRM_EMAILS'] === '' && strpos((string)v('SELECT VALUE FROM b_utm_crm_contact WHERE FIELD_ID = 3'), '@example.test') !== false && v('SELECT VALUE FROM b_utm_crm_contact WHERE FIELD_ID = 2') === 'not-multiple-field');
	ok('UF-дата рождения обнулена', $uts['UF_CRM_BIRTH'] === null);
	ok('UF Б24 с подписью «Паспорт» → цифры той же длины', (bool)preg_match('/^\d{11}$/', (string)$uts['UF_CRM_1612345678']) && $uts['UF_CRM_1612345678'] !== '45101234560', (string)$uts['UF_CRM_1612345678']);
	ok('UF Б24 без подписи → как текст (strict)', strpos((string)$uts['UF_CRM_1700000000'], '[скрыто') === 0, (string)$uts['UF_CRM_1700000000']);
	$authPhones = col('SELECT PHONE_NUMBER FROM b_user_phone_auth ORDER BY USER_ID');
	ok('уникальные телефоны — от ключа, без конфликтов', $authPhones === ['+70000000001', '+70000000002'], implode(', ', $authPhones));
	ok('push-каналы прода очищены', (int)v('SELECT COUNT(*) FROM b_pull_channel') === 0);
	$settingsNow = settings_now("$docroot/bitrix/.settings.php");
	ok('.settings.php: кеш и push прода убраны', !isset($settingsNow['cache']) && !isset($settingsNow['pull']) && isset($settingsNow['connections']['value']['default']));

	// --- переписка, магазин, файлы
	ok('25 000 сообщений: исходного текста нет', (int)v("SELECT COUNT(*) FROM b_im_message WHERE MESSAGE LIKE 'Сообщение номер%' OR MESSAGE LIKE '%ivan@mail.ru%'") === 0);
	ok('пустые сообщения пустые, NULL остался NULL', (int)v("SELECT COUNT(*) FROM b_im_message WHERE MESSAGE = ''") === count(array_filter(range(1, 25000), static function ($i) { return $i % 97 === 0 && $i % 10 !== 0; })) && (int)v('SELECT COUNT(*) FROM b_im_message WHERE MESSAGE_OUT IS NULL') === count(array_filter(range(1, 25000), static function ($i) { return $i % 3 !== 0; })));
	$props = col('SELECT VALUE FROM b_sale_order_props_value ORDER BY ORDER_PROPS_ID');
	ok('свойства заказа: e-mail, ФИО, текст заменены, местоположение цело', preg_match('/@example\.test$/', $props[0]) && $props[1] !== 'Петров Пётр' && $props[2] === '0000073738' && strpos($props[3], '[скрыто') === 0, implode(' | ', $props));
	ok('имя файла заменено, расширение осталось', (bool)preg_match('/^file_[0-9a-f]{10}\.pdf$/', (string)v('SELECT ORIGINAL_NAME FROM b_file WHERE ID = 1')));
	ok('поисковый индекс очищен', (int)v('SELECT COUNT(*) FROM b_search_content') === 0);

	// --- dev-настройки, ядро, скрипт клиента
	ok('домен сайта s1 → dev-хост, s2 не тронут', v("SELECT SERVER_NAME FROM b_lang WHERE LID='s1'") === $host && col("SELECT DOMAIN FROM b_lang_domain WHERE LID='s1'") === [$host] && v("SELECT SERVER_NAME FROM b_lang WHERE LID='s2'") === 'shop.acme.ru');
	ok('server_name и cookie_name', v("SELECT VALUE FROM b_option WHERE MODULE_ID='main' AND NAME='server_name'") === $host && v("SELECT VALUE FROM b_option WHERE MODULE_ID='main' AND NAME='cookie_name'") === 'BITRIX_SM_ACME_LOCAL');
	$marker = json_decode((string)v("SELECT VALUE FROM b_option WHERE MODULE_ID='devrestore' AND NAME='info'"), true);
	ok('отметка «обезличено» с боевым доменом', ($marker['prod_domain'] ?? '') === $prodHost);
	ok('delight.mcp: модуль стоит, ключ прежний, включён', v("SELECT ID FROM b_module WHERE ID='delight.mcp'") === 'delight.mcp' && v("SELECT VALUE FROM b_option WHERE MODULE_ID='delight.mcp' AND NAME='jwt_secret_key'") === 'LOCALSECRET' && v("SELECT VALUE FROM b_option WHERE MODULE_ID='delight.mcp' AND NAME='enabled'") === 'Y');
	ok('скрипт клиента: SQL и run в ядре', v("SELECT VALUE FROM b_option WHERE MODULE_ID='acme' AND NAME='sql_ran'") === 'Y' && v("SELECT VALUE FROM b_option WHERE MODULE_ID='acme' AND NAME='ran'") === "$prodHost>$host");
	ok('правило из скрипта клиента применено', v('SELECT UF_FIO FROM b_hlbd_clients') !== 'Сидорова Мария');
	ok('dev-админ в группе 1, кеш сброшен, автоматического входа нет (cookie всё равно стираются)', v("SELECT VALUE FROM b_option WHERE MODULE_ID='stub' AND NAME='groups_1'") === '2,1' && v("SELECT VALUE FROM b_option WHERE MODULE_ID='stub' AND NAME='authorized'") === null && v("SELECT VALUE FROM b_option WHERE MODULE_ID='stub' AND NAME='cache_cleared'") === 'Y');
	// v2.7: сессии и cookie прежней копии
	$uniq = (string)v("SELECT VALUE FROM b_option WHERE MODULE_ID='main' AND NAME='server_uniq_id'");
	ok('новый server_uniq_id: подпись сессий прежней копии не совпадёт, ядро их уничтожит', (bool)preg_match('/^[0-9a-f]{32}$/', $uniq) && $uniq !== '0123456789abcdef0123456789abcdef', $uniq);
	$hdr = implode("\n", (array)($GLOBALS['doneHeaders'] ?? []));
	$gone = static function (string $name, string $domain = '') use ($hdr): bool {
		return (bool)preg_match('/^Set-Cookie: ' . preg_quote($name, '/') . '=deleted; Expires=Thu, 01 Jan 1970[^\n]*Path=\/' . ($domain !== '' ? '; Domain=' . preg_quote($domain, '/') : '') . '$/mi', $hdr);
	};
	ok('итоговый ответ стирает cookie прежней копии: сессия и вход Битрикс, без домена и на домен', $gone('PHPSESSID') && $gone('PHPSESSID', $host)
		&& $gone('BITRIX_SM_ACME_LOCAL_UIDH') && $gone('BITRIX_SM_ACME_LOCAL_UIDH', $host) && $gone('BITRIX_SM_LOGIN'), $hdr);
	ok('итоговый ответ: ключ devrestore не стёрт, Clear-Site-Data по http не шлётся', stripos($hdr, 'Set-Cookie: dr_key=') === false && stripos($hdr, 'Clear-Site-Data') === false, $hdr);
	ok('отчёт: кнопка «Войти на портал» и строка про http и https', strpos($report, 'href="/auth/">Войти на портал') !== false && strpos($report, 'и по http, и по https') !== false
		&& strpos($report, 'вы уже авторизованы') === false);
	$disk = v("SELECT GROUP_CONCAT(CONCAT(NAME, '=', VALUE) ORDER BY NAME SEPARATOR ',') FROM b_option WHERE MODULE_ID = 'disk'");
	ok('Диск: ключи облачного редактора стёрты — просмотр переключён на встроенный, в отчёте строка', $disk === 'default_viewer_service=bitrix,default_viewer_service_boards=,disk_boards_b24_secretKey=,disk_onlyoffice_b24_secretKey='
		&& strpos($report, 'просмотр документов Диска переключён на встроенный') !== false, (string)$disk);
	ok('пароль dev-админа показан в отчёте', (bool)preg_match('/пароль <code>Dev-[0-9a-f]{8}-[0-9A-F]{4}!<\/code>/u', $report));

	// --- уборка
	clearstatcache();
	ok('кеш удалён', !is_dir("$docroot/bitrix/managed_cache") && !is_dir("$docroot/bitrix/cache"));
	ok('архив, его части и restore.php удалены', !is_file("$docroot/new.tar.gz") && !is_file("$docroot/new.tar.gz.1") && !is_file("$docroot/restore.php"));
	ok('devrestore.php на локалке не удалён', is_file("$docroot/devrestore.php"));

	// --- проверка утечек
	ok('утечка в чужой таблице найдена', strpos($report, 'zz_legacy_contacts.email') !== false);
	ok('отчёт: импорт клиентом mysql, пропущенные данные, этапы и самые долгие шаги', (bool)preg_match('/<th>Импорт<\/th><td>клиент mysql: [^<]*\(3 тома\); при импорте пропущены данные \d+ таблиц/u', $report)
		&& strpos($report, '<th>Обезличивание</th>') !== false && strpos($report, '<th>Проверка на утечки</th>') !== false && strpos($report, '<th>Самые долгие шаги</th>') !== false,
		preg_match('/<th>Импорт<\/th><td>[^<]*/u', $report, $mm) ? $mm[0] : 'строки «Импорт» нет');
	ok('быстрая проверка: колонки, обработанные правилами, пропущены, последние 20000 строк', (bool)preg_match('/проверено колонок: \d+, с находками: \d+ \(быстрая: последние 20000 строк; колонок, обработанных правилами, пропущено: [1-9]/u', $report));
	ok('утечка в битом сериализованном таймлайне найдена', strpos($report, 'b_crm_timeline.SETTINGS') !== false);
	ok('пример утечки замаскирован', strpos($report, 'legacy@client.ru') === false && strpos($report, 'l***@client.ru') !== false);
	ok('обезличенные таблицы утечек не дали', !preg_match('/b_(user|crm_contact|crm_field_multi|im_message)\.[A-Z_]+<\/td>/', $report));
	ok('11 цифр внутри hex-ID — не телефон (границы регулярки и колонки *_ID)', !preg_match('/b_bp_(tracking|task)\.(WORKFLOW_ID|ACTIVITY)/', $report));

	// --- соль и профиль
	$stateRaw = file_get_contents(glob("$home/state/*.json")[0]);
	$logsRaw = implode('', array_map('file_get_contents', glob("$home/logs/*.txt")));
	ok('соли нет ни в состоянии, ни в журнале', $salt !== '' && strpos($stateRaw, $salt) === false && strpos($logsRaw, $salt) === false);
	$ps = ps_salt($salt);
	ok('strict: соли нет в performance_schema' . ($ps === null ? ' (недоступна — пропуск)' : ''), $ps === null || $ps === 0, (string)$ps);
	preg_match('/пароль <code>(Dev-[^<]+)<\/code>/u', $report, $pm);
	ok('пароль dev-админа не сохранён в состоянии и журнале', !empty($pm[1]) && strpos($stateRaw, $pm[1]) === false && strpos($logsRaw, $pm[1]) === false);
	ok('в отчёте только само совпадение, без текста вокруг', strpos($report, 's:5:') === false && strpos($report, '***67') !== false);
	$profiles = json_decode(file_get_contents("$home/profiles.json"), true);
	ok('профиль клиента запомнен', ($profiles[$prodHost]['scripts'] ?? null) === ['acme.php']);

	// ============================================================ повторный прогон (идемпотентность)
	[, $body] = http('GET', '/devrestore.php');
	ok('главная видит обезличенную базу', strpos($body, 'База обезличена') !== false);
	put("$home/scripts/zz-bad-rule.php", "<?php\nreturn ['title' => 'Правило с ошибкой', 'anonymize' => [['table' => 'b_hlbd_clients', 'where' => 't.`NOPE` = 1', 'set' => ['UF_FIO' => 'full_name']]]];\n");
	[$st, $body] = http('POST', '/devrestore.php', ['action' => 'start', 'csrf' => csrf($body), 'confirm_domain' => $host, 'groups' => $allGroups,
		'scripts' => ['acme.php', 'demo-module.php', 'zz-bad-rule.php', 'zz-die.php'], 'text_mode' => 'strict', 'leak_scan' => '1']);
	$GLOBALS['saltOnBreak'] = null;
	[$log, $report, , $err] = run_steps($body);
	ok('шаг ядра оборвался после отметки — соли в состоянии уже нет', $GLOBALS['saltOnBreak'] === false, var_export($GLOBALS['saltOnBreak'], true));
	$errText = implode("\n", array_column(errors_in($log), 1));
	ok('повтор с ошибками дошёл до конца, в т.ч. после die() в скрипте клиента', !$err && strpos($report, 'Отчёт') !== false, implode('; ', $err));
	ok('ошибки видны: правило, модуль клиента, прерванный шаг ядра, неполное обезличивание', strpos($errText, 'NOPE') !== false && strpos($errText, 'demo.module') !== false
		&& strpos($errText, 'Шаг ядра прерван') !== false && strpos($errText, 'Обезличивание прошло с ошибками') !== false, $errText);
	ok('после die() дальше прошла проверка утечек', strpos($report, 'zz_legacy_contacts') !== false);
	[, $body] = http('GET', '/devrestore.php');
	ok('главная показывает неполное обезличивание красным', strpos($body, 'С ОШИБКАМИ') !== false);
	unlink("$home/scripts/zz-bad-rule.php");
	$pf = json_decode(file_get_contents("$home/profiles.json"), true);
	$pf[$prodHost]['scripts'] = ['acme.php', 'demo-module.php'];
	file_put_contents("$home/profiles.json", json_encode($pf));
	ok('повтор: заглушка почты не задвоилась', substr_count(file_get_contents("$docroot/bitrix/php_interface/dbconn.php"), 'DEVRESTORE-MAIL-STUB-BEGIN') === 1);
	ok('повтор: логины те же', col('SELECT LOGIN FROM b_user ORDER BY ID') === ['admin', 'user2', 'user3', 'user4', 'user5']);

	// ============================================================ известный клиент: автозапуск после restore.php
	// клиента mysql нет (путь в config.php не запускается) — дамп грузит сам restore.php, как раньше
	build_site();
	$cfgFile = "$home/config.php";
	$cfgOrig = file_get_contents($cfgFile);
	file_put_contents($cfgFile, str_replace("'mysql_bin' => '',", "'mysql_bin' => " . var_export("$work/no-such/mysql.exe", true) . ',', $cfgOrig, $n));
	ok('config.php: mysql_bin задан', $n === 1);
	[, $body] = http('GET', '/devrestore.php');
	ok('главная: без клиента mysql — предупреждение, что дамп загрузит restore.php', strpos($body, 'Дамп загрузит restore.php — это медленнее') !== false && strpos($body, 'не запускается') !== false);
	http('POST', '/devrestore.php', ['action' => 'prepare', 'confirm_domain' => $host, 'csrf' => csrf($body), 'keep_archive' => 'new.tar.gz', 'delete_other_archives' => '1', 'delete_dumps' => '1', 'restore_source' => 'existing']);
	file_put_contents($cfgFile, $cfgOrig);
	$restoreSrc = file_get_contents("$docroot/restore.php");
	ok('без клиента mysql: restore.php без передачи дампа, импорт — штатный', strpos($restoreSrc, 'phase=import') === false && substr_count($restoreSrc, 'DEVRESTORE-PATCH') === 5
		&& (state()['restore']['import']['mode'] ?? '') === 'restore');
	seed_prod();
	[$st, $body] = http('GET', '/restore.php?Step=3');
	ok('шаг 3 restore.php сразу отключает агенты, очередь писем и вебхуки', $st === 200 && (int)v("SELECT COUNT(*) FROM b_agent WHERE ACTIVE='Y'") === 0 && (int)v('SELECT COUNT(*) FROM b_event') === 0
		&& v('SELECT EVENT_HANDLER FROM b_rest_event') === 'https://blocked.devrestore.invalid/' && v("SELECT VALUE FROM b_option WHERE MODULE_ID='main' AND NAME='check_agents'") === 'N', "статус $st " . substr($body, 0, 200));
	[$st, , $loc] = http('GET', '/restore.php?Step=5&arc_name=new.tar.gz&dump_name=new.sql');
	ok('шаг 5 restore.php возвращает в devrestore', $st === 302 && strpos($loc, '/devrestore.php?phase=after&token=' . state()['token']) === 0, "статус $st, $loc");
	[$st, $body] = http('GET', $loc);
	ok('известный клиент: обработка стартует сразу', $st === 200 && strpos($body, 'window.DR') !== false, substr(strip_tags($body), 0, 200));
	[$log, $report, , $err] = run_steps($body);
	$errs = errors_in($log);
	ok('автозапуск дошёл до конца, ошибка только у модуля клиента из профиля', !$err && count($errs) === 1 && strpos($errs[0][1], 'demo.module') !== false, implode('; ', $err) . json_encode(errors_in($log), JSON_UNESCAPED_UNICODE));
	ok('автозапуск: скрипт клиента из профиля выполнен', v("SELECT VALUE FROM b_option WHERE MODULE_ID='acme' AND NAME='ran'") === "$prodHost>$host");
	ok('отчёт: импорт шёл через restore.php, причина названа', (bool)preg_match('/<th>Импорт<\/th><td>restore\.php: распаковка и импорт — [^<]*Не клиентом mysql: клиент mysql из config\.php \(mysql_bin\) не запускается/u', $report),
		preg_match('/<th>Импорт<\/th><td>[^<]*/u', $report, $mm) ? $mm[0] : 'строки «Импорт» нет');
	$keepRow = v("SELECT GROUP_CONCAT(CONCAT(NAME, '=', VALUE) ORDER BY NAME SEPARATOR ',') FROM b_option WHERE MODULE_ID = 'acme.api'");
	ok('options_keep двух скриптов объединены, имя-опечатка названо в отчёте', $keepRow === 'API_KEY=KeepMe-12345,backfill_token=TokenMe-12345,secret_key='
		&& preg_match('~оставлено по скрипту клиента: [^<;]*acme\.api/API_KEY~u', $report) && preg_match('~оставлено по скрипту клиента: [^<;]*acme\.api/backfill_token~u', $report)
		&& strpos($report, 'из options_keep не найдено среди секретов: acme.api/NO_SUCH') !== false,
		$keepRow . ' ' . (preg_match('/очищено опций[^<]*/u', $report, $mm) ? $mm[0] : 'строки «Секреты» нет'));

	// ============================================================ режим scrub
	$rx = false;
	try
	{
		$rx = v("SELECT REGEXP_REPLACE('ab','b','c')") === 'ac';
	}
	catch (Throwable $e)
	{
	}
	seed_prod();
	[, $body] = http('GET', '/devrestore.php');
	// заодно снята группа БП: её таблицы должны остаться как были
	[, $body] = http('POST', '/devrestore.php', ['action' => 'start', 'csrf' => csrf($body), 'confirm_domain' => $host,
		'groups' => array_values(array_diff($allGroups, ['Бизнес-процессы: журнал и задания'])), 'text_mode' => 'scrub']);
	[$log, , , $err] = run_steps($body);
	ok('scrub: дошёл до конца', !$err && !errors_in($log), implode('; ', $err));
	$m10 = (string)v('SELECT MESSAGE FROM b_im_message WHERE ID = 10');
	if ($rx)
	{
		ok('scrub: текст остался, почта и телефон вычищены', $m10 === 'Позвоните мне +7 000 000-00-00 или пишите user@example.test, Иван', $m10);
	}
	else
	{
		ok('scrub без REGEXP_REPLACE: откат на strict', strpos($m10, '[скрыто') === 0, $m10);
	}

	$tl1 = @unserialize((string)v('SELECT SETTINGS FROM b_crm_timeline WHERE ID = 1'), ['allowed_classes' => false]);
	ok('scrub: сериализованное чистится и в этом режиме', is_array($tl1) && strpos((string)($tl1['PHONE'] ?? ''), '+7000') === 0);
	ok('группа БП снята: сериализованные параметры задания как были', strpos((string)v('SELECT PARAMETERS FROM b_bp_task WHERE ID = 1'), '+7 916 123-45-67') !== false);
	$bpText = (string)v("SELECT CONCAT_WS('|', t.ACTION_NOTE, k.NAME, k.DESCRIPTION, k.DOCUMENT_NAME) FROM b_bp_tracking t, b_bp_task k");
	ok('группа БП снята: журнал и задания как были', $bpText === 'Согласовал Петров Иван Сергеевич, тел. +7 916 123-45-67, договор 7654321|Утвердить договор Сидоровой Марии|'
		. 'Позвоните Петрову Ивану: +7 916 123-45-67|Тендер 7654321: звонок Сидоровой', $bpText);

	// ============================================================ режим dict (словарь людей)
	seed_prod();
	q('ANALYZE TABLE b_im_message, b_crm_timeline');
	$cfgFile = "$home/config.php";
	file_put_contents($cfgFile, str_replace("'clean_max_rows' => 500000,", "'clean_max_rows' => 10000,", file_get_contents($cfgFile), $n));
	ok('dict: порог больших таблиц понижен для теста', $n === 1);
	[$st, $body] = http('GET', '/devrestore.php?phase=fields');
	ok('поля клиента: страница открывается (в т.ч. со слагом из 12 частей), смарт-процесс назван', $st === 200 && strpos($body, 'UF_CRM_1700000004') !== false && strpos($body, 'UF_CRM_1612345678') !== false && strpos($body, 'Тендеры') !== false, "статус $st " . substr(strip_tags($body), 0, 200));
	$raw = array_filter(['4510 123456', 'Ивановна', '345-67', 'VIP-42', 'Сидоровой'], static function ($x) use ($body) {
		return strpos($body, $x) !== false;
	});
	ok('поля клиента: значений нет, только формы', !$raw && strpos($body, '9999 999999') !== false && strpos($body, 'ААА-99') !== false, 'на странице: ' . implode(', ', $raw));
	ok('поля клиента: сигналы телефона и имени', strpos($body, 'телефон ×1') !== false && strpos($body, 'похоже на имя ×1') !== false);
	[$st, , $loc] = http('POST', '/devrestore.php', ['action' => 'save_fields', 'csrf' => csrf($body), 'uf' => ['CRM_CONTACT.UF_CRM_1700000001' => 'keep', 'CRM_CONTACT.UF_CRM_1700000000' => 'auto', 'CRM_CONTACT.NOPE' => 'keep']]);
	$pf = json_decode(file_get_contents("$home/profiles.json"), true);
	ok('поля клиента: решение сохранено, лишнее отброшено, профиль дополнен', $st === 302 && ($pf[$prodHost]['uf'] ?? null) === ['CRM_CONTACT.UF_CRM_1700000001' => 'keep'] && !empty($pf[$prodHost]['dev_hosts']), json_encode($pf[$prodHost]['uf'] ?? null));
	[, $body] = http('POST', '/devrestore.php', ['action' => 'save_fields', 'csrf' => 'bad', 'uf' => ['CRM_CONTACT.UF_CRM_1700000002' => 'replace']]);
	ok('поля клиента: без csrf не сохраняется', !isset(json_decode(file_get_contents("$home/profiles.json"), true)[$prodHost]['uf']['CRM_CONTACT.UF_CRM_1700000002']));

	[, $body] = http('GET', '/devrestore.php');
	ok('форма: режим «по словарю» и выбор компаний', strpos($body, 'value="dict"') !== false && strpos($body, 'name="companies"') !== false);
	[$st, $body] = http('POST', '/devrestore.php', ['action' => 'start', 'csrf' => csrf($body), 'confirm_domain' => $host, 'groups' => $allGroups,
		'scripts' => ['acme.php'], 'text_mode' => 'dict', 'companies' => 'individuals', 'leak_scan' => 'full']);
	// соль берём до первого шага: после отметки в базе её в состоянии уже нет
	$midSalt = (string)(state()['run']['salt'] ?? '');
	$midClean = null;
	[$log, $report, $salt, $err] = run_steps($body, static function () use ($midSalt, &$midClean) {
		$s = state();
		unset($s['run']['salt']);
		$midClean = $midSalt !== '' && strpos(json_encode($s, JSON_UNESCAPED_UNICODE), $midSalt) === false;
	});
	ok('dict: дошёл до конца без ошибок', !$err && !errors_in($log), implode('; ', $err) . json_encode(errors_in($log), JSON_UNESCAPED_UNICODE));
	ok('dict: соли нет в задачах во время обработки (только метка)', $midClean === true);
	$rep = html_entity_decode($report, ENT_QUOTES, 'UTF-8');
	ok('dict: полная проверка на утечки — все колонки целиком', (bool)preg_match('/проверено колонок: \d+, с находками: \d+ \(полная\)/u', $rep) && strpos($rep, '(быстрая') === false);

	$u1L = (string)v('SELECT LAST_NAME FROM b_user WHERE ID = 1');
	$u2L = (string)v('SELECT LAST_NAME FROM b_user WHERE ID = 2');
	$d10 = q('SELECT TITLE, COMMENTS FROM b_crm_deal WHERE ID = 10')->fetch_row();
	ok('dict: люди в тексте заменены, номер и остальной текст на месте', strpos($d10[1], 'Петрову') === false && strpos($d10[1], 'Ивану') === false && strpos($d10[1], 'Сидоровой') === false
		&& strpos($d10[1], ', договорились. Копия ') !== false && strpos($d10[1], '12-34-56') === false && strpos($d10[1], '+7000') !== false, $d10[1]);
	ok('dict: фамилия в тексте = фамилия в карточке, в том же падеже', strpos($d10[1], $u1L . 'у') !== false && strpos($d10[1], mb_substr($u2L, 0, -1) . 'ой') !== false, "$u1L / $u2L: {$d10[1]}");
	ok('dict: TITLE чистится словарём, номер остаётся', strpos($d10[0], 'Тендер 7654321: звонок ') === 0 && strpos($d10[0], 'Сидоровой') === false && $d10[0] !== 'Сделка #10', $d10[0]);
	$bp = q("SELECT t.ACTION_NOTE, k.NAME, k.DOCUMENT_NAME FROM b_bp_tracking t, b_bp_task k")->fetch_row();
	ok('dict: журнал БП читается, имя документа в задании = TITLE сделки', $bp[2] === $d10[0] && strpos($bp[0], 'Согласовал ') === 0 && strpos($bp[0], 'договор 7654321') !== false
		&& strpos($bp[1], 'Утвердить договор ') === 0 && !preg_match('/Петров Иван Сергеевич|Петрову Ивану|Сидоровой Марии|звонок Сидоровой|123-45-67/u', implode('|', $bp)), implode(' | ', $bp) . ' ≠ ' . $d10[0]);
	$bpp = @unserialize((string)v('SELECT PARAMETERS FROM b_bp_task WHERE ID = 1'), ['allowed_classes' => false]);
	$pjd = json_decode((string)v('SELECT PARAM_JSON FROM b_im_message_param WHERE ID = 1'));
	ok('dict: внутри сериализованного и JSON люди заменены, текст и коды на месте', is_array($bpp) && $bpp['USER'] === 'maria'
		&& strpos($bpp['COMMENT'], 'Перезвонить ') === 0 && strpos($bpp['COMMENT'], 'Сидоровой') === false && strpos($bpp['COMMENT'], '123-45-67') === false
		&& $pjd instanceof stdClass && strpos($pjd->ATTACH->CONTACT, 'Звонок ') === 0 && strpos($pjd->ATTACH->CONTACT, 'Сидоровой') === false
		&& $pjd->ATTACH->URL === 'https://example.com/a/b', json_encode([$bpp, $pjd], JSON_UNESCAPED_UNICODE));
	ok('dict: названия без людей, гео и служебный код не тронуты', col('SELECT TITLE FROM b_crm_deal WHERE ID IN (9, 11) ORDER BY ID') === ['Поставка для ООО Ромашка', 'Поставка в Орловская область']
		&& v('SELECT COMMENTS FROM b_crm_deal WHERE ID = 11') === '15047 : 28.10.2026 16:49:17');
	$leads = col('SELECT COMPANY_TITLE FROM b_crm_lead WHERE ID IN (8, 9) ORDER BY ID');
	ok('компании: ИП заменён, ООО оставлено', strpos($leads[0], 'ИП ') === 0 && strpos($leads[0], 'Гончаренко') === false && $leads[1] === 'ООО Ромашка', implode(' | ', $leads));
	$comp = col('SELECT TITLE FROM b_crm_company ORDER BY ID');
	ok('компании: юрлицо оставлено, компания с ИНН-12 — человек', $comp[0] === 'ООО Ромашка' && strpos($comp[1], 'Гончаренко') === false && $comp[1] !== ''
		&& strpos($comp[2], 'Петренко') === false, implode(' | ', $comp));
	$rq = col('SELECT RQ_COMPANY_NAME FROM b_crm_requisite WHERE ID IN (2, 3, 4) ORDER BY ID');
	ok('реквизиты: ИНН-12 — название заменено, юрлицо оставлено', strpos((string)$rq[0], 'Гончаренко') === false && $rq[1] === 'ООО Ромашка'
		&& strpos((string)$rq[2], 'Заря') === false && $rq[2] !== '', implode(' | ', $rq));
	ok('dict: ИП-мужчина с фамилией однофамилицы — двойник мужского рода', !preg_match('/(ов|ев|ин|ын)а \S+ \S+вич$/u', $comp[1] . ' | ' . $rq[0]) && !preg_match('/(ов|ев|ин|ын)а \S+ \S+вич \|/u', $comp[1] . ' | '), $comp[1] . ' | ' . $rq[0]);
	$cm = (string)v('SELECT COMMENTS FROM b_crm_company WHERE ID = 500');
	ok('dict: e-mail с кириллицей (домен .рф, «а» в mаil.ru) заменён, текст на месте', strpos($cm, 'пишите ') === 0 && strpos($cm, 'почта.рф') === false
		&& strpos($cm, 'mаil.ru') === false && substr_count($cm, '@example.test') === 2, $cm);
	$cc = (string)v('SELECT COMMENTS FROM b_crm_contact WHERE ID = 1000000001');
	ok('cp1251: текст чистится, кириллица цела', strpos($cc, 'Клиентка из Твери, звонить ') === 0 && strpos($cc, 'Сидоровой') === false && strpos($cc, '?') === false, $cc);
	$uts = q('SELECT * FROM b_uts_crm_contact')->fetch_assoc();
	ok('UF: код из uf_keep не тронут, текстовое поле почищено от имён', $uts['UF_CRM_CODE'] === 'VIP-42' && strpos((string)$uts['UF_CRM_1700000000'], 'Мария') === false && strpos((string)$uts['UF_CRM_1700000000'], 'Ивановна') === false && strpos((string)$uts['UF_CRM_1700000000'], ', муж ') !== false, json_encode($uts, JSON_UNESCAPED_UNICODE));
	ok('UF «оставить» со страницы полей: текст и имя целы, телефон заменён', strpos((string)$uts['UF_CRM_1700000001'], 'код 15047, тел. +7000') === 0
		&& strpos((string)$uts['UF_CRM_1700000001'], ', Гончаренко А. В.') !== false, (string)$uts['UF_CRM_1700000001']);
	$pf = json_decode(file_get_contents("$home/profiles.json"), true);
	ok('профиль после обработки: решения по полям сохранились', ($pf[$prodHost]['uf'] ?? null) === ['CRM_CONTACT.UF_CRM_1700000001' => 'keep'] && ($pf[$prodHost]['text_mode'] ?? '') === 'dict');
	ok('UF-ловушки: «Количество адресов» и «Карточка склада» не адрес и не карта', $uts['UF_CRM_1700000002'] === '3 адреса: Тверь' && $uts['UF_CRM_1700000003'] === 'СК-12');
	$slug = 'https://tenderland.ru/tender/Postavka-Meditsinskikh-Izdeliy-Dlya-Nuzhd-Goroda-Moskvy-V-Godu-Lot-Sem';
	$tl = (string)v('SELECT COMMENT FROM b_crm_timeline WHERE ID = 3002');
	ok('dict: слаг из 12 частей и товар из 6 частей с заглавных не изменены, люди рядом заменены', $uts['UF_CRM_1700000004'] === "$slug Шприц-Инъекционный-Трехкомпонентный-Одноразовый-Стерильный-Луер"
		&& strpos($tl, " по $slug") !== false && strpos($tl, 'Сидоровой') === false, $uts['UF_CRM_1700000004'] . ' / ' . $tl);
	$sp = q('SELECT UF_CRM_3_CLIENT_FIO, UF_CRM_3_NOTE FROM b_crm_dynamic_items_128')->fetch_row();
	ok('смарт-процесс: UF-ФИО заменено целиком, UF-текст почищен', $sp[0] !== 'Иванов Иван Иванович' && count(explode(' ', $sp[0])) === 3 && strpos($sp[1], 'Связаться с ') === 0 && strpos($sp[1], 'Сидоровой') === false, implode(' | ', $sp));
	ok('много строк пачками: имён не осталось', (int)v("SELECT COUNT(*) FROM b_crm_timeline WHERE COMMENT LIKE '%Сидоровой%'") === 0 && (int)v("SELECT COUNT(*) FROM b_crm_timeline WHERE COMMENT LIKE 'Звонок % №%'") === 3000);
	ok('большая таблица и колонка-дубль — замена целиком, с отметкой в отчёте', strpos((string)v('SELECT MESSAGE FROM b_im_message WHERE ID = 10'), '[скрыто') === 0
		&& strpos((string)v('SELECT MESSAGE_OUT FROM b_im_message WHERE ID = 3'), '[скрыто') === 0 && strpos($rep, 'Большие таблицы') !== false && strpos($rep, 'b_im_message') !== false,
		v("SELECT CONCAT(LEFT(MESSAGE, 30), ' | ', COALESCE(LEFT(MESSAGE_OUT, 20), 'NULL')) FROM b_im_message WHERE ID = 10") . ' / отчёт: ' . (preg_match('/Большие таблицы[^<]*/u', $rep, $bm) ? $bm[0] : 'нет')
		. ' / оценка строк: ' . v("SELECT TABLE_ROWS FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'b_im_message'"));
	ok('проверка нашла имена в чужой таблице и дала готовое правило', strpos($rep, 'zz_legacy_people.note') !== false && strpos($rep, "['table' => 'zz_legacy_people', 'set' => ['note' => 'clean']],") !== false);
	ok('пример имени замаскирован', strpos($rep, 'Гончаренко') === false && strpos($rep, 'Злата') === false);
	ok('город из справочника местоположений, совпавший с фамилией, — не имя', strpos($rep, 'zz_legacy_towns') === false);
	ok('почищенные таблицы имён не дали', !preg_match('/b_crm_(deal|timeline|contact|company|lead)\.[A-Z_]+/', implode(' ', array_column((array)(state()['run']['report']['leak_names'] ?? []), 'c')) . ' ' . (preg_match('/имена людей.*?<\/table>/su', $rep, $mm) ? $mm[0] : '')));
	ok('словарь удалён', (int)v("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'zz_devrestore_dict'") === 0);
	$marker = json_decode((string)v("SELECT VALUE FROM b_option WHERE MODULE_ID='devrestore' AND NAME='info'"), true);
	ok('отметка: режим dict, предупреждения посчитаны', ($marker['text_mode'] ?? '') === 'dict' && ($marker['warnings'] ?? 0) >= 1, json_encode($marker, JSON_UNESCAPED_UNICODE));
	[, $body] = http('GET', '/devrestore.php');
	ok('главная: «псевдонимизирована … с предупреждениями»', strpos($body, 'псевдонимизирована') !== false && strpos($body, 'с предупреждениями') !== false);
	$stateRaw = file_get_contents(glob("$home/state/*.json")[0]);
	$logsRaw = implode('', array_map('file_get_contents', glob("$home/logs/*.txt")));
	ok('dict: соли нет в состоянии, журнале и отчёте', $salt !== '' && $salt === $midSalt && strpos($stateRaw, $salt) === false && strpos($logsRaw, $salt) === false && strpos($report, $salt) === false);
	$ps = ps_salt($salt);
	ok('dict: соли нет в performance_schema' . ($ps === null ? ' (недоступна — пропуск)' : ''), $ps === null || $ps === 0, (string)$ps);

	// сброс: словарь, оставшийся от прерванной обработки, удаляется
	q('CREATE TABLE zz_devrestore_dict (k CHAR(32) NOT NULL PRIMARY KEY)');
	[, $body] = http('GET', '/devrestore.php');
	ok('главная предупреждает об оставленном словаре', strpos($body, 'zz_devrestore_dict') !== false && strpos($body, 'Сбросить') !== false);
	http('POST', '/devrestore.php', ['action' => 'cancel', 'csrf' => csrf($body)]);
	ok('«Сбросить» удаляет словарь', (int)v("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'zz_devrestore_dict'") === 0);
	file_put_contents($cfgFile, str_replace("'clean_max_rows' => 10000,", "'clean_max_rows' => 500000,", file_get_contents($cfgFile)));

	// ============================================================ боевая база на этом хосте
	q("DELETE FROM b_option WHERE MODULE_ID = 'devrestore'");
	q("UPDATE b_option SET VALUE = 'www.$host' WHERE MODULE_ID = 'main' AND NAME = 'server_name'"); // с www — тот же сайт
	[, $body] = http('GET', '/devrestore.php');
	ok('главная предупреждает о боевой базе', strpos($body, 'похоже на боевой сайт') !== false);
	[$st, $body] = http('POST', '/devrestore.php', ['action' => 'start', 'csrf' => csrf($body), 'confirm_domain' => $host, 'groups' => $allGroups]);
	ok('обработка базы, где server_name = этот хост, запрещена', $st === 500 && strpos($body, 'запрещена') !== false, "статус $st");
	$profiles[$host] = [];
	file_put_contents("$home/profiles.json", json_encode($profiles));
	[$st] = http('GET', '/devrestore.php');
	ok('хост, записанный как боевой домен в профилях → 403', $st === 403, "статус $st");
}
catch (Throwable $e)
{
	ok('тест упал исключением', false, $e->getMessage() . ' @' . $e->getLine());
}
finally
{
	proc_terminate($server);
}

$serverLog = (string)@file_get_contents("$work/server.log");
ok('в логе веб-сервера нет PHP-ошибок', !preg_match('/PHP (Fatal|Parse|Warning|Notice|Deprecated)/', $serverLog), implode("\n", array_slice(preg_grep('/PHP (Fatal|Parse|Warning|Notice|Deprecated)/', explode("\n", $serverLog)), 0, 5)));

echo PHP_EOL . ($fails ? "ИТОГ: $fails из $checks проверок упали" : "ИТОГ: все $checks проверок прошли") . " ($name)" . PHP_EOL;
exit($fails ? 1 : 0);
