<?php

declare(strict_types=1);

namespace OCA\Recall\Command;

use OCA\Recall\Service\EpisodeService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Consulta episodios sem passar pelo HTTP -- o par de diagnostico do
 * recall:add. Com --on-this-day corre a consulta que justifica a app existir.
 */
class ListEpisodes extends Command {
	public function __construct(
		private EpisodeService $service,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('recall:list');
		$this->setDescription('Lista episodios (diagnostico: nao passa pelo HTTP)');
		$this->addOption('user', null, InputOption::VALUE_REQUIRED, 'Utilizador');
		$this->addOption('on-this-day', null, InputOption::VALUE_NONE, 'Neste dia em anos anteriores');
		$this->addOption('date', null, InputOption::VALUE_REQUIRED, 'Data de referencia (por omissao: hoje)');
		$this->addOption('window', null, InputOption::VALUE_REQUIRED, 'Janela em dias, para --on-this-day', '0');
		$this->addOption('years-back', null, InputOption::VALUE_REQUIRED, 'Quantos anos para tras', '5');
		$this->addOption('from', null, InputOption::VALUE_REQUIRED, 'Inicio (YYYY-MM-DD)');
		$this->addOption('to', null, InputOption::VALUE_REQUIRED, 'Fim (YYYY-MM-DD)');
		$this->addOption('type', null, InputOption::VALUE_REQUIRED, 'Filtrar por tipo');
		$this->addOption('q', null, InputOption::VALUE_REQUIRED, 'Pesquisa no titulo/descricao');
		$this->addOption('link-kind', null, InputOption::VALUE_REQUIRED, 'Pesquisa inversa: contact|photo|event|task');
		$this->addOption('link-ref', null, InputOption::VALUE_REQUIRED, 'Pesquisa inversa: o identificador');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$user = (string)$input->getOption('user');
		if ($user === '') {
			$output->writeln('ERRO: --user e obrigatorio.');
			return 1;
		}

		try {
			if ($input->getOption('on-this-day')) {
				$anos = $this->service->onThisDay($user, [
					'date' => $input->getOption('date'),
					'window' => $input->getOption('window'),
					'years_back' => $input->getOption('years-back'),
				]);
				if ($anos === []) {
					$output->writeln('Nada encontrado nesta janela em anos anteriores.');
					return 0;
				}
				krsort($anos);
				foreach ($anos as $ano => $episodios) {
					$output->writeln("--- {$ano} ---");
					foreach ($episodios as $e) {
						$output->writeln('  ' . $e['occurred_at'] . '  ' . $e['title']
							. ($e['type'] !== '' ? '  [' . $e['type'] . ']' : ''));
					}
				}
				return 0;
			}

			$episodios = $this->service->list($user, [
				'from' => $input->getOption('from'),
				'to' => $input->getOption('to'),
				'type' => $input->getOption('type'),
				'q' => $input->getOption('q'),
				'link_kind' => $input->getOption('link-kind'),
				'link_ref' => $input->getOption('link-ref'),
			]);
		} catch (\Throwable $e) {
			$output->writeln('ERRO: ' . $e->getMessage());
			return 1;
		}

		$output->writeln('Encontrado(s) ' . count($episodios) . ' episodio(s):');
		foreach ($episodios as $e) {
			$output->writeln(json_encode($e, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
		}

		return 0;
	}
}
