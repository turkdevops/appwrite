<?php

namespace Appwrite\Messaging\Adapter;

use Appwrite\Messaging\Messaging;

class Twillio extends Messaging {

    /**
     * @var string
     */
    protected $name = 'Twillio (SMS)';

    /**
     * @var bool
     */
    protected $subscription = true;
}