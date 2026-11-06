<div class="table-wrap">
    <table {{ $attributes->class(['table']) }}>
        @isset($head)<thead><tr>{{ $head }}</tr></thead>@endisset
        <tbody>{{ $slot }}</tbody>
    </table>
</div>
