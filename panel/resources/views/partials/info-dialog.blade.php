<dialog class="modal" x-ref="dialog" aria-labelledby="info-title">
    <div class="modal-head">
        <h2 id="info-title">{{ __('Info') }}</h2>
        <button type="button" class="btn btn-ghost icon-btn" @click="close()" aria-label="{{ __('Close') }}"><x-icon name="close" /></button>
    </div>
    <div class="modal-body">
        <template x-if="loading && !info"><p class="muted">{{ __('Loading…') }}</p></template>
        <template x-if="info">
            <dl class="kv">
                <dt>{{ __('Application') }}</dt><dd x-text="info.app"></dd>
                <dt>{{ __('Version') }}</dt><dd x-text="'v' + info.version"></dd>
                <dt>{{ __('Channel') }}</dt><dd x-text="info.channel === 'test' ? @js(__('test')) : @js(__('production'))"></dd>
                @if ($isAdmin)
                <dt>{{ __('Commit') }}</dt><dd class="mono" x-text="info.commit || '–'"></dd>
                <dt>PHP / Laravel</dt><dd x-text="info.php + ' / ' + info.laravel"></dd>
                @endif
            </dl>
        </template>

        @if ($isAdmin)
        <div class="field">
            <span class="label">{{ __('Release channel') }}</span>
            <div class="seg" role="radiogroup" aria-label="{{ __('Release channel') }}">
                <label><input type="radio" name="channel" value="production" x-model="channel" :disabled="jobStatus === 'running' || jobStatus === 'restarting'"><span>{{ __('Production') }}</span></label>
                <label><input type="radio" name="channel" value="test" x-model="channel" :disabled="jobStatus === 'running' || jobStatus === 'restarting'"><span>{{ __('Test') }}</span></label>
            </div>
            <span class="help">{{ __('Production follows the main branch. Test gets new features first and can contain bugs.') }}</span>
        </div>

        <template x-if="check">
            <div :class="check.update_available ? 'alert alert-warn' : 'alert alert-ok'" role="status">
                <div>
                    <strong x-text="check.update_available ? @js(__('An update is available')) : @js(__('You are up to date'))"></strong>
                    <p class="small" x-show="check.update_available" x-text="'v' + check.current_version + ' → v' + (check.latest_version || '?') + ' · ' + check.commits_behind + ' ' + @js(__('new changes'))"></p>
                    <ul class="small" style="margin:6px 0 0;padding-left:18px" x-show="check.changes && check.changes.length">
                        <template x-for="c in check.changes.slice(0, 8)" :key="c.commit"><li x-text="c.subject"></li></template>
                    </ul>
                </div>
            </div>
        </template>

        <template x-if="jobStatus">
            <div class="field">
                <span class="label" x-text="jobStatus === 'restarting' ? @js(__('The panel is restarting…')) : (jobStatus === 'succeeded' ? @js(__('Update finished, reloading…')) : (jobStatus === 'failed' ? @js(__('Update failed')) : @js(__('Updating…'))))"></span>
                <div class="log" x-text="jobLog.join('\n') || '…'"></div>
            </div>
        </template>
        @endif

        <template x-if="error"><div class="alert alert-bad" role="alert" x-text="error"></div></template>
    </div>
    <div class="modal-foot">
        @if ($isAdmin)
            <button type="button" class="btn" @click="checkUpdates()" :disabled="loading || jobStatus === 'running'"><x-icon name="refresh" />{{ __('Check for updates') }}</button>
            <button type="button" class="btn" x-show="channel !== (info && info.channel)" x-cloak @click="switchChannel()" :disabled="jobStatus === 'running'">{{ __('Switch channel') }}</button>
            <button type="button" class="btn btn-primary" x-show="check && check.update_available && channel === (info && info.channel)" x-cloak @click="runUpdate()" :disabled="jobStatus === 'running' || jobStatus === 'restarting'">{{ __('Update now') }}</button>
        @else
            <button type="button" class="btn" @click="close()">{{ __('Close') }}</button>
        @endif
    </div>
</dialog>
