<?php

namespace App\Demo;

class Menu
{
    /**
     * Prices live on the server only; the checkout endpoint never trusts client prices.
     *
     * @return array<string, array{id: string, name: string, description: string, price_cents: int}>
     */
    public static function all(): array
    {
        $items = [
            ['id' => 'burger', 'name' => 'Smash Burger', 'description' => 'Double patty, cheddar, pickles.', 'price_cents' => 1099],
            ['id' => 'pizza', 'name' => 'Margherita Pizza', 'description' => 'San Marzano tomato, mozzarella, basil.', 'price_cents' => 1299],
            ['id' => 'salad', 'name' => 'Greek Salad', 'description' => 'Feta, olives, cucumber, tomato.', 'price_cents' => 899],
            ['id' => 'fries', 'name' => 'Truffle Fries', 'description' => 'Parmesan and truffle oil.', 'price_cents' => 599],
            ['id' => 'lemonade', 'name' => 'House Lemonade', 'description' => 'Fresh lemon, mint.', 'price_cents' => 399],
        ];

        $keyed = [];
        foreach ($items as $item) {
            $keyed[$item['id']] = $item;
        }

        return $keyed;
    }
}
