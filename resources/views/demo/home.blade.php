@extends('demo.layout')

@section('title', 'Home')

@section('content')
    <h1>Wood-fired food, done simply.</h1>
    <p class="muted">A demo restaurant used to exercise the analytics SDK. Browse a few pages, build a cart and place an order, then open the dashboard.</p>
    <p><a href="{{ route('demo.menu') }}"><button>See the menu</button></a></p>
@endsection
