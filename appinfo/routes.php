<?php

declare(strict_types=1);

return [
	// Interface. Rotas normais (com token CSRF, sessao de browser) -- ao
	// contrario das OCS abaixo, que sao para o agente. Os formularios usam
	// POST tambem para apagar, porque HTML so suporta GET e POST.
	'routes' => [
		['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
		['name' => 'page#create', 'url' => '/create', 'verb' => 'POST'],
		['name' => 'page#destroy', 'url' => '/delete/{id}', 'verb' => 'POST'],
	],

	// Rotas OCS (nao 'routes'): sao chamaveis com app password, sem sessao de
	// browser nem token CSRF -- que e o que permite a um agente usa-las.
	// Ficam em /ocs/v2.php/apps/recall/api/v1/...
	'ocs' => [
		// ATENCAO A ORDEM: 'on-this-day' tem de vir ANTES de '{id}', senao o
		// parametro apanha a palavra e a rota especifica nunca e alcancada.
		['name' => 'episodeApi#onThisDay', 'url' => '/api/v1/episodes/on-this-day', 'verb' => 'GET'],

		['name' => 'episodeApi#index', 'url' => '/api/v1/episodes', 'verb' => 'GET'],
		['name' => 'episodeApi#create', 'url' => '/api/v1/episodes', 'verb' => 'POST'],
		['name' => 'episodeApi#show', 'url' => '/api/v1/episodes/{id}', 'verb' => 'GET'],
		['name' => 'episodeApi#update', 'url' => '/api/v1/episodes/{id}', 'verb' => 'PUT'],
		['name' => 'episodeApi#destroy', 'url' => '/api/v1/episodes/{id}', 'verb' => 'DELETE'],

		// Alimenta o seletor de contactos da interface.
		['name' => 'contactApi#search', 'url' => '/api/v1/contacts', 'verb' => 'GET'],
	],
];
