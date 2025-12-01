# slackest

Simple Slack integration for sending messages to a channel and optionally attaching files.

## Installation

```bash
composer require itron/slackest
```

Composer will install the library and register the PSR-4 autoloader for the `iTRON\\Slackest` namespace.

## Usage

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use iTRON\Slackest\Slackest;

$slack = new Slackest('xoxb-your-bot-token', 'C12345678');

// Send a plain text message
$slack->send('Hello from Slackest!');

// Send a message with a file attachment
$slack->send('Here is a file', __DIR__ . '/report.pdf');
```

## Requirements

- PHP 7.4 or newer
- cURL extension
- JSON extension
- A Slack bot token with permissions to post messages and upload files
