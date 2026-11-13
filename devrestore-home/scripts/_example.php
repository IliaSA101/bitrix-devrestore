<?php
/**
 * Образец скрипта клиента. Скопируйте под именем клиента (например, acme.php) и отметьте
 * в форме devrestore — выбор запомнится в профиле по боевому домену.
 *
 * Файл должен только вернуть массив: не объявляйте функции и классы на верхнем уровне
 * (файл подключается несколько раз), всю логику держите в замыкании 'run'.
 *
 * Порядок: anonymize и sql выполняются чистым SQL сразу после общего обезличивания,
 * run — позже, с подключённым ядром Битрикс (после уборки кеша).
 */

return [
	'title' => 'Образец: доп. правила и настройки',
	'description' => 'Показывает все возможности скрипта клиента. Сам ничего полезного не делает.',

	// Доп. правила обезличивания — тот же формат, что и встроенные (см. dr_rules в devrestore.php).
	// Методы: first_name, last_name, patronymic, full_name, email, phone, contact (e-mail или телефон),
	//   company, address, digits (те же длина, только цифры), text, ['title', 'Метка'], login, filename,
	//   url, ip, im, empty, null, secret, ['fixed', 'значение'], keep,
	//   ['case', 'TYPE_COL', ['EMAIL' => 'email', ...], 'метод по умолчанию'],
	//   ['expr', 'SQL с {col}, {pk}, {m:метод}'].
	// В where колонки — с алиасом t. Несуществующие таблицы и колонки пропускаются.
	'anonymize' => [
		['table' => 'b_uts_crm_contact', 'set' => ['UF_CRM_PASSPORT' => 'digits', 'UF_CRM_MANAGER_NOTE' => 'text']],
		['tables_like' => 'b_hlbd_clients%', 'set' => ['UF_FIO' => 'full_name', 'UF_PHONE' => 'phone']],
		['table' => 'b_iblock_element', 'where' => 't.`IBLOCK_ID` = 42', 'set' => ['NAME' => 'full_name', 'PREVIEW_TEXT' => 'text']],
	],

	// Пользовательские поля, которые не трогать: нераспознанные строковые UF CRM и пользователей
	// иначе обрабатываются как текст (служебные коды, ID интеграций и т. п. перечислите здесь).
	'uf_keep' => ['UF_CRM_EXTERNAL_ID'],

	// Опции-секреты, которые шаг «Секреты» не стирает: «MODULE_ID/NAME», точное совпадение, без масок.
	// Значение остаётся с прода — например, ключ платного API для ручных прогонов на копии.
	// Безопасно, только пока агенты выключены (devrestore их выключает): иначе модуль пойдёт в API сам.
	'options_keep' => [
		// 'acme.sync/API_KEY',
	],

	// Произвольный SQL после обезличивания (до подключения ядра).
	'sql' => [
		// "UPDATE b_option SET VALUE = 'N' WHERE MODULE_ID = 'acme.sync' AND NAME = 'enabled'",
	],

	// Действия в ядре Битрикс: модули, опции, пользователи. $ctx->log($text, 'info'|'ok'|'warn'|'error').
	'run' => function (DevRestoreContext $ctx) {
		// \Bitrix\Main\Config\Option::set('acme.sync', 'api_url', 'https://sandbox.example.test');
		$ctx->log('Образец: dev-хост ' . $ctx->devHost . ', боевой домен ' . $ctx->prodDomain);
	},
];
