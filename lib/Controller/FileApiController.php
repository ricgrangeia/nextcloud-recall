<?php

declare(strict_types=1);

namespace OCA\Recall\Controller;

use OCA\Recall\Service\FileLinkService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Resolve uma ligacao interna do Nextcloud no ficheiro a que se refere.
 *
 * Existe para a interface poder confirmar de imediato o que foi colado --
 * colar um URL e nao receber sinal nenhum e mau, e so se descobriria que
 * estava errado depois de gravar a memoria.
 */
class FileApiController extends OCSController {
	public function __construct(
		string $appName,
		IRequest $request,
		private FileLinkService $files,
		private IUserSession $userSession,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function resolve(string $link = ''): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse(['error' => 'sem utilizador autenticado.'], Http::STATUS_UNAUTHORIZED);
		}

		$resolved = $this->files->resolve($user->getUID(), $link);
		if ($resolved === null) {
			return new DataResponse(
				['error' => 'nao encontrei nenhum ficheiro teu nessa ligacao.'],
				Http::STATUS_NOT_FOUND,
			);
		}

		return new DataResponse($resolved);
	}
}
