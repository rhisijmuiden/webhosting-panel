{{-- Styles of the FTP account screens, once per page. --}}
@once
<style>
    .ftp-form { max-width: 600px; }
    .ftp-login, .ftp-folder { display: flex; align-items: center; gap: 8px; }
    .ftp-login .input { max-width: 220px; }
    .ftp-folder .input { flex: 1; min-width: 0; }
    .ftp-folder > span, .ftp-login > span { white-space: nowrap; }
    .ftp-secret { align-items: flex-start; }
    .ftp-secret-row { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin: 6px 0 4px; }
    .ftp-secret code { font-size: 15px; padding: 4px 10px; border-radius: 8px; background: var(--surface-2, rgba(0,0,0,.05)); user-select: all; }
    .ftp-edit-row > td { background: var(--surface-2, rgba(0,0,0,.025)); }
    .ftp-fp { word-break: break-all; }
    .ftp-details > summary { list-style: none; display: inline-flex; margin-top: 8px; }
    .ftp-details > summary::-webkit-details-marker { display: none; }
    .ftp-details[open] > summary { margin-bottom: 12px; }
    td .actions { flex-wrap: nowrap; }
    .ftp-pills { display: flex; gap: 6px; flex-wrap: wrap; align-items: center; }
    .ftp-login-name { overflow-wrap: anywhere; }
    @media (max-width: 640px) { .ftp-folder { flex-wrap: wrap; } .ftp-login .input { max-width: none; flex: 1; } }
</style>
@endonce
