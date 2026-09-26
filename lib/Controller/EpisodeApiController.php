<?php

declare(strict_types=1);

namespace OCA\Recall\Controller;

use OCA\Recall\Service\EpisodeService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * API OCS dos episodios.
 *
 * E OCS de proposito, e nao uma rota normal: as rotas OCS sao chamaveis com
 * app password sem sessao de browser, que e o que permite a um agente usa-las.
 * Rotas normais exigem o token CSRF e respondem HTTP 412 a quem tente.
 */
class EpisodeApiController extends OCSController {
	public function __construct(
		string $appName,
		IRequest $request,
		private EpisodeService $service,
		private IUserSession $userSession,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function index(): DataResponse {
		return $this->run(fn (string $uid): array => [
			'episodes' => $this->service->list($uid, $this->request->getParams()),
		]);
	}

	/** "Neste dia (ou nesta janela) em anos anteriores." */
	#[NoAdminRequired]
	public function onThisDay(): DataResponse {
		return $this->run(fn (string $uid): array => [
			'years' => $this->service->onThisDay($uid, $this->request->getParams()),
		]);
	}

	#[NoAdminRequired]
	public function show(int $id): DataResponse {
		return $this->run(fn (string $uid): array => $this->service->get($id, $uid));
	}

	#[NoAdminRequired]
	public function create(): DataResponse {
		return $this->run(
			fn (string $uid): array => $this->service->create($uid, $this->request->getParams()),
			Http::STATUS_CREATED,
		);
	}

	#[NoAdminRequired]
	public function update(int $id): DataResponse {
		return $this->run(fn (string $uid): array => $this->service->update($id, $uid, $this->request->getParams()));
	}

	#[NoAdminRequired]
	public function destroy(int $id): DataResponse {
		return $this->run(function (string $uid) use ($id): array {
			if (!$this->service->delete($id, $uid)) {
				throw new DoesNotExistException('episodio ' . $id . ' nao existe.');
			}
			return ['deleted' => true, 'id' => $id];
		});
	}

	/**
	 * Um so sitio a tratar identidade e erros, para os metodos acima ficarem
	 * a dizer apenas o que fazem.
	 *
	 * @param callable(string): array $handler
	 */
	private function run(callable $handler, int $okStatus = Http::STATUS_OK): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse(['error' => 'sem utilizador autenticado.'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			return new DataResponse($handler($user->getUID()), $okStatus);
		} catch (DoesNotExistException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}
}
