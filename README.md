## About Slim

Slim is a PHP micro framework that helps you quickly write simple yet powerful web applications and APIs.

##Download & Install

We recommend you install the Slim Framework with the Composer dependency manager.

The easiest way to start working with Slim is to create a project using Slim-Skeleton as a base by running this bash command:

```
$ php composer.phar create-project slim/slim-skeleton [my-app-name]
```

Replace [my-app-name] with the desired directory name for your new application.

You can then run it with PHP's built-in webserver:

```
$ cd [my-app-name]; php -S localhost:8080 -t public public/index.php
```

##Features
- HTTP Router
- PSR-7 Support
- Middleware
- Dependency Injection
