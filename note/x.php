<?php

$uri = '/{ab?}{d?}/';
$matches = null;

preg_match_all('/\{(\w+?)\?\}/', $uri, $matches);

var_dump($matches);




