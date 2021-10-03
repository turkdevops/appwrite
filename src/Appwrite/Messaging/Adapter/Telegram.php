<?php

namespace Appwrite\Messaging\Adapter;

use Appwrite\Messaging\Messaging;

class Telegram extends Messaging {

    /**
     * @var string
     */
    protected $name = 'Telegram';

    /**
     * @var bool
     */
    protected $subscription = false;
}