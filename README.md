# Harbor for Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/emilsundberg/harbor-for-laravel.svg?style=flat-square)](https://packagist.org/packages/emilsundberg/harbor-for-laravel)

Harbor is an elegant Laravel package for managing your package forks when contributing to open source. 
It allows you to easily manage your forks by docking them in your local harbor, and then casting off back to the original package once your pull request is merged.

## Installation

You can install the package via composer:

```bash
composer require emilsundberg/harbor-for-laravel --dev
```

## Usage

Start by making a fork of the package you want to contribute to. 
Then dock the fork in your local harbor.

```bash
php artisan harbor:dock <package>
```

Once you have made your changes and your pull request is merged, you can cast off back to the original package.

```bash
php artisan harbor:depart <package>
```

## Testing

```bash
composer test
```

## Contributing

Please see [CONTRIBUTING](.github/CONTRIBUTING.md) for details.

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
