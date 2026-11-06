@php $locales = \App\Support\Locales::available(); @endphp
@if (count($locales) > 1)
<div class="dropdown" x-data="{ open: false }" @click.outside="open = false">
    <button type="button" class="btn" @click="open = !open" :aria-expanded="open" aria-haspopup="true" aria-label="{{ __('Language') }}">
        <x-icon name="language" />{{ strtoupper(app()->getLocale()) }}
    </button>
    <div class="dropdown-menu" x-show="open" x-cloak x-transition.opacity>
        @foreach ($locales as $code => $name)
            <form method="POST" action="{{ route('locale.switch') }}">
                @csrf<input type="hidden" name="locale" value="{{ $code }}">
                <button type="submit" @if ($code === app()->getLocale()) aria-current="true" @endif>
                    <span class="mono" style="width:22px">{{ strtoupper($code) }}</span>{{ $name }}
                    @if ($code === app()->getLocale())<x-icon name="check" style="margin-left:auto" />@endif
                </button>
            </form>
        @endforeach
    </div>
</div>
@endif
