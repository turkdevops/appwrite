<?php

namespace Appwrite\Messaging\Adapter;

use Appwrite\Messaging\Messaging;

class Websocket extends Messaging {

    /**
     * @var string
     */
    protected $name = 'Websocket';

    /**
     * @var bool
     */
    protected $subscription = true;

    // Send message to websocket: https://stackoverflow.com/questions/42955033/php-client-web-socket-to-send-messages
}