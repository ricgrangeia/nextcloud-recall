<?php

declare(strict_types=1);

namespace OCA\Recall\Controller;

use OCA\Recall\AppInfo\Application;
use OCA\Recall\Service\EpisodeService;
use OCA\Recall\Service\FileLinkService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;

/**
 * A interface. Renderizada no servidor, com formularios normais que
 * funcionam sozinhos; o js/recall.js e melhoria progressiva por cima disso
 * (evita recarregamentos e traz os seletores). Sem pipeline de build em
 * nenhum dos casos.
 */
class PageController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private EpisodeService $service,
		private FileLinkService $files,
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
		// Melhoria progressiva: sem isto a pagina continua a funcionar por
		// POST normal, so com recarregamento.
		\OCP\Util::addScript(Application::APP_ID, 'recall');

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
			// Os URLs da API OCS sao gerados aqui pelo router e entregues ao
			// JavaScript em atributos data-, em vez de o cliente os montar a
			// partir de suposicoes sobre o webroot.
			'ocsEpisodesUrl' => rtrim($this->urlGenerator->linkToOCSRouteAbsolute('recall.episodeApi.index'), '/'),
			'ocsContactsUrl' => rtrim($this->urlGenerator->linkToOCSRouteAbsolute('recall.contactApi.search'), '/'),
			'ocsFilesUrl' => rtrim($this->urlGenerator->linkToOCSRouteAbsolute('recall.fileApi.resolve'), '/'),
			// Prefixo da ligacao interna, para as ligacoes a ficheiros na lista
			// serem clicaveis: basta acrescentar-lhe o file id.
			'fileLinkBase' => $this->urlGenerator->getAbsoluteURL('/f/'),
		]);
	}

	#[NoAdminRequired]
	public function create(): RedirectResponse {
		// A ligacao ao contacto so se grava se vier o UID. O nome sozinho nao
		// serve: e o UID que sobrevive a pessoa mudar de nome, e gravar um sem
		// o outro daria uma ligacao que aponta para lado nenhum.
		$links = [];
		$contactRef = trim((string)$this->request->getParam('link_contact_ref', ''));
		$contactLabel = trim((string)$this->request->getParam('link_contact_label', ''));
		if ($contactRef !== '') {
			$links[] = [
				'kind' => 'contact',
				'ref' => $contactRef,
				'label' => $contactLabel !== '' ? $contactLabel : $contactRef,
			];
		}

		// A ligacao a ficheiro e sempre resolvida no servidor, mesmo quando o
		// JavaScript ja a mostrou resolvida: o que vem do cliente nao decide
		// a que ficheiro se fica ligado, nem se ha acesso a ele.
		$fileLink = trim((string)$this->request->getParam('link_file', ''));
		if ($fileLink !== '') {
			$resolved = $this->files->resolve($this->uid(), $fileLink);
			if ($resolved === null) {
				return $this->backWithError(
					'Nao encontrei nenhum ficheiro teu nessa ligacao: ' . $fileLink
				);
			}
			$links[] = $resolved;
		}

		try {
			$this->service->create($this->uid(), [
				'title' => $this->request->getParam('title'),
				'occurred_at' => $this->request->getParam('occurred_at'),
				'occurred_precision' => $this->request->getParam('occurred_precision'),
				'type' => $this->request->getParam('type'),
				'body' => $this->request->getParam('body'),
				'source' => 'manual',
				'links' => $links,
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
