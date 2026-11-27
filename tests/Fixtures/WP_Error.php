<?php

class WP_Error
{
    public function __construct(
        public string $code = '',
        public string $message = '',
    ) {}

    public function get_error_message(): string
    {
        return $this->message;
    }
}
