{{-- New cron job. --}}
<div class="page-head">
    <div>
        <div class="crumbs"><a href="{{ route($area.'.cron.index') }}">{{ __('Cron jobs') }}</a></div>
        <h1>{{ __('New cron job') }}</h1>
        <p class="sub">{{ __('Pick what should run and when. You can try it right away with Run now.') }}</p>
    </div>
</div>
@foreach ($errors->get('agent') as $e)<div class="alert alert-bad" role="alert"><x-icon name="info" /><div>{{ $e }}</div></div>@endforeach
<x-ui.card>
    <form method="POST" action="{{ route($area.'.cron.store') }}">
        @csrf
        @include('cron::partials.form')
        <div class="actions" style="margin-top:16px">
            <button type="submit" class="btn btn-primary">{{ __('Save cron job') }}</button>
            <a class="btn" href="{{ route($area.'.cron.index') }}">{{ __('Cancel') }}</a>
        </div>
    </form>
</x-ui.card>
