<?php
/**
 * Разовый скрипт: сбрасывает у товаров индивидуальные флаги
 * «Включить количественный учёт» и «Разрешить покупку при отсутствии товара»
 * на «по умолчанию» (берутся из настроек модуля «Торговый каталог»)
 * и пересчитывает поле «Доступен» (AVAILABLE).
 *
 * Запуск: из «Настройки → Инструменты → Командная PHP-строка»
 * (вставить содержимое без первой строки) или положить в корень сайта,
 * открыть под администратором и сразу удалить.
 *
 * Сначала запустите с $dryRun = true и посмотрите список.
 */

$dryRun = true;

if (!defined('B_PROLOG_INCLUDED')) {
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
}
global $USER;
if (!$USER->IsAdmin()) {
    die('Только для администратора');
}

\Bitrix\Main\Loader::includeModule('catalog');

$rows = \Bitrix\Catalog\ProductTable::getList([
    'select' => ['ID', 'QUANTITY', 'QUANTITY_TRACE', 'CAN_BUY_ZERO', 'AVAILABLE'],
    'filter' => [
        'LOGIC' => 'OR',
        '!=CAN_BUY_ZERO' => 'D',
        '!=QUANTITY_TRACE' => 'D',
    ],
]);

$count = 0;
echo '<pre>';
while ($row = $rows->fetch()) {
    $count++;
    printf(
        "ID %d: остаток %s, учёт=%s, покупка при 0=%s, доступен=%s\n",
        $row['ID'], $row['QUANTITY'], $row['QUANTITY_TRACE'], $row['CAN_BUY_ZERO'], $row['AVAILABLE']
    );
    if (!$dryRun) {
        $result = \Bitrix\Catalog\Model\Product::update(
            $row['ID'],
            ['QUANTITY_TRACE' => 'D', 'CAN_BUY_ZERO' => 'D']
        );
        if (!$result->isSuccess()) {
            echo '  ошибка: ' . implode('; ', $result->getErrorMessages()) . "\n";
        }
    }
}
echo "\nНайдено товаров с индивидуальными настройками: $count" . ($dryRun ? ' (dry run, ничего не изменено)' : '') . "\n";
echo '</pre>';
