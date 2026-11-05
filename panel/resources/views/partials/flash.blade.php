@if (session('status'))
    <div class="alert alert-ok" role="status"><x-icon name="check" />{{ session('status') }}</div>
@endif
@if (session('warning'))
    <div class="alert alert-warn" role="status"><x-icon name="info" />{{ session('warning') }}</div>
@endif
@foreach (['agent', 'customer', 'package'] as $key)
    @error($key)<div class="alert alert-bad" role="alert"><x-icon name="info" />{{ $message }}</div>@enderror
@endforeach
