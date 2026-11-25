<?php

declare(strict_types=1);

it('serves the welcome page', function () {
    $response = $this->get('/');

    $response->assertStatus(200);
});
