<?php

declare(strict_types=1);

namespace OCA\Recall\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<Episode>
 */
class EpisodeMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'recall_episodes', Episode::class);
	}

	/** @throws \OCP\AppFramework\Db\DoesNotExistException */
	public function find(int $id, string $userId): Episode {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

		return $this->findEntity($qb);
	}

	/**
	 * Consulta geral, com todos os filtros opcionais.
	 *
	 * @return Episode[]
	 */
	public function findAll(
		string $userId,
		?\DateTimeInterface $from = null,
		?\DateTimeInterface $to = null,
		?string $type = null,
		?string $search = null,
		int $limit = 50,
		int $offset = 0,
	): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

		// occurred_at e uma coluna de texto 'YYYY-MM-DD' (ver a migracao), por
		// isso isto e comparacao de string com string -- sem conversoes de tipo
		// implicitas, que e onde o PostgreSQL costuma recusar.
		if ($from !== null) {
			$qb->andWhere($qb->expr()->gte(
				'occurred_at',
				$qb->createNamedParameter($from->format('Y-m-d'))
			));
		}
		if ($to !== null) {
			$qb->andWhere($qb->expr()->lte(
				'occurred_at',
				$qb->createNamedParameter($to->format('Y-m-d'))
			));
		}
		if ($type !== null && $type !== '') {
			$qb->andWhere($qb->expr()->eq('type', $qb->createNamedParameter($type)));
		}
		if ($search !== null && trim($search) !== '') {
			$like = '%' . $this->db->escapeLikeParameter(trim($search)) . '%';
			$qb->andWhere($qb->expr()->orX(
				$qb->expr()->iLike('title', $qb->createNamedParameter($like)),
				$qb->expr()->iLike('body', $qb->createNamedParameter($like)),
			));
		}

		$qb->orderBy('occurred_at', 'DESC')
			->addOrderBy('id', 'DESC')
			->setMaxResults(max(1, min($limit, 500)))
			->setFirstResult(max(0, $offset));

		return $this->findEntities($qb);
	}

	/**
	 * "Neste dia (ou nesta janela) em anos anteriores" -- a consulta que
	 * justifica esta app existir.
	 *
	 * A janela e calculada em PHP e convertida numa lista de MMDD, em vez de
	 * ser feita com aritmetica de datas em SQL: assim os meses de tamanhos
	 * diferentes, a viragem do ano e os bissextos resolvem-se sozinhos, e a
	 * consulta fica a comparar inteiros -- identica em MySQL, PostgreSQL e
	 * SQLite.
	 *
	 * @return Episode[] ordenados do mais recente para o mais antigo
	 */
	public function onThisDay(
		string $userId,
		\DateTimeInterface $reference,
		int $windowDays = 0,
		int $yearsBack = 5,
	): array {
		$monthDays = self::windowMonthDays($reference, $windowDays);
		$currentYear = (int)$reference->format('Y');

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->in(
				'occurred_md',
				$qb->createNamedParameter($monthDays, IQueryBuilder::PARAM_INT_ARRAY)
			))
			->andWhere($qb->expr()->lt(
				'occurred_year',
				$qb->createNamedParameter($currentYear, IQueryBuilder::PARAM_INT)
			))
			->andWhere($qb->expr()->gte(
				'occurred_year',
				$qb->createNamedParameter($currentYear - max(1, $yearsBack), IQueryBuilder::PARAM_INT)
			))
			->orderBy('occurred_at', 'DESC');

		return $this->findEntities($qb);
	}

	/**
	 * Os MMDD abrangidos por uma janela de +/- N dias a volta de uma data.
	 *
	 * @return int[]
	 */
	public static function windowMonthDays(\DateTimeInterface $reference, int $windowDays): array {
		$windowDays = max(0, min($windowDays, 60));
		$base = new \DateTimeImmutable($reference->format('Y-m-d'));

		$out = [];
		for ($i = -$windowDays; $i <= $windowDays; $i++) {
			$day = $base->modify(($i >= 0 ? '+' : '') . $i . ' days');
			$out[] = (int)$day->format('md');
		}

		// 29 de fevereiro nunca aparece na janela quando o ano de referencia nao
		// e bissexto -- mas os episodios desse dia existem e sao precisamente os
		// que mais custa perder. Se a janela toca o fim de fevereiro, inclui-o.
		if (in_array(228, $out, true) || in_array(301, $out, true)) {
			$out[] = 229;
		}

		return array_values(array_unique($out));
	}

	/**
	 * Usado pela pesquisa inversa ("tudo o que me lembro sobre esta pessoa"):
	 * o LinkMapper devolve ids, estes trazem os episodios numa so consulta.
	 *
	 * @param int[] $ids
	 * @return Episode[]
	 */
	public function findByIds(string $userId, array $ids, int $limit = 50, int $offset = 0): array {
		if ($ids === []) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)))
			->orderBy('occurred_at', 'DESC')
			->addOrderBy('id', 'DESC')
			->setMaxResults(max(1, min($limit, 500)))
			->setFirstResult(max(0, $offset));

		return $this->findEntities($qb);
	}

	public function deleteById(int $id, string $userId): bool {
		try {
			$entity = $this->find($id, $userId);
		} catch (\OCP\AppFramework\Db\DoesNotExistException) {
			return false;
		}
		$this->delete($entity);
		return true;
	}
}
