<div class="page-head">
    <div>
        <div class="crumbs"><a href="{{ route($area.'.webapps.index') }}">{{ __('Node.js & Python') }}</a></div>
        <h1>{{ __('New app') }}</h1>
        <p class="sub">{{ __('The app gets its own service on the server: it starts by itself, restarts after a crash and stays inside the limits of the website\'s package.') }}</p>
    </div>
</div>
@foreach ($errors->get('agent') as $e)<div class="alert alert-bad" role="alert"><x-icon name="info" /><div>{{ $e }}</div></div>@endforeach
<form method="POST" action="{{ route($area.'.webapps.store') }}">
    @csrf
    <x-ui.card>
        @include('webapps::partials.form')
    </x-ui.card>
    <div class="actions" style="margin-top:16px">
        <button type="submit" class="btn btn-primary">{{ __('Create app') }}</button>
        <a class="btn" href="{{ route($area.'.webapps.index') }}">{{ __('Cancel') }}</a>
    </div>
</form>
