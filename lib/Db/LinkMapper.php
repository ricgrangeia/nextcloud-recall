<?php

declare(strict_types=1);

namespace OCA\Recall\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<Link>
 */
class LinkMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'recall_links', Link::class);
	}

	/** @return Link[] */
	public function findByEpisode(int $episodeId): array {
		return $this->findByEpisodes([$episodeId]);
	}

	/**
	 * Em lote, para nao fazer uma consulta por episodio ao hidratar uma lista.
	 *
	 * @param int[] $episodeIds
	 * @return Link[]
	 */
	public function findByEpisodes(array $episodeIds): array {
		if ($episodeIds === []) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->in(
				'episode_id',
				$qb->createNamedParameter($episodeIds, IQueryBuilder::PARAM_INT_ARRAY)
			))
			->orderBy('id', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * O sentido inverso: que episodios tocam esta pessoa/foto/evento/tarefa.
	 * E metade do valor da tabela -- "tudo o que me lembro sobre a Sofia".
	 *
	 * @return int[] ids de episodios
	 */
	public function findEpisodeIdsByRef(string $kind, string $ref): array {
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('episode_id')
			->from($this->getTableName())
			->where($qb->expr()->eq('kind', $qb->createNamedParameter($kind)))
			->andWhere($qb->expr()->eq('ref', $qb->createNamedParameter($ref)));

		$ids = [];
		$result = $qb->executeQuery();
		while ($row = $result->fetch()) {
			$ids[] = (int)$row['episode_id'];
		}
		$result->closeCursor();

		return $ids;
	}

	public function deleteByEpisode(int $episodeId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq(
				'episode_id',
				$qb->createNamedParameter($episodeId, IQueryBuilder::PARAM_INT)
			));
		$qb->executeStatement();
	}
}
