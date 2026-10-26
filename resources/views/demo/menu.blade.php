@extends('demo.layout')

@section('title', 'Menu')

@section('content')
    <h1>Menu</h1>
    <div style="display:grid; grid-template-columns: 1fr 320px; gap:24px; align-items:start;">
        <div id="menu" style="display:grid; gap:12px;"></div>

        <aside class="card">
            <h3 style="margin-top:0">Your order</h3>
            <div id="cart" class="muted">Your cart is empty.</div>
            <p><strong>Total: <span id="total">₹0.00</span></strong></p>
            <div style="display:grid; gap:8px;">
                <input id="name" placeholder="Your name" autocomplete="name">
                <input id="email" type="email" placeholder="Email" autocomplete="email">
                <button id="place" disabled>Place order</button>
                <div id="error" style="color:#b91c1c; font-size:14px;"></div>
            </div>
        </aside>
    </div>
@endsection

@push('scripts')
<script>
    const items = @json($items);
    const cart = {};
    const money = (cents) => '₹' + (cents / 100).toFixed(2);
    const $ = (id) => document.getElementById(id);

    pulse.track('menu_viewed', { item_count: Object.keys(items).length });

    function renderMenu() {
        const root = $('menu');
        Object.values(items).forEach((item) => {
            const card = document.createElement('div');
            card.className = 'card';
            card.style.display = 'flex';
            card.style.justifyContent = 'space-between';
            card.style.alignItems = 'center';

            const text = document.createElement('div');
            const title = document.createElement('strong');
            title.textContent = item.name + ' · ' + money(item.price_cents);
            const desc = document.createElement('div');
            desc.className = 'muted';
            desc.textContent = item.description;
            text.append(title, desc);

            const add = document.createElement('button');
            add.className = 'secondary';
            add.textContent = 'Add';
            add.addEventListener('click', () => addToCart(item));

            card.append(text, add);
            root.appendChild(card);
        });
    }

    function addToCart(item) {
        cart[item.id] = (cart[item.id] || 0) + 1;
        pulse.track('add_to_cart', { item_id: item.id, price_cents: item.price_cents });
        renderCart();
    }

    function cartTotal() {
        return Object.entries(cart).reduce((sum, [id, qty]) => sum + items[id].price_cents * qty, 0);
    }

    function renderCart() {
        const box = $('cart');
        box.replaceChildren();
        const entries = Object.entries(cart);
        if (!entries.length) { box.textContent = 'Your cart is empty.'; }
        entries.forEach(([id, qty]) => {
            const row = document.createElement('div');
            row.textContent = qty + ' × ' + items[id].name;
            box.appendChild(row);
        });
        $('total').textContent = money(cartTotal());
        $('place').disabled = !entries.length;
    }

    $('place').addEventListener('click', async () => {
        $('error').textContent = '';
        $('place').disabled = true;
        pulse.track('checkout_started', { cart_total_cents: cartTotal() });

        try {
            const res = await fetch('{{ route('demo.orders.store') }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                },
                body: JSON.stringify({
                    name: $('name').value,
                    email: $('email').value,
                    items: Object.entries(cart).map(([id, qty]) => ({ id, qty })),
                }),
            });
            const body = await res.json();
            if (!res.ok) {
                const first = body.errors ? Object.values(body.errors)[0][0] : 'Something went wrong.';
                $('error').textContent = first;
                $('place').disabled = false;
                return;
            }
            // Link this browser visitor to the customer, then flush before navigating away.
            pulse.identify(body.customer_id);
            pulse.track('checkout_completed', { cart_total_cents: cartTotal() });
            pulse.flush(true);
            window.location.href = body.redirect;
        } catch (e) {
            $('error').textContent = 'Network error, please try again.';
            $('place').disabled = false;
        }
    });

    renderMenu();
    renderCart();
</script>
@endpush
