#!/usr/bin/env bash

apt-get install librabbitmq-dev -y;
pecl install amqp
echo "extension=amqp.so" >> "$(php -r 'echo php_ini_loaded_file();')";
