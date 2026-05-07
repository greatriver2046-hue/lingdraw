<?php

namespace app\middleware;

use think\Response;

class Cors
{
    public function handle($request, \Closure $next)
    {
        $header = [
            'Access-Control-Allow-Origin'  => '*',
            'Access-Control-Allow-Methods' => 'GET, POST, PATCH, PUT, DELETE, OPTIONS',
            'Access-Control-Allow-Headers' => 'Authorization, Content-Type, If-Match, If-Modified-Since, If-None-Match, If-Unmodified-Since, X-Requested-With',
        ];

        if ($request->method(true) == 'OPTIONS') {
            $response = Response::create('ok')->code(200)->header($header);
            return $response;
        }

        $response = $next($request);
        
        // Ensure response is an object before adding headers (Response class)
        if ($response instanceof Response) {
             $response->header($header);
        }
        
        return $response;
    }
}
