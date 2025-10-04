<?php

spl_autoload_register(function ($class) {
  $lib = __DIR__ . '/lib/';
  $file = $lib . str_replace('\\', '/', $class) . '.php';

  if (file_exists($file)) {
    require $file;
  }
});
