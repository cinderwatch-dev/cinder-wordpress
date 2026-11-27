<?php

test('it will not use any debug function')
    ->expect(['dd', 'ray', 'dump', 'var_dump'])
    ->not()
    ->toBeUsed();
