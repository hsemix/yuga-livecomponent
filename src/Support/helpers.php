<?php

// function ylc(string $name, array $params = []): string
// {
//     return \Yuga\Live\YLC::render($name, $params);
// }

function ylc(string $name, array $params = [], array $options = []): string
{
    return \Yuga\Live\YLC::render($name, $params, $options);
}