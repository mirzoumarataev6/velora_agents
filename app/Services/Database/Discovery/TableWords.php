<?php

namespace App\Services\Database\Discovery;

use App\Services\Dataset\ColumnMapper;

final class TableWords
{
    public const PRODUCT = [

        'product', 'products', 'item', 'items', 'good', 'goods', 'ware', 'wares',
        'catalog', 'catalogue', 'merchandise', 'article', 'articles', 'sku', 'skus',
        'nomenclature', 'assortment',

        'товар', 'товары', 'товара', 'товаров', 'продукт', 'продукты', 'продукция',
        'номенклатура', 'каталог', 'изделие', 'изделия', 'ассортимент',

        'мол', 'молҳо', 'молхо', 'маҳсулот', 'махсулот', 'ассортимент',

        'tovar', 'tovary', 'tovarlar', 'produkt', 'produkty', 'produktsiya',
        'nomenklatura', 'katalog', 'izdelie', 'izdeliya', 'mahsulot', 'mol',
    ];

    public const CATEGORY = [
        'category', 'categories', 'group', 'groups', 'section', 'sections',
        'collection', 'collections', 'rubric', 'rubrics',
        'категория', 'категории', 'группа', 'группы', 'раздел', 'разделы',
        'коллекция', 'коллекции', 'рубрика',
        'гурӯҳ', 'гурух', 'гурӯҳҳо', 'категорияҳо',
        'kategoriya', 'kategorii', 'gruppa', 'gruppy', 'razdel', 'kollektsiya',
    ];

    public const STOCK = [
        'stock', 'stocks', 'inventory', 'warehouse', 'warehouses', 'balance',
        'balances', 'availability', 'quantities',
        'остаток', 'остатки', 'склад', 'склады', 'наличие', 'запас', 'запасы',
        'мавҷудӣ', 'мавчуди', 'анбор',
        'ostatok', 'ostatki', 'sklad', 'zapas', 'anbor',
    ];

    public const SERVICE = [
        'migration', 'migrations', 'session', 'sessions', 'cache', 'caches',
        'job', 'jobs', 'queue', 'queues', 'log', 'logs', 'audit', 'history',
        'backup', 'backups', 'temp', 'tmp', 'archive', 'archives',
        'notification', 'notifications', 'token', 'tokens', 'permission',
        'permissions', 'role', 'roles', 'setting', 'settings', 'config',
        'translation', 'translations', 'seo', 'redirect', 'redirects', 'banner',
        'banners', 'menu', 'menus', 'comment', 'comments', 'review', 'reviews',
        'rating', 'ratings', 'wishlist', 'favorites', 'subscriber', 'subscribers',
        'newsletter', 'statistics', 'views', 'visits', 'clicks',
        'миграции', 'сессии', 'логи', 'история', 'настройки', 'отзывы',
    ];

    public const TRANSACTION = [
        'order', 'orders', 'cart', 'carts', 'basket', 'baskets', 'invoice',
        'invoices', 'payment', 'payments', 'transaction', 'transactions',
        'receipt', 'receipts', 'shipment', 'shipments', 'delivery', 'deliveries',
        'refund', 'refunds', 'checkout',
        'заказ', 'заказы', 'корзина', 'корзины', 'счет', 'счёт', 'счета',
        'оплата', 'платежи', 'продажа', 'продажи', 'закупка', 'закупки',
        'поставка', 'поставки', 'чек', 'чеки',
        'zakaz', 'zakazy', 'korzina', 'schet', 'oplata', 'prodazha', 'postavka',
    ];

    public static function of(string $table): array
    {
        $name = str_contains($table, '.') ? substr($table, strrpos($table, '.') + 1) : $table;

        $words = explode(' ', ColumnMapper::fold($name));

        return array_values(array_filter($words, fn (string $word) => $word !== ''));
    }

    public static function any(array $words, array $vocabulary): bool
    {
        foreach ($words as $word) {
            foreach ($vocabulary as $entry) {

                if ($word === $entry || $word === $entry.'s' || $entry === $word.'s') {
                    return true;
                }
            }
        }

        return false;
    }

    public static function describes(string $table, array $vocabulary): bool
    {
        return self::any(self::of($table), $vocabulary);
    }
}
