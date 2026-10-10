#!/bin/sh
# Vérifie que PHP-FPM répond sur son ping interne (non exposé par Nginx).
set -e
SCRIPT_NAME=/fpm-ping SCRIPT_FILENAME=/fpm-ping REQUEST_METHOD=GET \
    cgi-fcgi -bind -connect 127.0.0.1:9000 | grep -q pong
