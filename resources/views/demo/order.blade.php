@extends('demo.layout')

@section('title', 'Order confirmed')

@section('content')
    <h1>Thanks, {{ $order->customer_name }}!</h1>
    <p class="muted">Order {{ $order->public_id }} has been placed.</p>
    <div class="card">
        @foreach ($order->items as $line)
            <div>{{ $line['qty'] }} × {{ $line['name'] }}</div>
        @endforeach
        <hr>
        <strong>Total: ₹{{ number_format($order->total_cents / 100, 2) }}</strong>
    </div>
    <p><a href="{{ route('dashboard') }}">See it in the analytics dashboard →</a></p>
@endsection
