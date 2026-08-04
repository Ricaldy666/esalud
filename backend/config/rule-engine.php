<?php

return [

    'enabled' => env('RULE_ENGINE_ENABLED', false),

    'mode' => env('RULE_ENGINE_MODE', 'disabled'),

    'fail_open' => env('RULE_ENGINE_FAIL_OPEN', true),

    'log_mode' => env('RULE_ENGINE_LOG_MODE', 'all'),

];
