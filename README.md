# composer-extra-dependency

[![Build Status](https://travis-ci.org/webimpress/composer-extra-dependency.svg?branch=master)](https://travis-ci.org/webimpress/composer-extra-dependency)
[![Coverage Status](https://coveralls.io/repos/github/webimpress/composer-extra-dependency/badge.svg?branch=master)](https://coveralls.io/github/webimpress/composer-extra-dependency?branch=master)

This composer plugin allows you to require composer dependencies in version
specified by user during the installation. It modifies the `composer.json` file
and add required package in `require` section.

This can be useful when your library supports multiple version of some
dependencies and you'd like to force user to use an explicit dependency instead
of depending on implicit dependencies from your library.

## Usage

Require the package in your library:
```console
# composer require webimpress/composer-extra-dependency
```

Update your `composer.json` file: in section `extra.dependency` add package(s)
you'd like to install with your library:

```json
{
    "name": "my/package",
    "description": "This is my package",
    "extra": {
        "dependency": [
            "package/to-require",
            ...
        ]
    },
    "require": {
        "php": "^5.6 || ^7.0",
        "webimpress/composer-extra-dependency": "^0.1 || ^1.0",
        ...
    }
    ...  
}
```

Then, during installation of your library, user will be prompted:
```bash
# Enter the version of package/to-require to require (or leave blank to use the latest version): 
```

After providing the version, `composer.json` of the user will be update
(package will be added in `require` section with version provided by user).

If user does not provide the version, plugin will try to find package in the
latest version matching platform requirements and other dependencies.
Here also `composer.json` will be updated and package will be installed.

Plugin runs always on post update/install package to check if there are some
dependencies to require explicitly in user `composer.json` file.

> If dependency is already provided in user `composer.json` (`require` or
> `require-dev` section) the plugin is not going to do anything.

> Please note plugin works only in __development interactive mode__.
> It means when `--no-dev` or `--no-interaction` flags are provided,
> plugin is not going to do anything.
