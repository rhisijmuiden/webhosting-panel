@props(['icon' => 'info'])
<div class="empty"><x-icon :name="$icon" style="width:28px;height:28px" /><p>{{ $slot }}</p>@isset($action){{ $action }}@endisset</div>
