<?php

declare(strict_types=1);

namespace OCA\Recall\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getOccurredAt()
 * @method void setOccurredAt(string $occurredAt)
 * @method int getOccurredYear()
 * @method void setOccurredYear(int $occurredYear)
 * @method int getOccurredMd()
 * @method void setOccurredMd(int $occurredMd)
 * @method string getOccurredPrecision()
 * @method void setOccurredPrecision(string $occurredPrecision)
 * @method string getTitle()
 * @method void setTitle(string $title)
 * @method string|null getBody()
 * @method void setBody(?string $body)
 * @method string getType()
 * @method void setType(string $type)
 * @method string|null getMeta()
 * @method void setMeta(?string $meta)
 * @method string getSource()
 * @method void setSource(string $source)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 * @method string getUpdatedAt()
 * @method void setUpdatedAt(string $updatedAt)
 */
class Episode extends Entity implements \JsonSerializable {
	protected $userId;
	protected $occurredAt;
	protected $occurredYear;
	protected $occurredMd;
	protected $occurredPrecision;
	protected $title;
	protected $body;
	protected $type;
	protected $meta;
	protected $source;
	protected $createdAt;
	protected $updatedAt;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('occurredYear', 'integer');
		$this->addType('occurredMd', 'integer');
		// occurredAt fica deliberadamente sem addType e e tratado como string
		// 'Y-m-d'. Ligar objetos DateTime a uma coluna 'date' obriga a acertar
		// com o tipo exato que cada motor devolve na leitura, e os tres nao
		// concordam; uma string ISO e aceite por MySQL, PostgreSQL e SQLite
		// tanto a escrever como a comparar.
	}

	/**
	 * Mantem occurred_year/occurred_md coerentes com a data -- nunca sao postos
	 * a mao. O MMDD perde o zero a esquerda (5 de janeiro = 105), o que e
	 * indiferente desde que seja SEMPRE gerado por aqui: as comparacoes sao
	 * entre inteiros produzidos da mesma maneira.
	 */
	public function applyOccurredAt(\DateTimeInterface $date): void {
		$this->setOccurredAt($date->format('Y-m-d'));
		$this->setOccurredYear((int)$date->format('Y'));
		$this->setOccurredMd((int)$date->format('md'));
	}

	public function jsonSerialize(): array {
		// Tolerante ao que o motor devolver: alguns entregam a coluna 'date'
		// como string, outros ja com hora colada.
		$occurred = $this->occurredAt;
		if ($occurred instanceof \DateTimeInterface) {
			$occurred = $occurred->format('Y-m-d');
		} elseif (is_string($occurred) && $occurred !== '') {
			$occurred = substr($occurred, 0, 10);
		} else {
			$occurred = null;
		}

		return [
			'id' => $this->getId(),
			'occurred_at' => $occurred,
			'occurred_precision' => $this->occurredPrecision,
			'title' => $this->title,
			'body' => $this->body,
			'type' => $this->type,
			'meta' => $this->meta === null ? null : json_decode($this->meta, true),
			'source' => $this->source,
			'created_at' => $this->createdAt,
			'updated_at' => $this->updatedAt,
		];
	}
}
