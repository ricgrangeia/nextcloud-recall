<?php

declare(strict_types=1);

namespace OCA\Recall\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getEpisodeId()
 * @method void setEpisodeId(int $episodeId)
 * @method string getKind()
 * @method void setKind(string $kind)
 * @method string getRef()
 * @method void setRef(string $ref)
 * @method string getLabel()
 * @method void setLabel(string $label)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 */
class Link extends Entity implements \JsonSerializable {
	public const KINDS = ['contact', 'photo', 'event', 'task'];

	protected $episodeId;
	protected $kind;
	protected $ref;
	protected $label;
	protected $createdAt;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('episodeId', 'integer');
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'episode_id' => $this->episodeId,
			'kind' => $this->kind,
			'ref' => $this->ref,
			'label' => $this->label,
		];
	}
}
