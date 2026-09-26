<?php

declare(strict_types=1);

namespace OCA\Recall\Service;

use OCA\Recall\Db\Episode;
use OCA\Recall\Db\EpisodeMapper;
use OCA\Recall\Db\Link;
use OCA\Recall\Db\LinkMapper;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Toda a logica de episodios. O controller so traduz HTTP; a validacao e a
 * hidratacao das ligacoes vivem aqui, para poderem ser reutilizadas depois
 * pela UI e por um eventual job em fundo sem passar por uma chamada HTTP.
 */
class EpisodeService {
	private const PRECISIONS = ['day', 'month', 'year'];

	public function __construct(
		private EpisodeMapper $episodes,
		private LinkMapper $links,
	) {
	}

	/** @return array<int, array> episodios ja com as ligacoes */
	public function list(string $userId, array $params): array {
		$kind = self::str($params['link_kind'] ?? null);
		$ref = self::str($params['link_ref'] ?? null);
		$limit = (int)($params['limit'] ?? 50);
		$offset = (int)($params['offset'] ?? 0);

		// Pesquisa inversa: "tudo o que me lembro sobre esta pessoa/foto/evento".
		if ($kind !== null && $ref !== null) {
			$ids = $this->links->findEpisodeIdsByRef($kind, $ref);
			return $this->hydrate($this->episodes->findByIds($userId, $ids, $limit, $offset));
		}

		return $this->hydrate($this->episodes->findAll(
			$userId,
			self::date($params['from'] ?? null, 'from'),
			self::date($params['to'] ?? null, 'to'),
			self::str($params['type'] ?? null),
			self::str($params['q'] ?? null),
			$limit,
			$offset,
		));
	}

	/**
	 * Agrupado por ano porque e assim que a pergunta e feita -- "no ano
	 * passado", "ha tres anos" -- e nao como uma lista corrida.
	 *
	 * @return array<string, array>
	 */
	public function onThisDay(string $userId, array $params): array {
		$reference = self::date($params['date'] ?? null, 'date') ?? new \DateTimeImmutable('today');
		$window = (int)($params['window'] ?? 0);
		$yearsBack = (int)($params['years_back'] ?? 5);

		$grouped = [];
		foreach ($this->hydrate($this->episodes->onThisDay($userId, $reference, $window, $yearsBack)) as $episode) {
			$year = substr((string)$episode['occurred_at'], 0, 4);
			$grouped[$year][] = $episode;
		}

		return $grouped;
	}

	/** @throws DoesNotExistException */
	public function get(int $id, string $userId): array {
		return $this->hydrate([$this->episodes->find($id, $userId)])[0];
	}

	/** @throws \InvalidArgumentException */
	public function create(string $userId, array $data): array {
		$title = self::str($data['title'] ?? null);
		if ($title === null) {
			throw new \InvalidArgumentException('title e obrigatorio.');
		}
		$occurred = self::date($data['occurred_at'] ?? null, 'occurred_at');
		if ($occurred === null) {
			throw new \InvalidArgumentException('occurred_at e obrigatorio (YYYY-MM-DD).');
		}

		$now = (new \DateTimeImmutable())->format(DATE_ATOM);

		$episode = new Episode();
		$episode->setUserId($userId);
		$episode->applyOccurredAt($occurred);
		$episode->setOccurredPrecision(self::precision($data['occurred_precision'] ?? null));
		$episode->setTitle(mb_substr($title, 0, 255));
		$episode->setBody(self::str($data['body'] ?? null));
		$episode->setType(mb_substr(self::str($data['type'] ?? null) ?? '', 0, 32));
		$episode->setMeta(self::meta($data['meta'] ?? null));
		$episode->setSource(mb_substr(self::str($data['source'] ?? null) ?? 'manual', 0, 32));
		$episode->setCreatedAt($now);
		$episode->setUpdatedAt($now);

		$episode = $this->episodes->insert($episode);
		$this->replaceLinks((int)$episode->getId(), $data['links'] ?? null);

		return $this->hydrate([$episode])[0];
	}

	/**
	 * Atualizacao parcial: so mexe nos campos presentes. `links`, se vier,
	 * substitui o conjunto todo -- e mais previsivel do que tentar adivinhar
	 * quais acrescentar e quais remover.
	 *
	 * @throws DoesNotExistException|\InvalidArgumentException
	 */
	public function update(int $id, string $userId, array $data): array {
		$episode = $this->episodes->find($id, $userId);

		if (array_key_exists('occurred_at', $data)) {
			$occurred = self::date($data['occurred_at'], 'occurred_at');
			if ($occurred === null) {
				throw new \InvalidArgumentException('occurred_at invalido (YYYY-MM-DD).');
			}
			$episode->applyOccurredAt($occurred);
		}
		if (array_key_exists('occurred_precision', $data)) {
			$episode->setOccurredPrecision(self::precision($data['occurred_precision']));
		}
		if (array_key_exists('title', $data)) {
			$title = self::str($data['title']);
			if ($title === null) {
				throw new \InvalidArgumentException('title nao pode ficar vazio.');
			}
			$episode->setTitle(mb_substr($title, 0, 255));
		}
		if (array_key_exists('body', $data)) {
			$episode->setBody(self::str($data['body']));
		}
		if (array_key_exists('type', $data)) {
			$episode->setType(mb_substr(self::str($data['type']) ?? '', 0, 32));
		}
		if (array_key_exists('meta', $data)) {
			$episode->setMeta(self::meta($data['meta']));
		}

		$episode->setUpdatedAt((new \DateTimeImmutable())->format(DATE_ATOM));
		$episode = $this->episodes->update($episode);

		if (array_key_exists('links', $data)) {
			$this->replaceLinks($id, $data['links']);
		}

		return $this->hydrate([$episode])[0];
	}

	public function delete(int $id, string $userId): bool {
		// O episodio vai PRIMEIRO, e a ordem e de seguranca, nao de arrumacao:
		// deleteById() e que verifica o dono. O deleteByEpisode() do LinkMapper
		// so conhece o id do episodio, portanto apagar as ligacoes antes
		// deixaria qualquer utilizador destruir as ligacoes de outro bastando
		// adivinhar um id.
		if (!$this->episodes->deleteById($id, $userId)) {
			return false;
		}
		$this->links->deleteByEpisode($id);
		return true;
	}

	/**
	 * @param Episode[] $episodes
	 * @return array<int, array>
	 */
	private function hydrate(array $episodes): array {
		if ($episodes === []) {
			return [];
		}

		$ids = array_map(static fn (Episode $e): int => (int)$e->getId(), $episodes);
		$byEpisode = [];
		foreach ($this->links->findByEpisodes($ids) as $link) {
			$byEpisode[$link->getEpisodeId()][] = $link->jsonSerialize();
		}

		$out = [];
		foreach ($episodes as $episode) {
			$row = $episode->jsonSerialize();
			$row['links'] = $byEpisode[(int)$episode->getId()] ?? [];
			$out[] = $row;
		}

		return $out;
	}

	private function replaceLinks(int $episodeId, mixed $links): void {
		if ($links === null) {
			return;
		}
		if (!is_array($links)) {
			throw new \InvalidArgumentException('links tem de ser uma lista.');
		}

		$this->links->deleteByEpisode($episodeId);
		$now = (new \DateTimeImmutable())->format(DATE_ATOM);

		foreach ($links as $raw) {
			if (!is_array($raw)) {
				continue;
			}
			$kind = self::str($raw['kind'] ?? null);
			$ref = self::str($raw['ref'] ?? null);
			if ($kind === null || $ref === null) {
				throw new \InvalidArgumentException('cada ligacao precisa de "kind" e "ref".');
			}
			if (!in_array($kind, Link::KINDS, true)) {
				throw new \InvalidArgumentException(
					'kind invalido: ' . $kind . ' (validos: ' . implode(', ', Link::KINDS) . ')'
				);
			}

			$link = new Link();
			$link->setEpisodeId($episodeId);
			$link->setKind($kind);
			$link->setRef(mb_substr($ref, 0, 255));
			// Sem rotulo, uma ligacao cujo alvo desapareca fica ilegivel.
			$link->setLabel(mb_substr(self::str($raw['label'] ?? null) ?? $ref, 0, 255));
			$link->setCreatedAt($now);
			$this->links->insert($link);
		}
	}

	private static function str(mixed $value): ?string {
		if (!is_string($value)) {
			return null;
		}
		$value = trim($value);
		return $value === '' ? null : $value;
	}

	private static function precision(mixed $value): string {
		$value = self::str($value);
		return in_array($value, self::PRECISIONS, true) ? $value : 'day';
	}

	private static function meta(mixed $value): ?string {
		if ($value === null || $value === '') {
			return null;
		}
		if (is_array($value)) {
			return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		}
		if (is_string($value) && json_decode($value) !== null) {
			return $value;
		}
		throw new \InvalidArgumentException('meta tem de ser um objeto JSON.');
	}

	/** @throws \InvalidArgumentException */
	private static function date(mixed $value, string $field): ?\DateTimeImmutable {
		$value = self::str($value);
		if ($value === null) {
			return null;
		}
		try {
			return new \DateTimeImmutable($value);
		} catch (\Throwable) {
			throw new \InvalidArgumentException($field . ' nao e uma data valida: ' . $value);
		}
	}
}
