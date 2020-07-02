<?php

$router->group(['prefix' => 'api'], function () use ($router) {
    $router->post('/send-code', ['as' => 'send-code', 'uses' => 'EmailVerificationController@sendCode']);
    $router->post('/check-code', ['as' => 'check-code', 'uses' => 'EmailVerificationController@checkCode']);
});
