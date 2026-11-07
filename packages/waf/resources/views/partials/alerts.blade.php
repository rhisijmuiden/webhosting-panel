@error('agent')<div class="alert alert-bad" role="alert"><x-icon name="info" />{{ $message }}</div>@enderror
@if (session('status'))<div class="alert alert-ok" role="status">{{ session('status') }}</div>@endif
@if ($errors->any() && ! $errors->has('agent'))<div class="alert alert-bad" role="alert"><x-icon name="info" />{{ $errors->first() }}</div>@endif
