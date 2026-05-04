#!/bin/bash

# Install pdo_sqlsrv for Azure SQL Server
if ! php -m | grep -q pdo_sqlsrv; then
    curl https://packages.microsoft.com/keys/microsoft.asc | apt-key add -
    curl https://packages.microsoft.com/config/ubuntu/20.04/prod.list > /etc/apt/sources.list.d/mssql-release.list
    apt-get update -qq
    ACCEPT_EULA=Y apt-get install -y -qq msodbcsql18 unixodbc-dev
    pecl install sqlsrv pdo_sqlsrv
    echo "extension=sqlsrv.so" >> /usr/local/etc/php/conf.d/ext-sqlsrv.ini
    echo "extension=pdo_sqlsrv.so" >> /usr/local/etc/php/conf.d/ext-pdo_sqlsrv.ini
fi

# Install mongodb extension
if ! php -m | grep -q mongodb; then
    pecl install mongodb
    echo "extension=mongodb.so" >> /usr/local/etc/php/conf.d/ext-mongodb.ini
fi

# Fix OpenSSL to allow TLS 1.2 with Atlas
cat > /etc/ssl/openssl.cnf << 'EOF'
openssl_conf = openssl_init

[openssl_init]
ssl_conf = ssl_sect

[ssl_sect]
system_default = system_default_sect

[system_default_sect]
MinProtocol = TLSv1.2
CipherString = DEFAULT@SECLEVEL=1
Options = UnsafeLegacyRenegotiation
EOF
