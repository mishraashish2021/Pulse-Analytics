<?php

namespace App\Http\Controllers;

use App\Analytics\ServerTracker;
use App\Demo\Menu;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DemoController extends Controller
{
    public function home()
    {
        return view('demo.home');
    }

    public function menu()
    {
        return view('demo.menu', ['items' => Menu::all()]);
    }

    public function about()
    {
        return view('demo.about');
    }

    public function order(Order $order)
    {
        return view('demo.order', ['order' => $order]);
    }

    public function store(Request $request, ServerTracker $tracker): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:190'],
            'items' => ['required', 'array', 'min:1', 'max:30'],
            'items.*.id' => ['required', 'string'],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:20'],
        ]);

        $menu = Menu::all();
        $lines = [];
        $total = 0;
        $quantity = 0;

        foreach ($data['items'] as $line) {
            $item = $menu[$line['id']] ?? null;

            if (! $item) {
                throw ValidationException::withMessages(['items' => 'Unknown menu item.']);
            }

            $lines[] = ['id' => $item['id'], 'name' => $item['name'], 'qty' => $line['qty'], 'price_cents' => $item['price_cents']];
            $total += $item['price_cents'] * $line['qty'];
            $quantity += $line['qty'];
        }

        // Pseudonymous customer id: lets analytics link the browser visitor and the server
        // event without ever sending the email address to the analytics system.
        $customerId = 'cust_'.substr(hash('sha256', strtolower($data['email'])), 0, 16);

        $order = Order::create([
            'public_id' => (string) Str::uuid(),
            'customer_id' => $customerId,
            'customer_name' => $data['name'],
            'items' => $lines,
            'total_cents' => $total,
        ]);

        // Business event from the server. The cookie written by the browser SDK ties it
        // to the same anonymous visitor who browsed the menu.
        $tracker->track('order_created', [
            'order_id' => $order->public_id,
            'total_cents' => $total,
            'item_count' => $quantity,
            'currency' => 'USD',
        ], $request->cookie('pa_aid'), $customerId);

        return response()->json([
            'redirect' => route('demo.order', $order),
            'customer_id' => $customerId,
        ], 201);
    }
}
