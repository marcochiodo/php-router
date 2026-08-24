<?php

namespace Controller\users\_username;

class username {

    function get(array $params) {
        return ['handler' => 'username', 'method' => 'get', 'params' => $params];
    }

    function patch(array $params) {
        return ['handler' => 'username', 'method' => 'patch', 'params' => $params];
    }

    function delete(array $params) {
        return ['handler' => 'username', 'method' => 'delete', 'params' => $params];
    }
}
