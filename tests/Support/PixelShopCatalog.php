<?php

namespace Tests\Support;

use App\Models\Agent;
use App\Models\Dataset;
use App\Models\DatasetProduct;

/**
 * The live PixelShop catalog, row for row and in its real file order: 22
 * products, and — the point — nothing priced between 6000 and 7000, with a
 * laptop at 5999 (one somoni under that floor) sitting at position 11, well
 * after three MacBooks at positions 0-2.
 */
trait PixelShopCatalog
{
    /** @return array<int, array{0: string, 1: string, 2: int, 3: string}> name, category, price, description */
    protected static function pixelShopRows(): array
    {
        return [
            ['Macbook Neo 13', 'Ноутбук', 8199, 'Процессор Apple, ОЗУ 8GB, память 256GB'],
            ['Macbook Neo 13', 'Ноутбук', 8900, 'Процессор Apple, ОЗУ 8GB, память 512GB'],
            ['Macbook Air 15', 'Ноутбук', 11999, 'Процессор Apple M4, ОЗУ 16GB, память 256GB'],
            ['Macbook Air 13.6', 'Ноутбук', 10499, 'Процессор Apple M4, ОЗУ 16GB, память 256GB'],
            ['Macbook Pro 14', 'Ноутбук', 17999, 'Процессор Apple M5, ОЗУ 16GB, память 512GB'],
            ['Macbook Pro 14', 'Ноутбук (офисный)', 24999, 'Процессор Apple M5, ОЗУ 24GB, память 1TB'],
            ['15 15.6" FHD', 'Ноутбук', 4699, 'Процессор Intel Core i3-N305, ОЗУ 8GB, память 256GB'],
            ['15 15.6" FHD', 'Ноутбук', 7999, 'Процессор Intel Core Ultra 7 255U, ОЗУ 16GB, память 512GB'],
            ['OMEN 16', 'Ноутбук (игровой)', 14999, 'Процессор Intel Core Ultra 7 255H, ОЗУ 16GB, память 1TB'],
            ['27f LED', 'Монитор', 2199, 'Монитор 27"'],
            ['24f LED', 'Монитор', 1699, 'Монитор 24"'],
            ['15 15.6" FHD', 'Ноутбук', 5999, 'Процессор Intel Core i5-1334U, ОЗУ 8GB, память 512GB'],
            ['14 Plus', 'Ноутбук', 9999, 'Процессор Intel Core Ultra 7 256V, ОЗУ 16GB, память 1TB'],
            ['Vostro 3520', 'Ноутбук', 9799, 'Процессор Intel Core i7-1255U, ОЗУ 16GB, память 512GB'],
            ['Zenbook 14 OLED', 'Ноутбук', 9999, 'Процессор Intel Core Ultra 7 255H, ОЗУ 16GB, память 512GB'],
            ['ROG STRIX G18', 'Ноутбук (игровой)', 22999, 'Процессор Intel Core i9-14900HX, ОЗУ 32GB'],
            ['TUF Gaming 6 Pro', 'Ноутбук (игровой)', 16999, 'Процессор Intel Core Ultra 9 275HX, ОЗУ 32GB'],
            ['IdeaPad 1', 'Ноутбук', 2699, 'Процессор Intel N100, ОЗУ 4GB, память 128GB'],
            ['Legion 5', 'Ноутбук (игровой)', 17999, 'Процессор Intel Core i9-14900HX, ОЗУ 32GB, память 1TB'],
            ['Legion Pro 5', 'Ноутбук (игровой)', 20999, 'Процессор Intel Core Ultra 9 275HX, ОЗУ 32GB'],
            ['Katana 15', 'Ноутбук (игровой)', 14999, 'Процессор Intel Core i7-14650HX, ОЗУ 16GB, память 1TB'],
            ['Canvio Basics 1TB', 'Комплектующие (внешний HDD)', 899, 'Внешний HDD, память 1TB'],
        ];
    }

    protected function seedPixelShop(Agent $agent): void
    {
        $rows = self::pixelShopRows();
        $dataset = Dataset::factory()->for($agent)->create(['row_count' => count($rows)]);

        foreach ($rows as $position => [$name, $category, $price, $description]) {
            DatasetProduct::factory()->create([
                'agent_id' => $agent->id,
                'dataset_id' => $dataset->id,
                'position' => $position,
                'name' => $name,
                'category' => $category,
                'price' => $price,
                'stock' => null,
                'description' => $description,
            ]);
        }
    }

    /** A presented price back as a number, whatever formatting it was given. */
    protected static function priceOf(array $row): float
    {
        return (float) preg_replace('/[^\d.]/', '', (string) $row['price']);
    }
}
