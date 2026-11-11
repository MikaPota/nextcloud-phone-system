<?php

declare(strict_types=1);

return [
	'routes' => [
		['name' => 'page#phone', 'url' => '/', 'verb' => 'GET'],
		['name' => 'session#mint', 'url' => '/session', 'verb' => 'POST'],
		['name' => 'calls#index', 'url' => '/calls', 'verb' => 'GET'],
		['name' => 'calls#create', 'url' => '/calls', 'verb' => 'POST'],
		['name' => 'calls#lookup', 'url' => '/lookup', 'verb' => 'GET'],
		['name' => 'admin#state', 'url' => '/admin/state', 'verb' => 'GET'],
		['name' => 'admin#tenant', 'url' => '/admin/tenant', 'verb' => 'POST'],
		['name' => 'admin#assign', 'url' => '/admin/mapping', 'verb' => 'POST'],
		['name' => 'admin#unassign', 'url' => '/admin/mapping/{user}', 'verb' => 'DELETE'],
		['name' => 'setup#page', 'url' => '/setup', 'verb' => 'GET'],
		['name' => 'setup#state', 'url' => '/setup/state', 'verb' => 'GET'],
		['name' => 'setup#catalog', 'url' => '/setup/catalog', 'verb' => 'GET'],
		['name' => 'setup#checkout', 'url' => '/setup/checkout', 'verb' => 'POST'],
		['name' => 'setup#confirm', 'url' => '/setup/checkout/confirm', 'verb' => 'POST'],
		['name' => 'setup#resend', 'url' => '/setup/checkout/resend', 'verb' => 'POST'],
		['name' => 'setup#users', 'url' => '/setup/systems/{id}/users', 'verb' => 'POST'],
		['name' => 'setup#billing', 'url' => '/setup/systems/{id}/billing', 'verb' => 'POST'],
	],
];
