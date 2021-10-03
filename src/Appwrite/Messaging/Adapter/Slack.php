<?php

namespace Appwrite\Messaging\Adapter;

use Appwrite\Messaging\Messaging;

class Slack extends Messaging {

    /**
     * @var string
     */
    protected $name = 'Slack';

    /**
     * @var bool
     */
    protected $subscription = false;
}