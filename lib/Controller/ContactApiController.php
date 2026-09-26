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

		$limite = max(1, min($limit, 25));
		$found = $this->contacts->search($q, ['FN', 'EMAIL'], [
			'limit' => $limite,
			'enumeration' => false,
		]);

		// A pesquisa do Nextcloud e substring literal: "Sofia Dias" nao encontra
		// "Sofia da Fonseca Dias", porque nao e um pedaco contiguo do nome. Sem
		// isto, dizer o nome mais completo dava MENOS resultados do que dizer so
		// o primeiro nome -- e zero resultados faz o episodio ficar sem ligacao,
		// que e o desfecho pior de todos. Cai-se para o primeiro nome e deixa-se
		// o desempate (familia, uso anterior, cobertura do nome) fazer o resto.
		$primeiro = self::firstWord($q);
		if ($found === [] && $primeiro !== $q) {
			$found = $this->contacts->search($primeiro, ['FN', 'EMAIL'], [
				'limit' => $limite,
				'enumeration' => false,
			]);
		}

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
			// RELATIONSHIP e CATEGORIES sao os sinais fortes, e sao POSTOS PELO
			// UTILIZADOR uma vez nos Contactos -- ao contrario do used_before,
			// que so existe depois de ja se ter acertado uma vez. Sao eles que
			// resolvem a primeira ligacao, que e onde o erro passa despercebido.
			//
			// Atencao ao nome: o Nextcloud grava RELATIONSHIP, nao a propriedade
			// vCard padrao RELATED. Confirmado contra contactos reais; ler
			// RELATED compila e nunca encontra nada.
			$out[] = [
				'uid' => $uid,
				'name' => $name,
				'email' => self::first($contact['EMAIL'] ?? null),
				'org' => self::first($contact['ORG'] ?? null),
				'relationship' => self::first($contact['RELATIONSHIP'] ?? null),
				'groups' => self::listOf($contact['CATEGORIES'] ?? null),
				'birthday' => self::first($contact['BDAY'] ?? null),
				'name_match' => self::nameMatch($q, $name),
			];
		}

		// Desempate: quantas memorias ja foram ligadas a cada candidato. Uma
		// escolha anterior do utilizador vale mais do que a ordem da pesquisa.
		$usos = $this->links->countByRefs('contact', array_column($out, 'uid'));
		foreach ($out as &$contacto) {
			$contacto['used_before'] = $usos[$contacto['uid']] ?? 0;
		}
		unset($contacto);

		// Ordem: familia primeiro, depois quem ja foi usado, depois quem
		// realmente SE CHAMA assim. Sem o ultimo criterio, "Prof. Clara
		// 1ºCiclo (Sofia)" chega ao agente ao mesmo nivel da propria Sofia.
		usort($out, static function (array $a, array $b): int {
			return [self::familyRank($b), $b['used_before'], self::matchRank($b['name_match'])]
				<=> [self::familyRank($a), $a['used_before'], self::matchRank($a['name_match'])];
		});

		return new DataResponse(['contacts' => $out]);
	}

	/** Familia declarada nos Contactos: relacao explicita vale mais do que grupo. */
	private static function familyRank(array $c): int {
		if ($c['relationship'] !== '') {
			return 2;
		}
		foreach ($c['groups'] as $grupo) {
			if (str_contains(self::fold($grupo), 'familia')) {
				return 1;
			}
		}
		return 0;
	}

	private static function matchRank(string $match): int {
		return ['exact' => 4, 'start' => 3, 'all_words' => 2, 'word' => 1][$match] ?? 0;
	}

	/** O primeiro nome, para quando a pesquisa pelo nome completo nao da nada. */
	private static function firstWord(string $q): string {
		$partes = preg_split('/\s+/u', trim($q), 2);
		return $partes[0] ?? $q;
	}

	/**
	 * Como e que o nome procurado bate no nome do contacto.
	 *
	 * Distingue "esta pessoa chama-se Sofia" de "esta pessoa tem Sofia escrito
	 * no nome por referencia a outra" -- que numa lista de dez resultados e a
	 * diferenca entre a filha do utilizador e a professora da filha.
	 *
	 * 'all_words' e o que salva o caso "Sofia Dias": as duas palavras estao no
	 * nome mas nao seguidas, por isso a pesquisa literal falhou e so chegamos
	 * aqui pela queda para o primeiro nome. Ter TODAS as palavras ditas e um
	 * sinal forte -- mais forte do que ter so uma.
	 */
	private static function nameMatch(string $q, string $name): string {
		$q = self::fold($q);
		$name = self::fold($name);
		if ($q === '' || $name === '') {
			return 'partial';
		}
		if ($q === $name) {
			return 'exact';
		}
		if (str_starts_with($name, $q . ' ')) {
			return 'start';
		}
		if (self::isWordIn($q, $name)) {
			return 'word';
		}

		$palavras = preg_split('/\s+/u', $q) ?: [];
		if (count($palavras) > 1) {
			foreach ($palavras as $palavra) {
				if (!self::isWordIn($palavra, $name)) {
					return 'partial';
				}
			}
			return 'all_words';
		}

		return 'partial';
	}

	/** Fronteira de palavra, para "Avo Sofia" contar e "Sofias" nao. */
	private static function isWordIn(string $agulha, string $palheiro): bool {
		return preg_match(
			'/(?:^|[^\p{L}])' . preg_quote($agulha, '/') . '(?:$|[^\p{L}])/u',
			$palheiro
		) === 1;
	}

	/** Compara nomes sem tropecar em acentos nem maiusculas. */
	private static function fold(string $s): string {
		$s = mb_strtolower(trim($s), 'UTF-8');
		if (class_exists(\Normalizer::class)) {
			$d = \Normalizer::normalize($s, \Normalizer::FORM_D);
			if (is_string($d)) {
				$s = preg_replace('/\p{Mn}/u', '', $d) ?? $s;
			}
		}
		return $s;
	}

	/**
	 * CATEGORIES vem como "Familia" ou "Familia,Trabalho", conforme o cliente
	 * que gravou o contacto.
	 *
	 * @return string[]
	 */
	private static function listOf(mixed $value): array {
		$bruto = is_array($value) ? $value : [$value];
		$out = [];
		foreach ($bruto as $item) {
			foreach (explode(',', (string)$item) as $parte) {
				$parte = trim($parte);
				if ($parte !== '') {
					$out[] = $parte;
				}
			}
		}
		return array_values(array_unique($out));
	}

	/**
	 * Diagnostico: devolve, sem filtrar, o que o Nextcloud sabe sobre cada
	 * contacto que corresponde a pesquisa.
	 *
	 * Existe porque o desempate de homonimos so pode assentar em propriedades
	 * que CHEGUEM MESMO aqui -- CATEGORIES (grupos), RELATED (conjuge, filho),
	 * NICKNAME. Construir a regra sobre a suposicao de que chegam e a melhor
	 * maneira de escrever codigo que parece certo e nao faz nada. Isto mostra
	 * o que ha antes de se decidir o que usar.
	 *
	 * O search e do IManager, logo ja esta limitado aos livros de enderecos
	 * do utilizador da sessao -- nao ha aqui forma de espreitar contactos de
	 * outra pessoa.
	 */
	#[NoAdminRequired]
	public function inspect(string $q = '', int $limit = 10): DataResponse {
		$q = trim($q);
		if ($q === '') {
			return new DataResponse(['error' => 'q e obrigatorio'], Http::STATUS_BAD_REQUEST);
		}
		if (!$this->contacts->isEnabled()) {
			return new DataResponse(['error' => 'gestor de contactos inativo'], Http::STATUS_OK);
		}

		$found = $this->contacts->search($q, ['FN', 'EMAIL', 'NICKNAME'], [
			'limit' => max(1, min($limit, 25)),
			'enumeration' => false,
		]);

		$out = [];
		foreach ($found as $contact) {
			$limpo = [];
			foreach ($contact as $prop => $valor) {
				// A foto e binaria e enche a resposta com kilobytes de base64
				// sem responder a pergunta nenhuma. Diz-se que existe e passa.
				$limpo[$prop] = ($prop === 'PHOTO')
					? '<' . strlen((string)(is_array($valor) ? reset($valor) : $valor)) . ' bytes>'
					: $valor;
			}
			$out[] = $limpo;
		}

		return new DataResponse([
			'query' => $q,
			'count' => count($out),
			// A lista das propriedades vistas em qualquer um dos resultados, para
			// se ver de relance o que existe sem ler tudo contacto a contacto.
			'properties_seen' => array_values(array_unique(array_merge(...array_map('array_keys', $out ?: [[]])))),
			'contacts' => $out,
		]);
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
