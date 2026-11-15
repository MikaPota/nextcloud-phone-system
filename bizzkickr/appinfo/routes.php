<?php

declare(strict_types=1);

return [
	'routes' => [
		['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
		['name' => 'page#state', 'url' => '/state', 'verb' => 'GET'],
		['name' => 'page#register', 'url' => '/register', 'verb' => 'POST'],
		['name' => 'page#refresh', 'url' => '/refresh', 'verb' => 'POST'],
		['name' => 'page#createCard', 'url' => '/card', 'verb' => 'POST'],
		['name' => 'page#saveCard', 'url' => '/card', 'verb' => 'PUT'],
		['name' => 'page#qr', 'url' => '/card/qr', 'verb' => 'GET'],
		['name' => 'page#send', 'url' => '/send', 'verb' => 'POST'],
		['name' => 'page#requests', 'url' => '/requests', 'verb' => 'GET'],
		['name' => 'page#answer', 'url' => '/requests/{id}/{verb}', 'verb' => 'POST'],
		['name' => 'page#sync', 'url' => '/sync', 'verb' => 'POST'],
		['name' => 'setup#state', 'url' => '/setup/state', 'verb' => 'GET'],
		['name' => 'setup#start', 'url' => '/setup/signup', 'verb' => 'POST'],
		['name' => 'setup#confirm', 'url' => '/setup/signup/confirm', 'verb' => 'POST'],
		['name' => 'setup#resend', 'url' => '/setup/signup/resend', 'verb' => 'POST'],
		['name' => 'setup#cancel', 'url' => '/setup/signup', 'verb' => 'DELETE'],
	],
];
