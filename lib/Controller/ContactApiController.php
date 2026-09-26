<?php

declare(strict_types=1);

namespace OCA\Recall\Controller;

use OCA\Recall\Db\LinkMapper;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\Contacts\IManager;
use OCP\IRequest;

/**
 * Pesquisa de contactos, so para alimentar o seletor da interface.
 *
 * Existe em vez de o JavaScript ir diretamente a alguma API do core porque o
 * que o recall precisa e muito especifico: o UID do CardDAV (o identificador
 * estavel que fica gravado na ligacao) e um nome legivel para o rotulo. Assim
 * a forma dos dados e definida aqui e nao depende do formato de uma API de
 * terceiros mudar.
 */
class ContactApiController extends OCSController {
	public function __construct(
		string $appName,
		IRequest $request,
		private IManager $contacts,
		private LinkMapper $links,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function search(string $q = '', int $limit = 10): DataResponse {
		$q = trim($q);
		if (mb_strlen($q) < 2) {
			return new DataResponse(['contacts' => []]);
		}
		if (!$this->contacts->isEnabled()) {
			return new DataResponse(['contacts' => []], Http::STATUS_OK);
		}

		$found = $this->contacts->search($q, ['FN', 'EMAIL'], [
			'limit' => max(1, min($limit, 25)),
			'enumeration' => false,
		]);

		$out = [];
		foreach ($found as $contact) {
			// O livro de enderecos do sistema devolve utilizadores do
			// Nextcloud, que nao sao contactos do CardDAV e nao tem UID util
			// para gravar numa ligacao.
			if (!empty($contact['isLocalSystemBook'])) {
				continue;
			}
			$uid = (string)($contact['UID'] ?? '');
			$name = trim((string)($contact['FN'] ?? ''));
			if ($uid === '' || $name === '') {
				continue;
			}
			// O email e a organizacao vao para se poder DISTINGUIR homonimos.
			// Sem eles, duas pessoas chamadas "Sofia" chegam ao agente (e ao
			// seletor da interface) como duas entradas identicas, e escolher
			// uma e adivinhar -- uma ligacao errada que parece certa, porque o
			// rotulo gravado diz "Sofia" de qualquer maneira.
			$out[] = [
				'uid' => $uid,
				'name' => $name,
				'email' => self::first($contact['EMAIL'] ?? null),
				'org' => self::first($contact['ORG'] ?? null),
			];
		}

		// Desempate: quantas memorias ja foram ligadas a cada candidato. Uma
		// escolha anterior do utilizador vale mais do que a ordem da pesquisa.
		$usos = $this->links->countByRefs('contact', array_column($out, 'uid'));
		foreach ($out as &$contacto) {
			$contacto['used_before'] = $usos[$contacto['uid']] ?? 0;
		}
		unset($contacto);

		usort($out, static fn (array $a, array $b): int => $b['used_before'] <=> $a['used_before']);

		return new DataResponse(['contacts' => $out]);
	}

	/** Estes campos tanto vem como string unica como array de valores. */
	private static function first(mixed $value): string {
		if (is_array($value)) {
			$value = reset($value);
		}
		if (is_array($value)) {              // ex: ['type' => 'HOME', 'value' => '...']
			$value = $value['value'] ?? '';
		}
		return trim((string)$value);
	}
}
