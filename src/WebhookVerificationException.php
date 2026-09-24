<?php

namespace NameGender;

/**
 * The request is not a genuine NameGender webhook. Answer it with 400.
 */
class WebhookVerificationException extends NameGenderException
{
    public function __construct(string $message)
    {
        parent::__construct($message);
    }
}
