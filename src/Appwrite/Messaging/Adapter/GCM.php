<?php

namespace Appwrite\Messaging\Adapter;

use Appwrite\Messaging\Messaging;

class GCM extends Messaging {

    /**
     * @var string
     */
    protected $name = 'Google Cloud Messaging';

    /**
     * @var bool
     */
    protected $subscription = true;
}