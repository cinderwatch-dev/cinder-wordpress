<?php

namespace Cinderwatch\WordPress\Tests\Fixtures;

class WpCliCommand
{
    /**
     * @param  array<string, WpCliCommand>  $subcommands
     */
    public function __construct(
        private readonly array $subcommands = [],
    ) {}

    /**
     * @return array<string, WpCliCommand>
     */
    public function get_subcommands(): array
    {
        return $this->subcommands;
    }
}
