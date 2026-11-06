<div style="padding:12px;border:1px solid var(--line);border-radius:var(--radius-md);font-size:13px;display:flex;flex-direction:column;gap:2px">
    <strong style="font-weight:600">{{ $customer->displayName() }}</strong>
    @php $subs = $customer->websites()->count(); @endphp
    <span class="muted">{{ trans_choice(':count subscription|:count subscriptions', $subs) }}</span>
</div>
