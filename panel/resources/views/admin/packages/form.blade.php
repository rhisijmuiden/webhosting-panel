@php $editing = $package->exists; @endphp
<x-layouts.admin :title="$editing ? __('Edit package') : __('New package')">
    <div class="page-head"><div><div class="crumbs"><a href="{{ route('admin.packages.index') }}">{{ __('Packages') }}</a></div><h1>{{ $editing ? $package->name : __('New package') }}</h1></div></div>
    <form method="POST" action="{{ $editing ? route('admin.packages.update', $package) : route('admin.packages.store') }}" class="form">
        @csrf @if ($editing) @method('PUT') @endif
        <x-ui.card>
            <div class="form-grid">
                <x-ui.field name="name" :label="__('Name')" :value="$package->name" required />
                <x-ui.field name="crm_id" :label="__('CRM ID')" :value="$package->crm_id" :help="__('Links this package to the product in the CRM.')" />
            </div>
            <x-ui.field name="description" type="textarea" :label="__('Description')" :value="$package->description" />
        </x-ui.card>
        <x-ui.card :title="__('Limits')">
            <p class="muted small">{{ __('Leave a limit on Unlimited to not limit it. Customers then share the server fairly with everyone else.') }}</p>
            <div class="form-grid">
                @foreach (\App\Models\HostingPackage::USAGE_LIMITS as $key => [$label, $unit, $min, $max])
                    <x-ui.limit :name="$key" :label="__($label)" :unit="$unit" :min="$min" :max="$max" :value="$package->{$key}" />
                @endforeach
            </div>
            <x-ui.field name="traffic_action" type="select" :label="__('When the data traffic limit is reached')" :help="__('Traffic is counted from the web server logs: everything visitors download and upload, per calendar month.')">
                @foreach (\App\Models\HostingPackage::TRAFFIC_ACTIONS as $a)
                    <option value="{{ $a }}" @selected(old('traffic_action', $package->traffic_action) === $a)>{{ $a === 'notify' ? __('Send me an e-mail, keep the websites online') : __('Send me an e-mail and suspend the websites') }}</option>
                @endforeach
            </x-ui.field>
            <label class="check"><input type="checkbox" name="allow_ssh" value="1" @checked(old('allow_ssh', $package->allow_ssh))><span>{{ __('SSH access') }}<small>{{ __('Customers can log in with SSH (SFTP is always available).') }}</small></span></label>
            <label class="check"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $package->is_active))><span>{{ __('Active') }}<small>{{ __('Inactive packages cannot be given to new customers.') }}</small></span></label>
        </x-ui.card>
        <x-ui.card :title="__('Server resources')">
            <div class="section-note"><x-icon name="shield" /><div><strong>{{ __('Every customer runs in its own resource jail.') }}</strong>
                {{ __('All websites of a customer share one jail (a systemd slice with cgroups), also for cron jobs, WP-CLI and SSH sessions. Without caps customers get an equal share when the server is busy; with caps a customer can never use more, whatever its websites do.') }}</div></div>
            <div class="form-grid">
                @php $help = [
                    'cpu_percent' => __('100 % is one full CPU core.'),
                    'memory_mb' => __('For all websites together. At 90 % memory is reclaimed and PHP slows down; at 100 % the heaviest process is stopped.'),
                    'io_mbps' => __('Read and write speed on the website disk.'),
                    'max_processes' => __('PHP workers, cron jobs and other processes together.'),
                    'php_workers' => __('Requests one website handles at the same time. Unlimited uses the server default from PHP settings.'),
                    'db_connections' => __('Simultaneous MariaDB connections per database user.'),
                ]; @endphp
                @foreach (\App\Models\HostingPackage::RESOURCE_LIMITS as $key => [$label, $unit, $min, $max])
                    <x-ui.limit :name="$key" :label="__($label)" :unit="$unit" :min="$min" :max="$max" :value="$package->{$key}" :help="$help[$key]"
                        :unlimited-label="$key === 'php_workers' ? __('Server default') : null" />
                @endforeach
            </div>
        </x-ui.card>
        <x-ui.card :title="__('Node.js and Python apps')">
            <p class="muted small">{{ __('Apps run as the website user in the same resource jail, behind Nginx. Set the number of apps to 0 to switch the feature off for this package.') }}</p>
            <div class="form-grid">
                @php $appHelp = [
                    'max_webapps' => __('Apps one website may run at the same time.'),
                    'webapp_memory_mb' => __('Hard cap for one app; the app is restarted when it uses more.'),
                    'webapp_cpu_percent' => __('100 % is one full CPU core.'),
                ]; @endphp
                @foreach (\App\Models\HostingPackage::APP_LIMITS as $key => [$label, $unit, $min, $max])
                    <x-ui.limit :name="$key" :label="__($label)" :unit="$unit" :min="$min" :max="$max" :value="$package->{$key}" :help="$appHelp[$key]" />
                @endforeach
            </div>
        </x-ui.card>
        @includeIf('object-cache::admin.package-card', ['package' => $package])
        <div class="actions">
            <button type="submit" class="btn btn-primary">{{ __('Save') }}</button>
            <a class="btn" href="{{ route('admin.packages.index') }}">{{ __('Cancel') }}</a>
        </div>
    </form>
    @if ($editing)
        <form method="POST" action="{{ route('admin.packages.destroy', $package) }}" data-confirm="{{ __('Delete this package?') }}">@csrf @method('DELETE')
            <button class="btn btn-danger btn-sm" type="submit"><x-icon name="trash" />{{ __('Delete package') }}</button></form>
    @endif
</x-layouts.admin>
