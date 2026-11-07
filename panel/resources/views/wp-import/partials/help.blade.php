{{-- How to export a WordPress website at the previous host: the two files the wizard asks for. --}}
<x-ui.card :title="__('How do I make the two files?')" class="wpi-help">
    <div class="wpi-file">
        <span class="wpi-file-icon" aria-hidden="true">@include('client.websites.overview.icon', ['name' => 'folder'])</span>
        <div>
            <h3>{{ __('1. The website: one zip file') }}</h3>
            <p class="small">{{ __('Everything of WordPress must be in this zip: the folders wp-admin, wp-content and wp-includes, and the files next to them such as wp-config.php, index.php and .htaccess.') }}</p>
        </div>
    </div>
    <details class="wpi-how">
        <summary>{{ __('With the file manager of your old host') }}</summary>
        <ol class="small">
            <li>{{ __('Log in to the control panel of your old host (cPanel, Plesk, DirectAdmin or similar) and open the File Manager.') }}</li>
            <li>{{ __('Go to the folder with WordPress. It is often called public_html, httpdocs, www or htdocs.') }}</li>
            <li>{{ __('Select everything in that folder, choose Compress (or Archive) and pick the Zip format.') }}</li>
            <li>{{ __('Download the zip file to your computer.') }}</li>
        </ol>
    </details>
    <details class="wpi-how">
        <summary>{{ __('With FTP (FileZilla)') }}</summary>
        <ol class="small">
            <li>{{ __('Download the WordPress folder with FileZilla to your computer.') }}</li>
            <li>{{ __('Windows: right-click the folder, Send to, Compressed (zipped) folder. Mac: right-click, Compress.') }}</li>
        </ol>
    </details>

    <div class="wpi-file">
        <span class="wpi-file-icon" aria-hidden="true"><x-icon name="database" /></span>
        <div>
            <h3>{{ __('2. The database: an export as zip') }}</h3>
            <p class="small">{{ __('The pages, posts, settings and users of WordPress live in the database. Export it as one .sql file and put it in a zip (a .sql or .sql.gz file is fine too).') }}</p>
        </div>
    </div>
    <details class="wpi-how">
        <summary>{{ __('With phpMyAdmin at your old host') }}</summary>
        <ol class="small">
            <li>{{ __('Open phpMyAdmin in the control panel of your old host (cPanel: Databases, phpMyAdmin; Plesk: Databases, phpMyAdmin; DirectAdmin: MySQL management).') }}</li>
            <li>{!! __('Click the WordPress database on the left. Not sure which one? Its name is in wp-config.php after :define.', ['define' => '<code>DB_NAME</code>']) !!}</li>
            <li>{{ __('Open the Export tab, choose the Quick method and the SQL format, and click Export (or Go).') }}</li>
            <li>{{ __('Zip the downloaded .sql file. Or choose Custom and set Compression to zipped before exporting.') }}</li>
        </ol>
    </details>

    <div class="wpi-note">
        <strong>{{ __('Check before uploading') }}</strong>
        <ul class="check-list small">
            <li><x-icon name="check" />{{ __('wp-admin, wp-content and wp-includes are in the website zip') }}</li>
            <li><x-icon name="check" />{{ __('The database zip holds one .sql file') }}</li>
            <li><x-icon name="check" />{{ __('No password on the zip files') }}</li>
        </ul>
        <p class="small muted">{{ __('Cache folders and logs in the zip are skipped automatically. You do not need to change wp-config.php yourself: the wizard does that.') }}</p>
    </div>
</x-ui.card>
