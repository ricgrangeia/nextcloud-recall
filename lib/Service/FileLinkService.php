<?php

declare(strict_types=1);

namespace OCA\Recall\Service;

use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;

/**
 * Transforma uma ligacao interna do Nextcloud (a do botao "Copiar ligacao
 * interna", da forma .../f/18432) na ligacao que o recall guarda.
 *
 * Guarda-se sempre o file id e nunca o caminho: o caminho muda quando se move
 * ou renomeia o ficheiro e a ligacao morria em silencio, enquanto o id
 * sobrevive.
 */
class FileLinkService {
	public function __construct(
		private IRootFolder $rootFolder,
	) {
	}

	/**
	 * @return array{kind: string, ref: string, label: string}|null
	 *         null quando o texto nao tem id nenhum, ou quando o utilizador
	 *         nao tem acesso ao ficheiro -- o que tambem impede que alguem
	 *         descubra o nome de ficheiros alheios inventando numeros.
	 */
	public function resolve(string $userId, string $input): ?array {
		$fileId = self::extractId($input);
		if ($fileId === null) {
			return null;
		}

		try {
			// Procurado DENTRO da pasta do utilizador: e isto que garante que
			// so resolve o que ele pode mesmo ver.
			$userFolder = $this->rootFolder->getUserFolder($userId);
			$nodes = $userFolder->getById($fileId);
		} catch (\Throwable) {
			return null;
		}

		$node = $nodes[0] ?? null;
		if (!$node instanceof Node) {
			return null;
		}

		return [
			'kind' => self::kindOf($node),
			'ref' => (string)$fileId,
			'label' => $node->getName(),
		];
	}

	private static function kindOf(Node $node): string {
		if ($node instanceof Folder) {
			return 'folder';
		}
		return str_starts_with((string)$node->getMimetype(), 'image/') ? 'photo' : 'file';
	}

	/**
	 * Aceita as varias formas que um URL do Nextcloud pode ter, e tambem o id
	 * escrito a mao -- e mais barato ser tolerante aqui do que explicar ao
	 * utilizador qual das ligacoes da interface e a "certa".
	 */
	public static function extractId(string $input): ?int {
		$input = trim($input);
		if ($input === '') {
			return null;
		}

		if (ctype_digit($input)) {
			return (int)$input;
		}

		$padroes = [
			'~/f/(\d+)~',                  // ligacao interna: .../f/18432
			'~[?&]fileid=(\d+)~',          // .../apps/files/?dir=/X&fileid=18432
			'~[?&]openfile=(\d+)~',        // .../apps/files/?openfile=18432
			'~/apps/files/files/(\d+)~',   // .../apps/files/files/18432?dir=/X
		];
		foreach ($padroes as $padrao) {
			if (preg_match($padrao, $input, $m) === 1) {
				return (int)$m[1];
			}
		}

		return null;
	}
}
