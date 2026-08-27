<?php

namespace App\Services\Database\Discovery;

use App\Services\Dataset\ColumnMapper;

/**
 * What a table's NAME says it is, in the three languages the platform serves.
 *
 * The vocabularies were the scorer's alone while only the scorer read names.
 * They are shared now because the JOIN PLANNER has to read them too: it may
 * aggregate `stocks` into a total per product, and `order_items` has exactly the
 * same shape — a product reference and a quantity — while meaning something a
 * shop must never answer "in stock: 240" from. Shape cannot tell those apart.
 * The name can, and there must be one list of names rather than two that drift.
 *
 * Matching is on WHOLE WORDS, never substrings: `wholesale_products` is a
 * catalog, not a sale, and `catalog_view` is a view of the catalog while
 * `product_views` is a hit counter.
 */
final class TableWords
{
    /**
     * Words that say "this table is the product catalog", plus the latin
     * transliterations that turn up in real exports.
     *
     * @var string[]
     */
    public const PRODUCT = [
        // en
        'product', 'products', 'item', 'items', 'good', 'goods', 'ware', 'wares',
        'catalog', 'catalogue', 'merchandise', 'article', 'articles', 'sku', 'skus',
        'nomenclature', 'assortment',
        // ru
        'товар', 'товары', 'товара', 'товаров', 'продукт', 'продукты', 'продукция',
        'номенклатура', 'каталог', 'изделие', 'изделия', 'ассортимент',
        // tg
        'мол', 'молҳо', 'молхо', 'маҳсулот', 'махсулот', 'ассортимент',
        // transliterated
        'tovar', 'tovary', 'tovarlar', 'produkt', 'produkty', 'produktsiya',
        'nomenklatura', 'katalog', 'izdelie', 'izdeliya', 'mahsulot', 'mol',
    ];

    /** @var string[] */
    public const CATEGORY = [
        'category', 'categories', 'group', 'groups', 'section', 'sections',
        'collection', 'collections', 'rubric', 'rubrics',
        'категория', 'категории', 'группа', 'группы', 'раздел', 'разделы',
        'коллекция', 'коллекции', 'рубрика',
        'гурӯҳ', 'гурух', 'гурӯҳҳо', 'категорияҳо',
        'kategoriya', 'kategorii', 'gruppa', 'gruppy', 'razdel', 'kollektsiya',
    ];

    /** @var string[] */
    public const STOCK = [
        'stock', 'stocks', 'inventory', 'warehouse', 'warehouses', 'balance',
        'balances', 'availability', 'quantities',
        'остаток', 'остатки', 'склад', 'склады', 'наличие', 'запас', 'запасы',
        'мавҷудӣ', 'мавчуди', 'анбор',
        'ostatok', 'ostatki', 'sklad', 'zapas', 'anbor',
    ];

    /**
     * Tables that exist to run the application rather than to describe what it
     * sells. Plurals where the singular would be too eager.
     *
     * @var string[]
     */
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

    /**
     * Tables that record what was SOLD, and the reason this class is shared.
     * `order_items` has a product reference and a quantity; adding those up and
     * calling the total "stock" would tell a customer that a sold-out product
     * has 240 in the warehouse.
     *
     * @var string[]
     */
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

    /**
     * A table name as comparable words: `oc_product_description` becomes
     * ['oc', 'product', 'description'], and a schema qualifier is dropped.
     *
     * @return string[]
     */
    public static function of(string $table): array
    {
        $name = str_contains($table, '.') ? substr($table, strrpos($table, '.') + 1) : $table;

        $words = explode(' ', ColumnMapper::fold($name));

        return array_values(array_filter($words, fn (string $word) => $word !== ''));
    }

    /**
     * @param  string[]  $words
     * @param  string[]  $vocabulary
     */
    public static function any(array $words, array $vocabulary): bool
    {
        foreach ($words as $word) {
            foreach ($vocabulary as $entry) {
                // A trailing "s" is tolerated in both directions so a vocabulary
                // does not have to spell every latin plural twice.
                if ($word === $entry || $word === $entry.'s' || $entry === $word.'s') {
                    return true;
                }
            }
        }

        return false;
    }

    /** Whether the table's name itself says this, without going through of(). */
    public static function describes(string $table, array $vocabulary): bool
    {
        return self::any(self::of($table), $vocabulary);
    }
}
