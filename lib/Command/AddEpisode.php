<?php

declare(strict_types=1);

namespace OCA\Recall\Command;

use OCA\Recall\Service\EpisodeService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Cria um episodio chamando o EpisodeService diretamente, sem passar pelo
 * HTTP. Serve para separar "a camada de dados esta partida" de "as rotas ou a
 * autenticacao estao mal" -- se isto funciona e o curl falha, o problema nao
 * esta na base de dados.
 */
class AddEpisode extends Command {
	public function __construct(
		private EpisodeService $service,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('recall:add');
		$this->setDescription('Cria um episodio (diagnostico: nao passa pelo HTTP)');
		$this->addOption('user', null, InputOption::VALUE_REQUIRED, 'Utilizador dono do episodio');
		$this->addOption('title', null, InputOption::VALUE_REQUIRED, 'Titulo');
		$this->addOption('date', null, InputOption::VALUE_REQUIRED, 'Quando aconteceu (YYYY-MM-DD)');
		$this->addOption('type', null, InputOption::VALUE_REQUIRED, 'Tipo (compra, viagem, saude, ...)', '');
		$this->addOption('body', null, InputOption::VALUE_REQUIRED, 'Descricao', null);
		$this->addOption('precision', null, InputOption::VALUE_REQUIRED, 'day | month | year', 'day');
		$this->addOption(
			'link',
			null,
			InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
			'Ligacao no formato kind:ref:label (repetivel). kind: contact|photo|event|task',
		);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$user = (string)$input->getOption('user');
		if ($user === '') {
			$output->writeln('ERRO: --user e obrigatorio (os episodios sao por utilizador).');
			return 1;
		}

		$links = [];
		foreach ((array)$input->getOption('link') as $raw) {
			$parts = explode(':', (string)$raw, 3);
			if (count($parts) < 2) {
				$output->writeln('ERRO: ligacao invalida "' . $raw . '" -- usa kind:ref[:label]');
				return 1;
			}
			$links[] = ['kind' => $parts[0], 'ref' => $parts[1], 'label' => $parts[2] ?? $parts[1]];
		}

		try {
			$episode = $this->service->create($user, [
				'title' => $input->getOption('title'),
				'occurred_at' => $input->getOption('date'),
				'type' => $input->getOption('type'),
				'body' => $input->getOption('body'),
				'occurred_precision' => $input->getOption('precision'),
				'source' => 'occ',
				'links' => $links,
			]);
		} catch (\Throwable $e) {
			$output->writeln('ERRO: ' . $e->getMessage());
			return 1;
		}

		$output->writeln(json_encode($episode, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
		return 0;
	}
}
