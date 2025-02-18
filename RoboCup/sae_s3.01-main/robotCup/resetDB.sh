#!/bin/bash

php bin/console doctrine:database:drop --force
php bin/console doctrine:database:create
symfony console doctrine:schema:create
php bin/console doctrine:migrations:migrate -n
php bin/console app:create-admin

if [ $# -gt 0 ]; then
    php bin/console doctrine:fixtures:load -n --append
fi

