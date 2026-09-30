<?php
/**
 * Защита от оформления заказа на товар с нулевым (недостаточным) остатком.
 *
 * Подключение: в /local/php_interface/init.php добавить
 *     require_once __DIR__ . '/include/order_stock_guard.php';
 *
 * Срабатывает только на НОВЫХ заказах (сохранение из sale.order.ajax,
 * «Купить в 1 клик», REST и т.п.). Если остаток хотя бы одной позиции
 * меньше заказанного количества — заказ не сохраняется, покупатель видит ошибку.
 */

use Bitrix\Catalog\ProductTable;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Event;
use Bitrix\Main\EventManager;
use Bitrix\Main\EventResult;
use Bitrix\Main\Loader;
use Bitrix\Sale\Order;
use Bitrix\Sale\ResultError;

/**
 * true  — блокировать ВСЕГДА, если остаток < заказанного (игнорируя флаг
 *         «Разрешить покупку при отсутствии товара» в карточке/настройках).
 * false — блокировать только товары, у которых с учётом настроек модуля
 *         включён количественный учёт и запрещена покупка при отсутствии.
 */
const ORDER_STOCK_GUARD_STRICT = true;

EventManager::getInstance()->addEventHandler(
    'sale',
    'OnSaleOrderBeforeSaved',
    'orderStockGuardOnBeforeSaved'
);

function orderStockGuardOnBeforeSaved(Event $event)
{
    /** @var Order $order */
    $order = $event->getParameter('ENTITY');
    if (!$order instanceof Order || !$order->isNew() || !Loader::includeModule('catalog')) {
        return null;
    }

    // Суммируем количество по товару (одна позиция может встречаться несколько раз).
    $need = [];
    $names = [];
    foreach ($order->getBasket() as $item) {
        if ($item->getField('MODULE') !== 'catalog') {
            continue;
        }
        $productId = (int)$item->getProductId();
        $need[$productId] = ($need[$productId] ?? 0) + (float)$item->getQuantity();
        $names[$productId] = $item->getField('NAME');
    }
    if (!$need) {
        return null;
    }

    $defaultTrace = Option::get('catalog', 'default_quantity_trace', 'N') === 'Y';
    $defaultCanBuyZero = Option::get('catalog', 'default_can_buy_zero', 'N') === 'Y';

    $errors = [];
    $rows = ProductTable::getList([
        'select' => ['ID', 'QUANTITY', 'QUANTITY_TRACE', 'CAN_BUY_ZERO'],
        'filter' => ['@ID' => array_keys($need)],
    ]);
    while ($row = $rows->fetch()) {
        $id = (int)$row['ID'];

        if (!ORDER_STOCK_GUARD_STRICT) {
            // 'D' = «по умолчанию» → берём значение из настроек модуля.
            $trace = $row['QUANTITY_TRACE'] === 'D' ? $defaultTrace : $row['QUANTITY_TRACE'] === 'Y';
            $canBuyZero = $row['CAN_BUY_ZERO'] === 'D' ? $defaultCanBuyZero : $row['CAN_BUY_ZERO'] === 'Y';
            if (!$trace || $canBuyZero) {
                continue;
            }
        }

        $available = (float)$row['QUANTITY'];
        if ($available < $need[$id]) {
            $errors[] = $available > 0
                ? sprintf('«%s»: в наличии только %s шт.', $names[$id], $available)
                : sprintf('«%s»: нет в наличии.', $names[$id]);
        }
    }

    if (!$errors) {
        return null;
    }

    return new EventResult(
        EventResult::ERROR,
        new ResultError(
            'Недостаточно товара на складе. ' . implode(' ', $errors) . ' Уберите позиции из корзины или измените количество.',
            'ORDER_STOCK_GUARD'
        ),
        'sale'
    );
}
