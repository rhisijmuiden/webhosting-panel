# Written by webmail/snappymail/install.sh. Placeholders: @WEBMAIL_HOST@ @PHP_SOCK@ @AUTOCONFIG_SOCK@ @CERT@ @KEY@
# Webmail (SnappyMail) and the mail discovery endpoints for hosted domains.

map $ssl_server_name $whp_mail_endpoint_domain {
    ~^(autoconfig|autodiscover|mta-sts)\.(?<d>.+)$ $d;
    default "";
}

server {
    listen 80;
    listen [::]:80;
    server_name @WEBMAIL_HOST@ ~^(autoconfig|autodiscover|mta-sts)\..+$;

    location ^~ /.well-known/acme-challenge/ {
        root /var/lib/webhosting-panel/acme;
        default_type text/plain;
    }
    # Thunderbird also asks over plain HTTP; the answer holds no secrets.
    location ~ ^/(mail/config-v1\.1\.xml|\.well-known/autoconfig/mail/config-v1\.1\.xml)$ {
        if ($host !~ ^autoconfig\.) { return 404; }
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME /opt/webhosting-panel/webmail/autoconfig/public/index.php;
        fastcgi_pass unix:@AUTOCONFIG_SOCK@;
    }
    location / {
        return 301 https://$host$request_uri;
    }
}

server {
    listen 443 ssl;
    listen [::]:443 ssl;
    listen 443 quic;
    listen [::]:443 quic;
    http2 on;
    server_name ~^(autoconfig|autodiscover|mta-sts)\..+$;

    # One certificate per hosted domain (autoconfig, autodiscover and mta-sts as SANs),
    # issued by the agent as cert name "mail-endpoints.<domain>".
    ssl_certificate     /etc/letsencrypt/live/mail-endpoints.$whp_mail_endpoint_domain/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/mail-endpoints.$whp_mail_endpoint_domain/privkey.pem;

    add_header Alt-Svc 'h3=":443"; ma=86400' always;
    add_header Strict-Transport-Security "max-age=31536000" always;

    client_max_body_size 16k;
    location / {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME /opt/webhosting-panel/webmail/autoconfig/public/index.php;
        fastcgi_pass unix:@AUTOCONFIG_SOCK@;
    }
}

server {
    listen 443 ssl;
    listen [::]:443 ssl;
    listen 443 quic;
    listen [::]:443 quic;
    http2 on;
    server_name @WEBMAIL_HOST@;

    ssl_certificate     @CERT@;
    ssl_certificate_key @KEY@;

    root /var/www/snappymail;
    index index.php;
    client_max_body_size 50m;

    add_header Alt-Svc 'h3=":443"; ma=86400' always;
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
    add_header X-Content-Type-Options nosniff always;
    add_header Referrer-Policy same-origin always;
    add_header Permissions-Policy "camera=(), microphone=(), geolocation=()" always;

    # The data folder lives outside the web root; block it anyway in case of a misconfiguration.
    location ^~ /data { deny all; }
    location ~ /\. { deny all; }

    location ~ \.php$ {
        try_files $uri =404;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param HTTPS on;
        fastcgi_pass unix:@PHP_SOCK@;
        fastcgi_read_timeout 300;
    }
    location ~* \.(js|css|woff2?|ttf|svg|png|jpg|ico)$ {
        expires 30d;
        access_log off;
        try_files $uri =404;
    }
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
}
