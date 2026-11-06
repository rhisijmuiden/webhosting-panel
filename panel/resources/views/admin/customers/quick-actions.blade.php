{{-- Quick actions for a customer: big touch targets, also on a phone. --}}
@if (session('new_password'))
    @php $np = session('new_password'); @endphp
    <div class="alert alert-info new-password" role="status" x-data="copyValue(@js($np['password']))">
        <x-icon name="key" />
        <div>
            <strong>{{ __('New password for :email', ['email' => $np['email']]) }}</strong>
            <p class="small">{{ __('Shown only once. Pass it on to the customer and ask them to change it after logging in.') }}</p>
            <div class="new-password-value"><code class="mono">{{ $np['password'] }}</code>
                <button type="button" class="btn btn-sm" @click="copy()"><x-icon name="check" x-show="copied" x-cloak /><span x-text="copied ? @js(__('Copied')) : @js(__('Copy'))">{{ __('Copy') }}</span></button></div>
        </div>
    </div>
@endif
<div class="quick-actions" aria-label="{{ __('Quick actions') }}">
    @if ($customer->phone)
        <a class="m-action" href="tel:{{ preg_replace('/[^0-9+]/', '', $customer->phone) }}"><x-icon name="phone" /><span>{{ __('Call') }}</span></a>
    @endif
    <a class="m-action" href="mailto:{{ $customer->email }}"><x-icon name="mail" /><span>{{ __('E-mail') }}</span></a>
    @if ($customer->status === 'suspended')
        <form method="POST" action="{{ route('admin.customers.resume', $customer) }}" data-confirm="{{ __('Reactivate :name? Logins work again and the websites come back online.', ['name' => $customer->displayName()]) }}">@csrf
            <button type="submit" class="m-action is-ok"><x-icon name="play" /><span>{{ __('Reactivate') }}</span></button></form>
    @else
        <form method="POST" action="{{ route('admin.customers.suspend', $customer) }}" data-confirm="{{ __('Suspend :name? Their logins are blocked and their websites go offline until you reactivate them.', ['name' => $customer->displayName()]) }}">@csrf
            <button type="submit" class="m-action is-bad"><x-icon name="pause" /><span>{{ __('Suspend') }}</span></button></form>
    @endif
    @if (Route::has('admin.backups.index'))
        <a class="m-action" href="{{ route('admin.backups.index') }}"><x-icon name="backup" /><span>{{ __('Backups') }}</span></a>
    @endif
    @if (Route::has('admin.mail.log.index'))
        <a class="m-action" href="{{ route('admin.mail.log.index', ['q' => $customer->websites->first()?->domain]) }}"><x-icon name="list" /><span>{{ __('Mail log') }}</span></a>
    @endif
</div>
