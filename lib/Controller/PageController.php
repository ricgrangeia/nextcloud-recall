<?php

declare(strict_types=1);

namespace OCA\Recall\Controller;

use OCA\Recall\AppInfo\Application;
use OCA\Recall\Service\EpisodeService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;

/**
 * A interface. Renderizada no servidor e com formularios normais, sem
 * JavaScript nem pipeline de build -- e o que permite acrescentar memorias a
 * mao sem depender do agente, que era o requisito, ao custo de recarregar a
 * pagina em cada accao.
 */
class PageController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private EpisodeService $service,
		private IUserSession $userSession,
		private IURLGenerator $urlGenerator,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * O NoCSRFRequired e obrigatorio e nao um relaxamento: o SecurityMiddleware
	 * exige token CSRF em TODOS os pedidos, GET incluido, e abrir a pagina pela
	 * barra de navegacao e um GET sem token nenhum. Sem isto a app responde
	 * "CSRF check failed" logo ao entrar.
	 *
	 * Os metodos abaixo (create/destroy) NAO levam esta anotacao de proposito:
	 * alteram estado, e o token vem no formulario.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): TemplateResponse {
		\OCP\Util::addStyle(Application::APP_ID, 'recall');

		$userId = $this->uid();
		$search = (string)($this->request->getParam('q') ?? '');

		$episodes = $this->service->list($userId, ['q' => $search, 'limit' => 200]);
		// O URL de apagar vem do router, e nao de concatenar strings no
		// template: assim nao depende de o URL do indice acabar em barra.
		foreach ($episodes as &$episode) {
			$episode['delete_url'] = $this->urlGenerator->linkToRoute(
				'recall.page.destroy',
				['id' => $episode['id']]
			);
		}
		unset($episode);

		return new TemplateResponse(Application::APP_ID, 'index', [
			'episodes' => $episodes,
			// O painel de "neste dia" no topo e o que torna visivel para que
			// serve esta app -- sem ele parece apenas mais uma lista.
			'onThisDay' => $this->service->onThisDay($userId, ['window' => 3, 'years_back' => 20]),
			'search' => $search,
			'error' => (string)($this->request->getParam('error') ?? ''),
			'createUrl' => $this->urlGenerator->linkToRoute('recall.page.create'),
			'indexUrl' => $this->urlGenerator->linkToRoute('recall.page.index'),
		]);
	}

	#[NoAdminRequired]
	public function create(): RedirectResponse {
		try {
			$this->service->create($this->uid(), [
				'title' => $this->request->getParam('title'),
				'occurred_at' => $this->request->getParam('occurred_at'),
				'occurred_precision' => $this->request->getParam('occurred_precision'),
				'type' => $this->request->getParam('type'),
				'body' => $this->request->getParam('body'),
				'source' => 'manual',
			]);
		} catch (\InvalidArgumentException $e) {
			return $this->backWithError($e->getMessage());
		}

		return $this->back();
	}

	#[NoAdminRequired]
	public function destroy(int $id): RedirectResponse {
		$this->service->delete($id, $this->uid());
		return $this->back();
	}

	private function uid(): string {
		return (string)$this->userSession->getUser()?->getUID();
	}

	private function back(): RedirectResponse {
		return new RedirectResponse($this->urlGenerator->linkToRoute('recall.page.index'));
	}

	private function backWithError(string $message): RedirectResponse {
		return new RedirectResponse(
			$this->urlGenerator->linkToRoute('recall.page.index') . '?error=' . urlencode($message)
		);
	}
}
