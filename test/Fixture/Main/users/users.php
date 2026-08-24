<?php

namespace Controller\users;

class users {

    function get(array $params) {
        return ['handler' => 'users', 'method' => 'get', 'params' => $params];
    }

    function post(array $params) {
        return ['handler' => 'users', 'method' => 'post', 'params' => $params];
    }
}
