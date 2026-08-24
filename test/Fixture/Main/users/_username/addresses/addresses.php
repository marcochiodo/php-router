<?php

namespace Controller\users\_username\addresses;

class addresses {

    function get(array $params) {
        return ['handler' => 'addresses', 'method' => 'get', 'params' => $params];
    }
}
