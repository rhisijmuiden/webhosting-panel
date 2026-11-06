{{-- Autoresponder of one mailbox ($box, $reply), inside the mailboxes table. --}}
@php
    $bag = $errors->getBag('autoreply-' . $box->id);
    $address = $box->local_part . '@' . $domain->domain;
    $val = fn (string $field, $default = null) => $bag->any() ? old($field, $default) : $default;
    $intervals = [1 => __('Once a day per sender'), 3 => __('Once every 3 days per sender'), 7 => __('Once a week per sender'), 30 => __('Once a month per sender')];
@endphp
<details @if ($bag->any()) open @endif style="margin-top:8px">
    <summary class="small" style="cursor:pointer;font-weight:600">{{ __('Autoreply (out of office)') }}</summary>
    <form method="POST" action="{{ $r('autoreply', [$box->id]) }}" class="form" style="margin-top:12px">
        @csrf @method('PUT')
        <input type="hidden" name="enabled" value="0">
        <label class="check"><input type="checkbox" name="enabled" value="1" @checked($val('enabled', $reply?->enabled))><span>{{ __('Send an automatic reply') }}<small>{{ __('Everyone who mails :address gets this answer. Spam and mailing lists never get one.', ['address' => $address]) }}</small></span></label>
        <div class="field">
            <label for="ar-subject-{{ $box->id }}">{{ __('Subject') }}</label>
            <input id="ar-subject-{{ $box->id }}" name="subject" class="input" maxlength="200" value="{{ $val('subject', $reply?->subject ?? __('Out of office')) }}">
            @if ($bag->has('subject'))<span class="error">{{ $bag->first('subject') }}</span>@endif
        </div>
        <div class="field">
            <label for="ar-body-{{ $box->id }}">{{ __('Message') }}</label>
            <textarea id="ar-body-{{ $box->id }}" name="body" class="textarea" rows="5" maxlength="10000">{{ $val('body', $reply?->body ?? __("Thank you for your message. I am out of the office and will reply when I am back.\n\nKind regards,\n:name", ['name' => $box->display_name ?: $address])) }}</textarea>
            @if ($bag->has('body'))<span class="error">{{ $bag->first('body') }}</span>@endif
        </div>
        <div class="form-grid">
            <div class="field">
                <label for="ar-start-{{ $box->id }}">{{ __('First day') }}</label>
                <input id="ar-start-{{ $box->id }}" type="date" name="starts_on" class="input" value="{{ $val('starts_on', $reply?->starts_on?->toDateString()) }}">
                <span class="help">{{ __('Empty: from now on.') }}</span>
                @if ($bag->has('starts_on'))<span class="error">{{ $bag->first('starts_on') }}</span>@endif
            </div>
            <div class="field">
                <label for="ar-end-{{ $box->id }}">{{ __('Last day') }}</label>
                <input id="ar-end-{{ $box->id }}" type="date" name="ends_on" class="input" value="{{ $val('ends_on', $reply?->ends_on?->toDateString()) }}">
                <span class="help">{{ __('Empty: until you switch it off.') }}</span>
                @if ($bag->has('ends_on'))<span class="error">{{ $bag->first('ends_on') }}</span>@endif
            </div>
        </div>
        <div class="field">
            <label for="ar-days-{{ $box->id }}">{{ __('How often') }}</label>
            <select id="ar-days-{{ $box->id }}" name="interval_days" class="select">
                @foreach ($intervals as $days => $label)<option value="{{ $days }}" @selected((int) $val('interval_days', $reply?->interval_days ?? 1) === $days)>{{ $label }}</option>@endforeach
            </select>
        </div>
        <div><button type="submit" class="btn btn-dark btn-sm">{{ __('Save autoreply') }}</button></div>
    </form>
</details>
