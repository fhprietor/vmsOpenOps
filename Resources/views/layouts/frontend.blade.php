@extends('app')

@section('title', isset($title) ? $title . ' - Operations' : 'Operations')

@section('content')
<div class="container">
    @yield('content')
</div>
@endsection