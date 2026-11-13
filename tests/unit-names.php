<?php
/**
 * Юнит-тест чистки текста «по словарю» (DrName, DrFakes, DrCleaner) без БД.
 * Классы берутся из devrestore.php как есть: вырезается кусок от DR_SALT_MARK до раздела «Правила».
 *   php tests/unit-names.php [--src=копия-devrestore.php]
 */

$opt = getopt('', ['src:']);
$src = (string)file_get_contents($opt['src'] ?? __DIR__ . '/../devrestore.php');
$a = strpos($src, 'const DR_SALT_MARK');
$b = strpos($src, "// Правила: что отключаем");
if ($a === false || $b === false)
{
	fwrite(STDERR, "не нашёл границы классов в devrestore.php\n");
	exit(1);
}
function dr_lit(string $s): string
{
	return "'" . addslashes($s) . "'";
}
$b = (int)strrpos(substr($src, 0, $b), "\n// ====");
eval(substr($src, $a, $b - $a));

$fails = 0;
$total = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
	global $fails, $total;
	$total++;
	if (!$ok)
	{
		$fails++;
		echo "FAIL $name" . ($detail !== '' ? " — $detail" : '') . "\n";
	}
}

$fk = new DrFakes('unit-salt');
// словарь как его строит SQL: ключ основы → вид → род → двойник (здесь двойник считает PHP-копия, в e2e — SQL)
$people = [
	['ирина', 'иванова', 'игоревна'],
	['иван', 'иванов', 'иванович'],
	['николай', 'римский-корсаков', 'андреевич'],
	['анна', 'орловская', ''],
	['ольга', 'полина', ''],
	['пётр', 'петров', 'петрович'],
	['john', 'smith', ''],
	['анна', 'гончаренко', ''],
	['олег', 'морозов', 'олегович'],
];
$dict = [];
foreach ($people as [$f, $l, $p])
{
	$fem = DrName::firstFem($f);
	[$ff] = $fk->first($f);
	$dict[$fk->key(DrName::fold($f)) . 'f' . (int)$fem] = $ff;
	$dict[$fk->key(DrName::fold($l)) . 'l' . (int)DrName::surnameFem($l)] = $fk->last($l, $fem);
	if ($p !== '')
	{
		$dict[$fk->key(DrName::fold($p)) . 'p' . (int)$fem] = $fk->patr($p, $fem);
	}
}
$c = new DrCleaner($fk, $dict);
$fakeIvanovaF = DrName::likeCase($fk->last('иванова', true), 'Иванова');
$fakeIvanovM = $fk->last('иванов', false);

// --- случаи из постановки задачи ---
$r = $c->clean('Иванова И. И. отказала, аукцион был уже');
check('ФИО с инициалами', preg_match('/^(\p{Lu}\p{Ll}+) (\p{Lu})\. (\p{Lu})\. отказала, аукцион был уже$/u', $r, $m) === 1 && $m[1] === $fakeIvanovaF, $r);
check('муж и жена — одна фамилия-двойник', $fakeIvanovaF === DrName::likeCase($fakeIvanovM . 'а', 'Иванова'), $fakeIvanovaF . ' / ' . $fakeIvanovM);
$r = $c->clean('Звонок Иванову по сделке');
check('одиночная фамилия в падеже', $r === 'Звонок ' . DrName::likeCase(DrName::decline($fakeIvanovM, 'l', false)[2], 'Иванову') . ' по сделке', $r);
check('номер в названии остаётся', $c->clean('Торги 7654321') === 'Торги 7654321');
check('служебный код остаётся', $c->clean('15047 : 28.10.2026 16:49:17') === '15047 : 28.10.2026 16:49:17');
check('номер извещения 19 цифр остаётся', $c->clean('Извещение 0373100012323000045') === 'Извещение 0373100012323000045');
$r = $c->clean('позвони иванову завтра');
check('строчная фамилия', strpos($r, 'иванову') === false && strpos($r, 'позвони ') === 0, $r);
check('гео: Орловская область', $c->clean('Поставка в Орловская область') === 'Поставка в Орловская область');
check('имя не путать с фамилией', $c->clean('Полина пришла') === 'Полина пришла');
$r = $c->clean('Передал Ивану Петренко документы');
check('человек вне словаря: Имя Фамилия', strpos($r, 'Ивану') === false && strpos($r, 'Петренко') === false && strpos($r, 'документы') !== false, $r);
$r = $c->clean('Счёт для ИП Сидорчук А. В.');
check('ИП + фамилия вне словаря', strpos($r, 'Сидорчук') === false && strpos($r, 'Счёт для ИП ') === 0, $r);
$r = $c->clean('Договор с Иванова Ивана Ивановича');
$fakeF = $fk->first('иван')[0];
check('полное ФИО в родительном', $r === 'Договор с ' . DrName::likeCase(DrName::decline($fakeIvanovM, 'l', false)[1], 'И') . ' '
	. DrName::likeCase(DrName::decline($fakeF, 'f', false)[1], 'И') . ' ' . DrName::likeCase(DrName::decline($fk->patr('иванович', false), 'p', false)[1], 'И'), $r);
$r = $c->clean('Композитор Римским-Корсаковым');
check('двойная фамилия в творительном', strpos($r, 'Римским') === false, $r);
$r = $c->clean('ИВАНОВ ИВАН');
check('прописные', $r === mb_strtoupper($r) && strpos($r, 'ИВАНОВ') === false, $r);
$r = $c->clean('Ответственный: Иванов&nbsp;И.&nbsp;И.');
check('&nbsp; между фамилией и инициалами', strpos($r, 'Иванов') === false && strpos($r, '&nbsp;') !== false, $r);
$r = $c->clean('И.И.Иванов подписал');
check('инициалы слитно', strpos($r, 'Иванов') === false && strpos($r, 'И.И.') === false && preg_match('/^\p{Lu}\.\p{Lu}\.\p{Lu}\p{Ll}+ подписал$/u', $r), $r);
$r = $c->clean('И.И.Петренко подписал');
check('инициалы слитно, фамилия вне словаря', strpos($r, 'Петренко') === false, $r);
foreach (['Магнитогорский ЦКБ', 'Ставропольский ГАУ', 'Московский ГУП'] as $keep)
{
	check("не человек: $keep", $c->clean($keep) === $keep, $c->clean($keep));
}
foreach (['ждём до морозов', 'у столяров выходной'] as $keep)
{
	check("строчное множественное — не фамилия: $keep", $c->clean($keep) === $keep, $c->clean($keep));
}
$r = $c->clean('передать морозову');
check('строчная фамилия в дательном заменяется', strpos($r, 'морозову') === false, $r);
$r = $c->clean('Mr John Smith wrote');
check('латиница из словаря', strpos($r, 'Smith') === false, $r);
$r = $c->clean('Счёт Гончаренко Андрею Викторовичу');
$fakeG = DrName::likeCase(DrName::decline($fk->last('гончаренко', false), 'l', false)[2], 'Г');
check('несклоняемая фамилия: род по отчеству, а не по однофамилице из словаря', strpos($r, 'Счёт ' . $fakeG . ' ') === 0, "$r / $fakeG");
$r = $c->clean('Звонила Гончаренко Анна');
check('несклоняемая фамилия женщины — из словаря', strpos($r, 'Звонила ' . DrName::likeCase($fk->last('гончаренко', true), 'Г') . ' ') === 0, $r);
$r = $c->clean('Договор с Сидорова Ивана Петровича');
check('мужская фамилия в родительном вне словаря — не женская', strpos($r, 'Договор с ' . DrName::likeCase(DrName::decline($fk->last('сидоров', false), 'l', false)[1], 'С') . ' ') === 0, $r);
$r = $c->clean('Петров Пётр Петрович');
check('реальный человек с фамилией из списка двойников заменяется', $r !== 'Петров Пётр Петрович', $r);

// --- документы и контакты ---
$r = $c->clean('пишите a.b@mail.ru или +7 (912) 345-67-89, 8 (4012) 12-34-56, +375 29 123-45-67');
check('e-mail', strpos($r, 'mail.ru') === false && strpos($r, '@example.test') !== false, $r);
check('телефоны', strpos($r, '345-67-89') === false && strpos($r, '12-34-56') === false && strpos($r, '123-45-67') === false, $r);
check('e-mail = как SQL', $fk->email(' A.B@Mail.ru') === 'u' . substr(md5('unit-salt:e:a.b@mail.ru'), 0, 10) . '@example.test');
foreach (['7 912 345-67-89', '912 345-67-89', '(912) 345-67-89', '7(912)3456789', '8 912 345 67 89'] as $ph)
{
	$r = $c->clean("тел. $ph, звонить");
	check("телефон без +7: $ph", $r === 'тел. ' . $fk->phone($ph) . ', звонить', $r);
}
foreach (['Заказ 9123456789', 'Тендер 7654321', 'Сумма 912 345,67', 'Счёт 912-345', 'Код 1912 345-67-89'] as $keep)
{
	check("не телефон: $keep", $c->clean($keep) === $keep, $c->clean($keep));
}
foreach (['моб.+7 916 123-45-67', 'тел.89161234567', 'Иванов,89161234567'] as $ph)
{
	check("телефон после знака: $ph", !preg_match('/916.?123|9161234567/', $c->clean($ph)), $c->clean($ph));
}
check('телефон с NBSP = как в карточке', $fk->phone("+7\u{00A0}916\u{00A0}123-45-67") === $fk->phone('+7 916 123-45-67'));
check('телефон без +7 = как в карточке', $fk->phone('912 345-67-89') === $fk->phone('+7 (912) 345-67-89'));
$r = $c->clean('ИНН 500100732259, ИНН юрлица 7707083893');
check('ИНН-12 заменён, ИНН-10 остался', strpos($r, '500100732259') === false && strpos($r, '7707083893') !== false, $r);
$r = $c->clean('СНИЛС 112-233-445 95');
check('СНИЛС', strpos($r, '112-233-445 95') === false, $r);
foreach (['Штрихкод 4607001234567', 'Заказ 4000000000000002', 'Позиция 2 2223000048400011'] as $keep)
{
	check("не карта: $keep", $c->clean($keep) === $keep, $c->clean($keep));
}
$r = $c->clean('оплата картой 4000000000000002');
check('карта после слова «карта»', strpos($r, '4000000000000002') === false, $r);
$r = $c->clean('р/с 40817810099910004312');
check('лицевой счёт 20 цифр', strpos($r, '40817810099910004312') === false, $r);
$r = $c->clean('карта 4111 1111 1111 1111');
check('карта по Луну', strpos($r, '1111 1111') === false, $r);
$r = $c->clean('паспорт 45 10 123456 выдан');
check('паспорт', strpos($r, '123456') === false && strpos($r, 'паспорт ') === 0, $r);
check('год рождения', $c->clean('Иван, 1985 г.р.') !== 'Иван, 1985 г.р.');
$r = $c->clean('Доставка: ул. Ленина, д. 5, кв. 12, звонить заранее');
check('адрес', strpos($r, 'Ленина') === false && strpos($r, 'кв. 12') === false && strpos($r, 'звонить заранее') !== false, $r);
check('квартал не адрес', $c->clean('план на 1 кв. 2026') === 'план на 1 кв. 2026');
check('сумма не телефон', $c->clean('НМЦК 80 123 456 789,00 руб.') === 'НМЦК 80 123 456 789,00 руб.');
$r = $c->clean('профиль https://vk.com/id123456 и сайт https://zakupki.gov.ru/epz/order/1');
check('соцсеть заменена, госзакупки остались', strpos($r, 'vk.com') === false && strpos($r, 'zakupki.gov.ru') !== false, $r);

// --- значение-человек ---
$p1 = $c->person('Иванов Иван Иванович');
$p2 = $c->clean('Иванов Иван Иванович');
check('ФИО-поле = ФИО в тексте', $p1 === $p2, "$p1 / $p2");
$r = $c->person('Петренко Тарас');
check('ФИО-поле вне словаря', $r !== 'Петренко Тарас' && count(explode(' ', $r)) === 2, $r);

// --- склонения ---
$cases = [
	['иванов', 'l', false, 'иванов иванова иванову иванова ивановым иванове'],
	['иванова', 'l', true, 'иванова ивановой ивановой иванову ивановой ивановой'],
	['достоевский', 'l', false, 'достоевский достоевского достоевскому достоевского достоевским достоевском'],
	['толстой', 'l', false, 'толстой толстого толстому толстого толстым толстом'],
	['шевченко', 'l', false, 'шевченко шевченко шевченко шевченко шевченко шевченко'],
	['гершман', 'l', true, 'гершман гершман гершман гершман гершман гершман'],
	['павел', 'f', false, 'павел павла павлу павла павлом павле'],
	['илья', 'f', false, 'илья ильи илье илью ильей илье'],
	['дмитрий', 'f', false, 'дмитрий дмитрия дмитрию дмитрия дмитрием дмитрии'],
	['мария', 'f', true, 'мария марии марии марию марией марии'],
	['любовь', 'f', true, 'любовь любови любови любовь любовью любови'],
	['ольга', 'f', true, 'ольга ольги ольге ольгу ольгой ольге'],
	['ивановна', 'p', true, 'ивановна ивановны ивановне ивановну ивановной ивановне'],
];
foreach ($cases as [$w, $k, $f, $exp])
{
	check("склонение $w", implode(' ', DrName::decline($w, $k, $f)) === $exp, implode(' ', DrName::decline($w, $k, $f)));
}
// двойник никогда не совпадает с оригиналом, даже если настоящее имя есть в наших списках
$same = [];
foreach (['unit-salt', 'другая соль', 'третья'] as $sl)
{
	$f2 = new DrFakes($sl);
	foreach (DrAnon::LAST_M as $w)
	{
		$lw = DrName::fold(mb_strtolower($w));
		if ($f2->last($lw, false) === $lw || $f2->last($lw . 'а', true) === $lw . 'а')
		{
			$same[] = $w;
		}
	}
	foreach (array_merge(DrAnon::FIRST_M, DrAnon::FIRST_F) as $w)
	{
		$lw = DrName::fold(mb_strtolower($w));
		if ($f2->first($lw)[0] === mb_strtolower($w))
		{
			$same[] = $w;
		}
	}
	foreach (DrAnon::PATR_M as $w)
	{
		$lw = mb_strtolower($w);
		if ($f2->patr($lw, false) === $lw)
		{
			$same[] = $w;
		}
	}
}
check('двойник не совпадает с оригиналом', !$same, implode(', ', $same));
// все двойники склоняются: косвенные падежи отличаются от именительного
foreach (array_merge(DrAnon::LAST_M, DrAnon::FIRST_M, DrAnon::PATR_M) as $w)
{
	$lw = mb_strtolower($w);
	$kind = in_array($w, DrAnon::LAST_M, true) ? 'l' : (in_array($w, DrAnon::FIRST_M, true) ? 'f' : 'p');
	check("двойник склоняется: $w", DrName::decline($lw, $kind, false)[1] !== $lw);
}
$any = static function () {
	return true;
};
check('разбор: Ивановым', DrName::parse('ивановым', 'l', $any) === ['иванов', false, 4]);
check('разбор: Павлом', (DrName::parse('павлом', 'f', static function ($l) {
	return $l === 'павел';
})[0] ?? '') === 'павел');

// слова из многих частей через дефис: вариантов не больше, чем у двойной фамилии (раньше рост 44^k и 1 ГБ на 5 частях)
$lemmas = new ReflectionMethod('DrName', 'lemmas');
$lemmas->setAccessible(true);
$two = count($lemmas->invoke(null, 'санкт-петербург'));
check('двойная фамилия: варианты обеих частей перемножаются', $two > 100 && in_array('римский-корсаков', $lemmas->invoke(null, 'римским-корсаковым'), true), (string)$two);
$t0 = microtime(true);
$m0 = memory_get_usage();
foreach ([3, 5, 12] as $k)
{
	foreach (['кир' => 'из-за-того-что-по-над-при-без-для-про-под-над', 'лат' => 'postavka-meditsinskikh-izdeliy-dlya-nuzhd-goroda-moskvy-v-2026-godu-lot-7'] as $abc => $base)
	{
		$w = implode('-', array_slice(explode('-', $base), 0, $k));
		$n = count($lemmas->invoke(null, $w));
		check("слово из $k частей ($abc): вариантов не больше, чем у двух частей", $n <= $two, "$w: $n");
		check("слово из $k частей ($abc): не имя", DrName::parse($w, 'l', $any) === null || $abc === 'лат', $w);
	}
}
check('многочастные слова разбираются быстро и без памяти', microtime(true) - $t0 < 1 && memory_get_usage() - $m0 < 8 << 20,
	round(microtime(true) - $t0, 3) . ' с, ' . (memory_get_usage() - $m0) . ' байт');
check('латиница: только точное совпадение', $lemmas->invoke(null, 'smith') === ['smith'] && $lemmas->invoke(null, 'postavka-meditsinskikh-izdeliy') === ['postavka-meditsinskikh-izdeliy']);
check('предохранитель: слово длиннее 40 букв — не имя', $lemmas->invoke(null, str_repeat('иванов', 7)) === []);
$slug = 'https://tenderland.ru/tender/Postavka-Meditsinskikh-Izdeliy-Dlya-Nuzhd-Goroda-Moskvy-V-Godu из-за-того-что Шприц-Инъекционный-Трехкомпонентный-Одноразовый-Стерильный-Луер Римским-Корсаковым';
$r = $c->clean($slug);
check('текст со слагом, «из-за-того-что» и товаром с заглавных: слаг и слово целы, двойная фамилия заменена', strpos($r, 'Postavka-Meditsinskikh-Izdeliy-Dlya-Nuzhd-Goroda-Moskvy-V-Godu из-за-того-что Шприц-Инъекционный-Трехкомпонентный-Одноразовый-Стерильный-Луер ') !== false
	&& strpos($r, 'Римским') === false, $r);

// v2.6: e-mail с кириллицей — проверка утечек их видит, значит и чистка должна заменять
$r = $c->clean('пишите иван@почта.рф или ivan@mаil.ru, спасибо');
check('e-mail на .рф и с кириллической «а» в домене заменены, текст вокруг цел', substr_count($r, '@example.test') === 2 && strpos($r, 'пишите ') === 0
	&& strpos($r, ', спасибо') !== false && strpos($r, 'почта') === false, $r);

// v2.6: регулярка проверки утечек (та же, что в MySQL, переведённая в PCRE для проверки в PHP)
if (!preg_match('/\nfunction dr_leak_pcre\(.*?\n}\n/s', $src, $fm))
{
	check('dr_leak_pcre найдена в devrestore.php', false);
}
else
{
	eval($fm[0]);
	$leakAll = dr_leak_pcre(DR_RX_EMAIL . '|' . DR_RX_PHONE_PLUS . '|' . DR_RX_PHONE_8 . '|' . DR_RX_PHONE_BARE . '|' . DR_RX_PHONE_SEP);
	foreach (['тел. 8 916 123-45-67, звоните', '89161234567', 'моб: 79161234567.', '+7 (916) 123-45-67', '(916) 123-45-67', 'иван@почта.рф', 'ivan@mail.ru'] as $s)
	{
		check("утечка: «{$s}» — найдено", preg_match($leakAll, $s) === 1);
	}
	// 11 цифр внутри hex-ID, UUID, хеша и длинного номера — не телефон; ИНН-12, начинающийся с 8, — тоже
	foreach (['5f89161234567abc', 'a89161234567b', '0e3f8a7c-89161234567f-4b1d', '891612345678', '861234567890', 'ETAG-x79161234567y'] as $s)
	{
		check("утечка: «{$s}» — не телефон", preg_match($leakAll, $s) === 0);
	}
	check('колонки ID и хешей проверяются только на e-mail', preg_match(DR_RX_ID_COLUMN, 'WORKFLOW_ID') && preg_match(DR_RX_ID_COLUMN, 'FILE_HASH')
		&& preg_match(DR_RX_ID_COLUMN, 'DAV_XML_ID') && preg_match(DR_RX_ID_COLUMN, 'ETAG') && !preg_match(DR_RX_ID_COLUMN, 'COMMENTS') && !preg_match(DR_RX_ID_COLUMN, 'PHONE'));
}

// v2.7: просмотр документов Диска после очистки ключей облачного редактора
$cleared = ['main/smtp_password', 'disk/disk_onlyoffice_b24_secretKey', 'disk/disk_boards_b24_secretKey'];
check('Диск: ключ облачного редактора стёрт — просмотр встроенный, доски пусто',
	dr_disk_viewer_fix('onlyoffice', 'boards', $cleared) === ['default_viewer_service' => 'bitrix', 'default_viewer_service_boards' => '']);
check('Диск: ключи оставлены через options_keep (не среди очищенных) — выбор не трогаем', dr_disk_viewer_fix('onlyoffice', 'boards', ['main/smtp_password']) === []);
check('Диск: просмотр уже встроенный или не задан — менять нечего', dr_disk_viewer_fix('bitrix', '', $cleared) === [] && dr_disk_viewer_fix(null, null, $cleared) === []);

// v2.7: заголовки итогового ответа — браузер забывает вход и сессию прежней копии
$h = dr_logout_headers(['PHPSESSID', 'BITRIX_SM_CLIENT_LOCAL_UIDH', 'BITRIX_SM_LOGIN', 'OTHER_SITE', 'dr_key', 'bad name;x'], 'client.local:8080', false, 'PHPSESSID', 'BITRIX_SM_CLIENT_LOCAL');
$hs = implode("\n", $h);
check('http: без Clear-Site-Data, сессия и cookie Битрикс стёрты без домена и на домен (без порта)', strpos($hs, 'Clear-Site-Data') === false
	&& strpos($hs, "Set-Cookie: PHPSESSID=deleted; Expires=Thu, 01 Jan 1970 00:00:01 GMT; Max-Age=0; Path=/\n") !== false
	&& strpos($hs, 'Set-Cookie: BITRIX_SM_CLIENT_LOCAL_UIDH=deleted; Expires=Thu, 01 Jan 1970 00:00:01 GMT; Max-Age=0; Path=/; Domain=client.local') !== false
	&& strpos($hs, 'BITRIX_SM_LOGIN=deleted') !== false && strpos($hs, 'Secure') === false, $hs);
check('ключ devrestore, чужие cookie и кривые имена не трогаем', strpos($hs, 'dr_key') === false && strpos($hs, 'OTHER_SITE') === false && strpos($hs, 'bad name') === false, $hs);
$hs = implode("\n", dr_logout_headers([], 'client.local', true, 'BXSESS', 'BITRIX_SM_CLIENT_LOCAL'));
check('https: Clear-Site-Data и Secure; сессия стирается и под своим именем, и как PHPSESSID', strpos($hs, 'Clear-Site-Data: "cookies"') === 0
	&& strpos($hs, 'BXSESS=deleted') !== false && strpos($hs, 'PHPSESSID=deleted') !== false && substr_count($hs, '; Secure') === 4, $hs);

// v2.8: сериализованное и JSON — распаковать, почистить строки, упаковать
$text = static function (string $v, bool $code) use ($c): string {
	return $code ? $c->contacts($v) : $c->clean($v);
};
$contacts = static function (string $v) use ($c): string {
	return $c->contacts($v);
};
$src8 = ['79161234567' => 'ключ-телефон не трогаем', 'note' => 'Звонил Иванов, тел. +7 916 123-45-67, почта ivanov@mail.ru', 'n' => 5, 'f' => 1.5,
	'deep' => ['code' => 'CRM_DEAL', 'url' => '/crm/deal/details/5/', 'b' => true, 'z' => null]];
$ser = serialize($src8);
[$out, $st] = dr_struct_clean($ser, 'ser', $text);
$back = $st === 'changed' ? unserialize((string)$out, ['allowed_classes' => false]) : null;
check('ser: распаковано, почищено, упаковано — длины s:N верные', is_array($back) && strpos($back['note'], '123-45-67') === false
	&& strpos($back['note'], 'ivanov@mail.ru') === false && strpos($back['note'], 'Иванов') === false, (string)$out);
check('ser: ключи, коды, ссылки, числа, bool и null — как были', is_array($back) && $back['79161234567'] === $src8['79161234567']
	&& $back['deep'] === $src8['deep'] && $back['n'] === 5 && $back['f'] === 1.5 && array_keys($back) === array_keys($src8), (string)$out);
[$out, $st] = dr_struct_clean($ser, 'ser', $contacts);
$back = $st === 'changed' ? unserialize((string)$out, ['allowed_classes' => false]) : null;
check('ser в strict: только контакты — имя остаётся, телефон и почта заменены', is_array($back) && strpos($back['note'], 'Звонил Иванов, тел. ') === 0
	&& strpos($back['note'], '123-45-67') === false && strpos($back['note'], 'ivanov@mail.ru') === false, (string)$out);
check('ser: нечего чистить — same и null (строку не пишем)', dr_struct_clean(serialize(['a' => 'CRM_LEAD', 'b' => [1, 2]]), 'ser', $text) === [null, 'same']);
check('ser: обрезанное — broken, не трогаем', dr_struct_clean(substr($ser, 0, -5), 'ser', $text) === [null, 'broken']);
check('ser: длина s:N не совпадает — broken', dr_struct_clean('a:1:{s:4:"note";s:99:"ivanov@mail.ru";}', 'ser', $text) === [null, 'broken']);
check('ser: хвост после значения (unserialize его отбрасывает) и i:007; — broken: упаковка не совпала бы с исходным',
	dr_struct_clean('a:1:{s:1:"t";s:12:"+79161234567";}хвост', 'ser', $text) === [null, 'broken'] && dr_struct_clean('i:007;', 'ser', $text) === [null, 'broken']);
check('ser: b:0; и N; — распаковываются, менять нечего', dr_struct_clean('b:0;', 'ser', $text) === [null, 'same'] && dr_struct_clean('N;', 'ser', $text) === [null, 'same']);
$obj = 'O:8:"CrmThing":1:{s:1:"e";s:14:"ivanov@mail.ru";}';
check('ser: объект класса — упаковывается как был, внутрь не заходим', dr_struct_clean($obj, 'ser', $text) === [null, 'same']);
$j = json_encode(['empty' => new stdClass(), 'list' => [], 'text' => 'Иванов <b>+79161234567</b> & "кавычки"', 'url' => 'https://example.com/a/b?x=1'],
	JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
[$out, $st] = dr_struct_clean($j, 'json', $text);
check('json: {} остаётся {}, [] остаётся [], флаги исходника (\\u-экранирование, \\/) сохранены', $st === 'changed'
	&& strpos((string)$out, '"empty":{}') !== false && strpos((string)$out, '"list":[]') !== false && strpos((string)$out, '\u003Cb\u003E') !== false
	&& strpos((string)$out, '\u0026 \u0022') !== false && strpos((string)$out, '\u0418\u0432') === false && strpos((string)$out, 'https:\/\/example.com\/a\/b?x=1') !== false
	&& strpos((string)$out, '9161234567') === false, (string)$out);
$j = json_encode(['t' => 'Иванов: +79161234567', 'u' => 'https://example.com/x', 'c' => 'lead_12'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
[$out, $st] = dr_struct_clean($j, 'json', $contacts);
check('json без экранирования: кириллица и / остаются как были, ссылка не тронута', $st === 'changed' && strpos((string)$out, '"Иванов: +7') !== false
	&& strpos((string)$out, '"https://example.com/x"') !== false && strpos((string)$out, '"lead_12"') !== false && strpos((string)$out, '9161234567') === false, (string)$out);
check('json: большое целое не воспроизводится — broken', dr_struct_clean('{"id":12345678901234567890,"p":"+79161234567"}', 'json', $text) === [null, 'broken']);
check('json: пробелы и переносы не как у json_encode — broken', dr_struct_clean("{\n  \"p\": \"+79161234567\"\n}", 'json', $text) === [null, 'broken']);
check('json: не JSON — broken; null и число — same', dr_struct_clean('Иванов +79161234567', 'json', $text) === [null, 'broken']
	&& dr_struct_clean('null', 'json', $text) === [null, 'same'] && dr_struct_clean('12', 'json', $text) === [null, 'same']);
check('формат по значению: правило json, а значение сериализованное — чистится', dr_struct_clean($ser, 'json', $text)[1] === 'changed'
	&& dr_struct_clean($j, 'ser', $contacts)[1] === 'changed');
$seen = [];
$probe = static function (string $v, bool $code) use (&$seen): string {
	$seen[$v] = $code;
	return $v;
};
dr_struct_clean(serialize(['lead_12', '/crm/deal/details/5/', 'https://x.example/?a=Иванов', '79161234567', 'Иван Петров', 'a@b.ru']), 'ser', $probe);
check('коды и ссылки — только контакты, текст — по словарю', $seen === ['lead_12' => true, '/crm/deal/details/5/' => true, 'https://x.example/?a=Иванов' => true,
	'79161234567' => true, 'Иван Петров' => false, 'a@b.ru' => false], json_encode($seen, JSON_UNESCAPED_UNICODE));
$inner = serialize(['cfg' => json_encode(['mail' => 'petrov@mail.ru', 'o' => new stdClass()]), 's' => serialize(['tel' => '+7 916 765-43-21'])]);
[$out, $st] = dr_struct_clean($inner, 'ser', $contacts);
$back = $st === 'changed' ? unserialize((string)$out, ['allowed_classes' => false]) : null;
$cfg = is_array($back) ? json_decode($back['cfg']) : null;
$s2 = is_array($back) ? @unserialize($back['s'], ['allowed_classes' => false]) : null;
check('вложенные JSON и сериализованное внутри строки — чистятся как структура, остаются целыми', $cfg instanceof stdClass
	&& strpos($back['cfg'], 'petrov@mail.ru') === false && strpos($back['cfg'], '"o":{}') !== false
	&& is_array($s2) && strpos($s2['tel'], '765-43-21') === false, (string)$out);

// v2.9: быстрый импорт — адрес сервера, файл параметров клиента, тома и операторы дампа, пропуск данных
check('адрес сервера: порт, сокет, постоянное соединение', dr_db_host('127.0.0.1:3399') === ['127.0.0.1', 3399, null]
	&& dr_db_host('localhost:/var/run/mysqld/mysqld.sock') === ['localhost', null, '/var/run/mysqld/mysqld.sock'] && dr_db_host('p:db.local') === ['db.local', null, null]);
$cnf = dr_mysql_cnf(['host' => '127.0.0.1:3399', 'user' => 'root', 'pass' => 'pa"ss\\word']);
check('файл параметров mysql: пароль в кавычках с экранированием, порт', $cnf === "[client]\nuser=\"root\"\npassword=\"pa\\\"ss\\\\word\"\nhost=\"127.0.0.1\"\nport=3399\n", $cnf);
check('файл параметров mysql: localhost без сокета в настройках — сокет, которым ходит mysqli',
	strpos(dr_mysql_cnf(['host' => 'localhost', 'user' => 'u', 'pass' => ''], '/tmp/my.sock'), "socket=\"/tmp/my.sock\"\n") !== false
	&& strpos(dr_mysql_cnf(['host' => '127.0.0.1', 'user' => 'u', 'pass' => ''], '/tmp/my.sock'), 'socket') === false);
$dir = sys_get_temp_dir() . '/dr-unit-' . getmypid();
@mkdir($dir);
$dump = "-- Дамп резервной копии\nDROP TABLE IF EXISTS `b_event_log`;\nCREATE TABLE `b_event_log` (\n  `ID` int NOT NULL,\n  PRIMARY KEY (`ID`)\n);\n"
	. "INSERT INTO `b_event_log` VALUES ('1','x; y'),('2','z');\nINSERT INTO `b_user` VALUES ('1','admin');\n# комментарий\nINSERT INTO `B_Search_Content_Stem` VALUES ('1');\nхвост без точки с запятой";
// тома режутся посреди строки и посреди оператора — как поток они должны склеиться
file_put_contents("$dir/d.sql", substr($dump, 0, 40));
file_put_contents("$dir/d.sql.1", substr($dump, 40, 100));
file_put_contents("$dir/d.sql.2", substr($dump, 140));
file_put_contents("$dir/d.sql.4", 'не тот том');
$parts = dr_dump_parts("$dir/d.sql");
check('тома дампа: .sql, .sql.1, .sql.2 — до первого пропуска', $parts === ["$dir/d.sql", "$dir/d.sql.1", "$dir/d.sql.2"], implode(', ', $parts));
$rd = new DrDumpReader($parts);
$sts = [];
while (($st = $rd->next()) !== null)
{
	$sts[] = $st;
}
check('операторы дампа по правилу restore.php: строка с «;» в конце закрывает оператор, комментарии — к следующему', count($sts) === 5
	&& $sts[0] === "-- Дамп резервной копии\nDROP TABLE IF EXISTS `b_event_log`;\n" && $sts[1] === "CREATE TABLE `b_event_log` (\n  `ID` int NOT NULL,\n  PRIMARY KEY (`ID`)\n);\n"
	&& $sts[2] === "INSERT INTO `b_event_log` VALUES ('1','x; y'),('2','z');\n" && $sts[4] === "# комментарий\nINSERT INTO `B_Search_Content_Stem` VALUES ('1');\n", json_encode($sts, JSON_UNESCAPED_UNICODE));
check('позиция — все байты всех томов, хвост без «;» отброшен и посчитан', $rd->pos === strlen($dump) && $rd->tail === strlen('хвост без точки с запятой'), $rd->pos . ' / ' . $rd->tail);
check('начало оператора: вид, таблица, смещение ключевого слова', dr_dump_head($sts[0]) === ['drop', 'b_event_log', strlen("-- Дамп резервной копии\n")]
	&& dr_dump_head($sts[1]) === ['create', 'b_event_log', 0] && dr_dump_head($sts[4]) === ['insert', 'b_search_content_stem', strlen("# комментарий\n")]
	&& dr_dump_head("CREATE TABLE IF NOT EXISTS b_x (a int);\n") === ['create', 'b_x', 0] && dr_dump_head("SET NAMES 'utf8';\n") === null);
array_map('unlink', glob("$dir/*"));
rmdir($dir);
$rules = [
	['group' => 'Логи', 'truncate' => ['b_event_log', 'b_perf_%']],
	['group' => 'Поиск', 'truncate' => ['b_search_content%']],
	['group' => 'Выключено по умолчанию', 'default_off' => true, 'truncate' => ['b_sale_order_change']],
	['group' => 'Обновление', 'table' => 'b_user', 'set' => []],
];
$skip = dr_import_skip_patterns($rules);
$rx = dr_like_rx($skip);
check('пропуск данных при импорте: группы очистки, кроме выключенных по умолчанию; шаблоны LIKE без учёта регистра',
	$skip === ['b_event_log', 'b_perf_%', 'b_search_content%'] && preg_match($rx, 'b_event_log') && preg_match($rx, 'b_search_content_stem') && preg_match($rx, 'B_PERF_HIT')
	&& !preg_match($rx, 'b_event_log_old') && !preg_match($rx, 'b_sale_order_change') && !preg_match($rx, 'b_user') && dr_like_rx([]) === null, (string)$rx);
check('PHP для командной строки рядом с php-fpm и php-cgi', dr_php_cli_candidates('/usr/sbin/php-fpm8.2', false) === ['/usr/sbin/php', '/usr/sbin/php8.2', '/usr/bin/php8.2']
	&& dr_php_cli_candidates('D:/php/php-cgi.exe', true) === ['D:/php/php.exe'] && dr_php_cli_candidates('/usr/bin/php8.3', false) === ['/usr/bin/php8.3', '/usr/bin/php']);
check('длительность для отчёта', dr_duration(0.25) === '250 мс' && dr_duration(12.4) === '12 с' && dr_duration(245) === '4 мин 05 с' && dr_duration(3725) === '1 ч 02 мин');
[$stages, $top] = dr_stage_times([['kind' => 'update', 'label' => 'A', 'sec' => 3], ['kind' => 'clean', 'label' => 'B', 'sec' => 10], ['kind' => 'leak', 'label' => 'C', 'sec' => 7],
	['kind' => 'leak_names', 'label' => 'D', 'sec' => 1], ['kind' => 'cleanup', 'label' => 'E', 'sec' => 2], ['kind' => 'kernel', 'label' => 'F', 'sec' => 4], ['kind' => 'ops', 'label' => 'G']]);
check('этапы и пять самых долгих шагов', array_values($stages) === [13.0, 8.0, 6.0] && array_column($top, 0) === ['B', 'C', 'F', 'A', 'E'], json_encode([$stages, $top], JSON_UNESCAPED_UNICODE));

echo $fails ? "$fails из $total ПРОВАЛЕНО\n" : "OK: $total проверок\n";
exit($fails ? 1 : 0);
