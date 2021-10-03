<?php

namespace Appwrite\Messaging\Adapter;

use Appwrite\Messaging\Messaging;

class APNS extends Messaging {

    /**
     * @var string
     */
    protected $name = 'Apple Push Notification Service';

    /**
     * @var bool
     */
    protected $subscription = true;
}