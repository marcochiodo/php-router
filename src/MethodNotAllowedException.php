<?php

namespace mrblue\PhpRouter;

class MethodNotAllowedException extends RouterException {

    /**
     * @param string[] $allowed HTTP methods (uppercase) available on the matched handler
     */
    function __construct(
        string $message,
        public readonly array $allowed = [],
    ) {
        parent::__construct($message);
    }
}
