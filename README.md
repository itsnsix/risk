# World domination

## About
This is an automatic risk map pulling submissions from a 3rd party site to generate user activity.

## Dev
- Set up PHP 7 and Composer.
- Set up Python 2.7.
- Set up Node 12 (ish?).
- Set up a local mysql database (mariadb for example).
- Create a `.env` file in root based on the `.env.example` file.
- Edit the DB_* properties to your local mysql database. Remember to create a database for the project `CREATE DATABASE risk;`
- Run `composer install` to install php dependencies.
- Run `php artisan key:generate` to generate key used for encryption.
- Run `php artisan migrate` to create the database tables.
- Run `npm install` to install the javascript dependencies.
- Run `npm run dev` to build the frontend, this generates a mix-manifest file needed for the php server to start.
- Run `php artisan serve` to start the project locally on localhost:8000.

Error log can be found in `storage/logs/laravel.log` if something fails.
