{{-- Service plan form: memory of the object cache per website (packages/object-cache). --}}
<x-ui.card :title="__('Object cache')">
    <p class="muted small">{{ __('Every website of this plan can switch on its own Valkey object cache. It runs as the website user in the same resource jail. Set it to 0 to switch the feature off for this plan; Server default uses :size.', ['size' => \Illuminate\Support\Number::fileSize((int) config('object-cache.default_mb', 64) * 1024 * 1024)]) }}</p>
    <div class="form-grid">
        @foreach (\App\Models\HostingPackage::CACHE_LIMITS as $key => [$label, $unit, $min, $max])
            <x-ui.limit :name="$key" :label="__($label)" :unit="$unit" :min="$min" :max="$max" :value="$package->{$key}"
                :help="__('Memory of the object cache of one website. When it is full, the oldest entries make room.')" :unlimited-label="__('Server default')" />
        @endforeach
    </div>
</x-ui.card>
