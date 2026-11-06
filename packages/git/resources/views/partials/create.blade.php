{{-- Link a repository to a website. --}}
<div class="page-head">
    <div>
        <div class="crumbs"><a href="{{ route($area.'.git.index') }}">{{ __('Git') }}</a> / {{ __('Link a repository') }}</div>
        <h1>{{ __('Link a repository') }}</h1>
        <p class="sub">{{ __('After saving you get a deploy key and a webhook address to add to your repository.') }}</p>
    </div>
</div>

@foreach ($errors->get('agent') as $e)<div class="alert alert-bad" role="alert"><x-icon name="info" />{{ $e }}</div>@endforeach

@if ($websites->isEmpty())
    <x-ui.card><x-ui.empty icon="globe">{{ __('Create a website first.') }}</x-ui.empty></x-ui.card>
@else
<form method="POST" action="{{ route($area.'.git.store') }}" class="form"
    x-data="{ mode: @js(old('mode', 'releases')), path: @js(old('deploy_path', $defaults[$website->id]['path'])), shared: @js(old('shared', $defaults[$website->id]['shared'])), defaults: @js($defaults),
              pick(id) { const d = this.defaults[id]; if (d) { this.path = d.path; this.shared = d.shared; } } }">
    @csrf
    <x-ui.card :title="__('Website')">
        <x-ui.field name="website_id" type="select" :label="__('Website')" x-on:change="pick($event.target.value)">
            @foreach ($websites as $w)
                <option value="{{ $w->id }}" @selected((int) old('website_id', $website->id) === $w->id)>{{ $w->domain }}@if ($area === 'admin' && $w->customer) · {{ $w->customer->name }}@endif</option>
            @endforeach
        </x-ui.field>
    </x-ui.card>
    <x-ui.card :title="__('Repository')">
        @include('git::partials.settings', ['repo' => null, 'commandsAllowed' => $area === 'admin' || config('git.customer_commands')])
    </x-ui.card>
    <div class="actions">
        <button type="submit" class="btn btn-primary"><x-icon name="git" />{{ __('Link repository') }}</button>
        <a class="btn" href="{{ route($area.'.git.index') }}">{{ __('Cancel') }}</a>
    </div>
</form>
@endif
