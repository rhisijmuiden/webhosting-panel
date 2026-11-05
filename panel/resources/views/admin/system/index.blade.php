<x-layouts.admin :title="__('Updates')">
    <div class="page-head"><div><h1>{{ __('Updates') }}</h1><p class="sub">{{ __('Version, release channel and updates of the panel.') }}</p></div></div>
    <section class="grid grid-2" style="align-items:start" x-data="infoDialog(@js([
        'channel' => $version['channel'],
        'infoUrl' => route('info'),
        'checkUrl' => route('admin.system.check'),
        'updateUrl' => route('admin.system.update'),
        'channelUrl' => route('admin.system.channel'),
        'jobUrl' => route('admin.system.job', '__JOB__'),
    ]))" x-init="info = { channel: @js($version['channel']) }">
        <x-ui.card :title="__('Installed version')">
            <dl class="kv">
                <dt>{{ __('Version') }}</dt><dd>v{{ $version['version'] }}</dd>
                <dt>{{ __('Channel') }}</dt><dd>{{ __($version['channel']) }}</dd>
                <dt>{{ __('Branch') }}</dt><dd class="mono">{{ $version['branch'] ?? '–' }}</dd>
                <dt>{{ __('Commit') }}</dt><dd class="mono">{{ $version['commit'] ?? '–' }}</dd>
                @if ($version['commit_date'])<dt>{{ __('Released') }}</dt><dd>{{ \Illuminate\Support\Carbon::parse($version['commit_date'])->isoFormat('LLL') }}</dd>@endif
                @if ($system)<dt>{{ __('Server') }}</dt><dd>{{ $system['os'] }}</dd>@endif
                <dt>PHP / Laravel</dt><dd>{{ PHP_VERSION }} / {{ app()->version() }}</dd>
            </dl>
        </x-ui.card>
        <x-ui.card :title="__('Check and update')">
            <div class="field">
                <span class="label">{{ __('Release channel') }}</span>
                <div class="seg" role="radiogroup" aria-label="{{ __('Release channel') }}">
                    <label><input type="radio" value="production" x-model="channel"><span>{{ __('Production') }}</span></label>
                    <label><input type="radio" value="test" x-model="channel"><span>{{ __('Test') }}</span></label>
                </div>
                <span class="help">{{ __('Production follows the main branch. Test gets new features first and can contain bugs.') }}</span>
            </div>
            <template x-if="check">
                <div :class="check.update_available ? 'alert alert-warn' : 'alert alert-ok'" role="status">
                    <div>
                        <strong x-text="check.update_available ? @js(__('An update is available')) : @js(__('You are up to date'))"></strong>
                        <p class="small" x-show="check.update_available" x-text="'v' + check.current_version + ' → v' + (check.latest_version || '?') + ' · ' + check.commits_behind + ' ' + @js(__('new changes'))"></p>
                        <ul class="small" style="margin:6px 0 0;padding-left:18px" x-show="check.changes && check.changes.length"><template x-for="c in check.changes" :key="c.commit"><li x-text="c.subject"></li></template></ul>
                    </div>
                </div>
            </template>
            <template x-if="jobStatus"><div class="log" x-text="jobLog.join('\n') || '…'"></div></template>
            <template x-if="error"><div class="alert alert-bad" role="alert" x-text="error"></div></template>
            <div class="actions">
                <button type="button" class="btn" @click="checkUpdates()" :disabled="loading"><x-icon name="refresh" />{{ __('Check for updates') }}</button>
                <button type="button" class="btn btn-dark" x-show="channel !== info.channel" x-cloak @click="switchChannel()">{{ __('Switch channel') }}</button>
                <button type="button" class="btn btn-primary" x-show="check && check.update_available && channel === info.channel" x-cloak @click="runUpdate()" :disabled="jobStatus === 'running'">{{ __('Update now') }}</button>
            </div>
            <p class="muted small">{{ __('Before an update the panel goes into maintenance mode. If something fails, it goes back to the previous version automatically.') }}</p>
        </x-ui.card>
    </section>
</x-layouts.admin>
