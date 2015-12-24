PHPAGI
------

PHPAGI is a set of PHP classes for use in developing applications with
the Asterisk Gateway Interface forked from https://github.com/d4rkstar/phpagi.

## Installation

The suggested installation method is via [composer](https://getcomposer.org/):

```sh
composer require gietos/phpagi
```

## Usage

Create a script `agi.php`

Put there:

```php
<?php

require 'vendor/autoload.php';

$a = new \gietos\AGI\Handler();
$a->init();
```

Put in `extensions.ael`:
```
context incoming {
    _7XXXXXXXXXX => {
        AGI(/path/to/agi.php);
        Hangup();
    }
}
```
