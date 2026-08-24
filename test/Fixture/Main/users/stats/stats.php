<?php

namespace Controller\users\stats;

class stats {

    function get(array $params) {
        return ['handler' => 'stats', 'method' => 'get', 'params' => $params];
    }
}
