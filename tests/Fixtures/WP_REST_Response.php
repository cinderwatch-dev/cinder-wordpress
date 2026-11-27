<?php

class WP_REST_Response
{
    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public mixed $data = null,
        public int $status = 200,
        public array $headers = [],
    ) {}
}
