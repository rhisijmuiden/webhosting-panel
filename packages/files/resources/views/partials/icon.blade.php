{{-- File type icons for the file manager (kept in the package, so the shared icon set is untouched). --}}
<svg class="fm-ico {{ $name }}" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" fill="none">
@switch($name)
    @case('folder')<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z" fill="inherit"/>@break
    @case('web')<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z" fill="inherit"/><path d="M12 10v6M9 13h6" />@break
    @case('archive')<path d="M5 3h10l4 4v14H5V3Z"/><path d="M11 3v2h2v2h-2v2h2v2h-2v2"/><rect x="10" y="14" width="4" height="4" rx="1"/>@break
    @case('code')<path d="M6 3h9l4 4v14H6V3Z"/><path d="m11 12-2 2 2 2M14 12l2 2-2 2"/>@break
    @case('image')<path d="M5 3h10l4 4v14H5V3Z"/><circle cx="10" cy="11" r="1.5"/><path d="m6 19 5-5 3 3 2-2 3 3"/>@break
    @case('link')<path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/>@break
    @case('upload')<path d="M12 16V4M7 9l5-5 5 5M4 20h16"/>@break
    @case('download')<path d="M12 4v12M7 11l5 5 5-5M4 20h16"/>@break
    @case('folder-plus')<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z"/><path d="M12 10v6M9 13h6"/>@break
    @case('file-plus')<path d="M6 3h9l4 4v14H6V3Z"/><path d="M12 10v6M9 13h6"/>@break
    @case('terminal')<rect x="3" y="4" width="18" height="16" rx="2"/><path d="m7 9 3 3-3 3M12 15h5"/>@break
    @case('more')<circle cx="5" cy="12" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/>@break
    @case('up')<path d="M12 19V5M5 12l7-7 7 7"/>@break
    @default<path d="M6 3h9l4 4v14H6V3Z"/><path d="M15 3v4h4"/>
@endswitch
</svg>
