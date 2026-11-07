{{-- How a smart update works, in four steps. --}}
<section class="card su-steps" aria-label="{{ __('How smart updates work') }}">
    <div class="su-step"><span class="n"><x-icon name="search" /></span><div><strong>{{ __('1. Screenshots') }}</strong><span>{{ __('Every chosen page is photographed twice, on desktop and mobile. What moves by itself (sliders, dates) is left out.') }}</span></div></div>
    <div class="su-step"><span class="n"><x-icon name="backup" /></span><div><strong>{{ __('2. Restore point and update') }}</strong><span>{{ __('Database, plugins and themes are saved first. Then the update runs in the maintenance window.') }}</span></div></div>
    <div class="su-step"><span class="n"><x-icon name="activity" /></span><div><strong>{{ __('3. Compare') }}</strong><span>{{ __('The pages are photographed again and compared block by block with the screenshots from before.') }}</span></div></div>
    <div class="su-step"><span class="n"><x-icon name="shield" /></span><div><strong>{{ __('4. Keep or roll back') }}</strong><span>{{ __('Looks the same: the update stays. Looks different or gives an error: the site is put back at once.') }}</span></div></div>
</section>
