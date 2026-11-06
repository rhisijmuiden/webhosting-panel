@foreach ((array) session('warnings', []) as $w)
    <div class="alert alert-warn" role="status"><x-icon name="info" />{{ $w }}</div>
@endforeach
@foreach (['dkim', 'dns'] as $key)
    @error($key)<div class="alert alert-bad" role="alert"><x-icon name="info" />{{ $message }}</div>@enderror
@endforeach
