#!/usr/bin/php
<?php

require(__DIR__.'/Framework/Parser.php');

$config = include __DIR__ .'/website.config.php';

//Añadir opcion de minimizacion HTML

foreach( $config['templates'] as $template ){
	$parser = Parser::from_file($template, $config['vars']);

	$parser->parse_template();

	$parser->save();

   echo "\n Converted {$template} file";
}