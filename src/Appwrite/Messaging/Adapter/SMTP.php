<?php

namespace Appwrite\Messaging\Adapter;

use Appwrite\Messaging\Messaging;

class SMTP extends Messaging {

    /**
     * @var string
     */
    protected $name = 'SMTP';

    /**
     * @var bool
     */
    protected $subscription = true;
}