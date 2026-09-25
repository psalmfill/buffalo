<?php

test('the health check route responds with 200', function () {
    $this->get('/up')->assertOk();
});
