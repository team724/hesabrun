hesabrun

for see full project Demo see main website at https://hesabrun.ir

Before Installation
For install hesabrunCore you need this tools

Web server : Apache,NginX,...

Database: Mysql, mariaDB,SqlServer,....

PHP: php : +8.1

php extentions: php-Intl, php-mbstring, php-http, php-raphf

composer

Installation
Copy or clone project in web server directory . if you use shared hosting panels like cpanel or directadmin copy files in root directory and public_html folder will be rewrited.

create database in your DBMS and edit .env file in root of project

Install dependencies with run this command

composer install
edit .env file and set database connection string with your username and password and name of database

create local env file with run this command

composer dump-env prod
login to your database managment like phpmyadmin and import file located in hesabrunBackup/databaseFiles/hesabrun-db-default.sql

go to hesabrunCore folder in cli and update database with this command

php bin/console doctrine:schema:update --force --complete
open root domain address in browser you should see hesabrun api main page.

Connect to email service
For connect hesabrun to your email service edit .env.local.php file located in hesabrunCore folder and set your email server connection string in MAILER_DSN parameter. for more information about connection strings see symfony mailer documents. Click Here

after set connection string edit mailer.yaml located in configs folder and set header for send emails.

Donation
for help developers please use this link
https://zarinp.al/hesabrun.ir
