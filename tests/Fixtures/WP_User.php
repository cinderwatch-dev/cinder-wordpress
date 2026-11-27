<?php

class WP_User
{
    public function __construct(
        public int $ID = 0,
        public string $user_email = '',
    ) {}

    public function exists(): bool
    {
        return $this->ID !== 0;
    }
}
