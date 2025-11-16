Трекер обращений клиентов (backend на PHP + frontend HTML/jQuery).

Коротко:
- backend: PHP 8+, PDO, JWT (firebase/php-jwt), без фреймворков
- frontend: static `front/index.html` + jQuery
- БД: MySQL (скрипт `db_init.sql`)
- Docker: `docker-compose.yml` включён (mysql, php, nginx)

Старт (Docker):

1. Запустите контейнеры:

docker-compose up -d

2. Инит. базу (в контейнере mysql):

docker-compose exec -T mysql mysql -uroot -proot tracker < db_init.sql

3. Можно создать скриптом:

docker-compose exec php php back/seed_users.php

Этот скрипт создаст `admin/admin` и `user/user`.

Имя пользователя : admin
Пароль: admin

обычный тестовый пользователь:

Имя пользователя : user
Пароль: user

4. Откройте в браузере `http://localhost:8080` или адрес, где поднят nginx.

Базовый адрес API: `http://localhost:8000/api/v1`