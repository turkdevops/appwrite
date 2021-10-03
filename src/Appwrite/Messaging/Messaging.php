<?php

namespace Appwrite\Messaging;

abstract class Messaging {

    /**
     * @var string
     */
    protected $name = 'Unknown';

    /**
     * @var bool
     */
    protected $subscription = false;

    /**
     * Get Name
     * 
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Get Subscription
     * 
     * @return bool
     */
    public function getSubscription(): bool
    {
        return $this->subscription;
    }

    /**
     * Send Message
     * 
     * @return bool
     */
    abstract public function send($message): bool;
}