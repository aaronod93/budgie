<?php

test('health endpoint responds', function () {
    $this->get('/up')->assertOk();
});
