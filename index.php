<?php

define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_CHECK', true);

ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
$iblockLoaded = \Bitrix\Main\Loader::includeModule('iblock');
ob_end_clean();

if (!$iblockLoaded) {
    http_response_code(500);
    exit;
}

function loadActiveFeedElements(int $iblockId, array $fields, array $propertyCodes): array
{
    $filter = [
        'IBLOCK_ID' => $iblockId,
        'ACTIVE' => 'Y',
        'ACTIVE_DATE' => 'Y',
        'CHECK_PERMISSIONS' => 'Y',
    ];
    $rows = CIBlockElement::GetList(
        ['SORT' => 'ASC', 'ID' => 'ASC'],
        $filter,
        false,
        false,
        $fields
    );

    $elements = [];
    while ($element = $rows->Fetch()) {
        $element['PROPERTIES'] = [];
        $elements[$element['ID']] = $element;
    }

    if ($elements) {
        CIBlockElement::GetPropertyValuesArray(
            $elements,
            $iblockId,
            $filter + ['ID' => array_keys($elements)],
            ['CODE' => $propertyCodes],
            ['GET_RAW_DATA' => 'Y']
        );
    }

    return $elements;
}

function feedPropertyText($value): string
{
    if (is_array($value)) {
        return $value['TEXT'] ?? implode("\n", array_map('feedPropertyText', $value));
    }

    return (string) $value;
}

function escapeFeedXml(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE | ENT_DISALLOWED, 'UTF-8');
}

$doctors = loadActiveFeedElements(33, ['ID', 'CODE', 'NAME'], ['LAST_NAME', 'SECOND_NAME', 'TEXT_BLOCK', 'EXPERIENCE']);
$services = loadActiveFeedElements(65, ['ID', 'NAME'], ['NUMBER']);
$date = (new DateTimeImmutable('now', new DateTimeZone('Europe/Moscow')))->format('Y-m-d H:i');

header('Content-Type: application/xml; charset=UTF-8');

echo <<<XML
<shop version="2.0" date="$date">
    <name>ЛДЦ «Казанская клиника»</name>
    <company>ООО Медворк</company>
    <url>https://kazan-clinic.ru</url>
    <email>mail@kazan-clinic.ru</email>
    <picture>https://kazan-clinic.ru/upload/iblock/951/wn2ogqi4jayvkpuiw7teuwgytoahv64t/logo.png</picture>
    <doctors>
XML;

foreach ($doctors as $doctor) {
    $properties = $doctor['PROPERTIES'];
    $code = (string) $doctor['CODE'];
    $fullName = $doctor['NAME'];
    $nameParts = preg_split('/\s+/u', $fullName, -1, PREG_SPLIT_NO_EMPTY);
    $firstName = $nameParts[1] ?? '';
    $surname = trim(feedPropertyText($properties['LAST_NAME']['VALUE'] ?? ''));
    $patronymic = trim(feedPropertyText($properties['SECOND_NAME']['VALUE'] ?? ''));
    if ($patronymic === '') {
        $patronymic = $nameParts[2] ?? '';
    }

    $description = feedPropertyText($properties['TEXT_BLOCK']['VALUE'] ?? '');
    $description = strip_tags(preg_replace('~<br(?:\s[^>]*)?\s*/?>|</(?:li|h4|p)\s*>~i', "\n", $description));
    $experience = feedPropertyText($properties['EXPERIENCE']['VALUE'] ?? '');

    $fields = [
        'name' => $fullName,
        'url' => 'https://kazan-clinic.ru/portfolio/' . rawurlencode($code) . '/',
        'description' => $description,
        'internal_id' => $doctor['ID'],
        'first_name' => $firstName,
        'surname' => $surname !== '' ? $surname : ($nameParts[0] ?? ''),
        'patronymic' => $patronymic,
        'experience_years' => preg_replace('/[^0-9]/', '', $experience),
    ];

    if ($patronymic === '') {
        unset($fields['patronymic']);
    }

    echo "\n        <doctor id=\"" . escapeFeedXml($code) . "\">\n";
    foreach ($fields as $tag => $value) {
        echo "            <$tag>" . escapeFeedXml($value) . "</$tag>\n";
    }
    echo '        </doctor>';
}

echo "\n";
echo <<<'XML'
    </doctors>
    <clinics>
        <clinic id="clinic_lushnikova">
            <url>https://kazan-clinic.ru</url>
            <picture>https://kazan-clinic.ru/upload/iblock/951/wn2ogqi4jayvkpuiw7teuwgytoahv64t/logo.png</picture>
            <name>Казанская клиника на Лушникова</name>
            <city>г. Казань</city>
            <address>ул. Лушникова, д. 10</address>
            <email>mail@kazan-clinic.ru</email>
            <phone>+78432101106</phone>
            <internal_id>1</internal_id>
            <company_id>1</company_id>
        </clinic>
        <clinic id="clinic_glushko">
            <url>https://kazan-clinic.ru</url>
            <picture>https://kazan-clinic.ru/upload/iblock/951/wn2ogqi4jayvkpuiw7teuwgytoahv64t/logo.png</picture>
            <name>Казанская клиника на Глушко</name>
            <city>г. Казань</city>
            <address>ул. Ак. Глушко, д. 21А</address>
            <email>mail@kazan-clinic.ru</email>
            <phone>+78432101106</phone>
            <internal_id>2</internal_id>
            <company_id>2</company_id>
        </clinic>
    </clinics>
    <services>
XML;

foreach ($services as $service) {
    $id = escapeFeedXml($service['ID']);
    $name = escapeFeedXml($service['NAME']);
    $govId = trim(feedPropertyText($service['PROPERTIES']['NUMBER']['VALUE'] ?? ''));

    echo "\n        <service id=\"service_$id\">\n";
    echo "            <name>$name</name>\n";
    if ($govId !== '') {
        echo '            <gov_id>' . escapeFeedXml($govId) . "</gov_id>\n";
    }
    echo "            <internal_id>$id</internal_id>\n";
    echo '        </service>';
}

echo "\n";
echo <<<'XML'
    </services>
</shop>
XML;
