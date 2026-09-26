<?php

declare(strict_types=1);

namespace OCA\Recall\Command;

use OCP\Contacts\IManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Diagnostico: mostra o que o OCP\Contacts\IManager devolve mesmo para um
 * contacto, campo a campo.
 *
 * Existe porque construir desempate de homonimos em cima de suposicoes sobre
 * que propriedades vCard chegam aqui (CATEGORIES? RELATED? NICKNAME?) e a
 * melhor maneira de escrever codigo que parece certo e nao faz nada. Com
 * --raw ve-se o que ha, e so depois se decide o que usar.
 */
class InspectContacts extends Command {
	public function __construct(
		private IManager $contacts,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('recall:contacts');
		$this->setDescription('Mostra o que o Nextcloud devolve sobre um contacto (diagnostico)');
		$this->addOption('q', null, InputOption::VALUE_REQUIRED, 'Nome a procurar');
		$this->addOption('raw', null, InputOption::VALUE_NONE, 'Mostra TODAS as propriedades, nao so as usadas');
		$this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximo de resultados', '10');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$q = trim((string)$input->getOption('q'));
		if ($q === '') {
			$output->writeln('ERRO: --q e obrigatorio.');
			return 1;
		}
		if (!$this->contacts->isEnabled()) {
			$output->writeln('ERRO: nao ha gestor de contactos ativo nesta instancia.');
			return 1;
		}

		$found = $this->contacts->search($q, ['FN', 'EMAIL', 'NICKNAME'], [
			'limit' => max(1, (int)$input->getOption('limit')),
			'enumeration' => false,
		]);

		$output->writeln('Encontrado(s) ' . count($found) . ' contacto(s) para "' . $q . '":');
		foreach ($found as $contact) {
			$output->writeln('');
			if ($input->getOption('raw')) {
				$output->writeln(json_encode($contact, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
				continue;
			}
			$output->writeln(json_encode([
				'UID' => $contact['UID'] ?? null,
				'FN' => $contact['FN'] ?? null,
				'NICKNAME' => $contact['NICKNAME'] ?? null,
				'CATEGORIES' => $contact['CATEGORIES'] ?? null,   // grupos
				'RELATED' => $contact['RELATED'] ?? null,         // conjuge, filho, ...
				'X-ADDRESSBOOKSERVER-KIND' => $contact['X-ADDRESSBOOKSERVER-KIND'] ?? null,
				'isLocalSystemBook' => $contact['isLocalSystemBook'] ?? false,
				'(outras propriedades presentes)' => array_values(array_diff(
					array_keys($contact),
					['UID', 'FN', 'NICKNAME', 'CATEGORIES', 'RELATED', 'isLocalSystemBook']
				)),
			], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
		}

		return 0;
	}
}
