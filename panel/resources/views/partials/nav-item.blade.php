<a href="{{ route($item['route']) }}" @class(['active' => $item['active']]) @if ($item['active']) aria-current="page" @endif>
    <x-icon :name="$item['icon']" /><span class="nav-label">{{ __($item['label']) }}</span>
    @if ($item['badge'] !== null && $item['badge'] !== '')<span class="badge">{{ is_numeric($item['badge']) ? $item['badge'] : __($item['badge']) }}</span>@endif
</a>
