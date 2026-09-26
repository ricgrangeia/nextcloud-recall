<?php

declare(strict_types=1);

namespace OCA\Recall\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000001Date20260926000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('recall_episodes')) {
			$table = $schema->createTable('recall_episodes');
			$table->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('user_id', 'string', ['notnull' => true, 'length' => 64]);

			// Quando ACONTECEU -- distinto de created_at (quando foi registado).
			// E a distincao que separa esta app de um bloco de notas.
			$table->addColumn('occurred_at', 'date', ['notnull' => true]);

			// Ano e MMDD desnormalizados de occurred_at. Existem porque uma app
			// Nextcloud tem de correr em MySQL, PostgreSQL e SQLite, e extrair
			// mes/dia em SQL portavel entre os tres e um sarilho. Com estes dois
			// inteiros indexados, "mesma janela em anos anteriores" passa a ser
			// aritmetica de inteiros, igual nos tres motores.
			$table->addColumn('occurred_year', 'integer', ['notnull' => true]);
			$table->addColumn('occurred_md', 'integer', ['notnull' => true]);

			// day | month | year -- muitas memorias sao difusas ("no verao de
			// 2019"). Sem isto obrigava-se a inventar uma precisao que nao existe.
			$table->addColumn('occurred_precision', 'string', [
				'notnull' => true, 'length' => 8, 'default' => 'day',
			]);

			$table->addColumn('title', 'string', ['notnull' => true, 'length' => 255]);
			$table->addColumn('body', 'text', ['notnull' => false]);
			$table->addColumn('type', 'string', ['notnull' => true, 'length' => 32, 'default' => '']);

			// JSON livre (valor, moeda, local, ...) -- deixa acrescentar campos
			// sem migracao nova enquanto o dominio ainda esta a assentar.
			$table->addColumn('meta', 'text', ['notnull' => false]);

			// manual | agent | <app id> -- saber quem registou o episodio.
			$table->addColumn('source', 'string', ['notnull' => true, 'length' => 32, 'default' => 'manual']);

			$table->addColumn('created_at', 'string', ['notnull' => true, 'length' => 32]);
			$table->addColumn('updated_at', 'string', ['notnull' => true, 'length' => 32]);

			$table->setPrimaryKey(['id']);
			$table->addIndex(['user_id', 'occurred_at'], 'recall_ep_user_date_idx');
			$table->addIndex(['user_id', 'occurred_md'], 'recall_ep_user_md_idx');
			$table->addIndex(['user_id', 'type'], 'recall_ep_user_type_idx');
		}

		if (!$schema->hasTable('recall_links')) {
			$table = $schema->createTable('recall_links');
			$table->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('episode_id', 'integer', ['notnull' => true]);

			// contact (UID CardDAV) | photo (file id) | event (UID CalDAV) | task (UID VTODO).
			// Referencia-se sempre por identificador estavel, nunca por caminho:
			// caminhos mudam ao mover/renomear e a ligacao morre em silencio.
			$table->addColumn('kind', 'string', ['notnull' => true, 'length' => 16]);
			$table->addColumn('ref', 'string', ['notnull' => true, 'length' => 255]);

			// Rotulo legivel gravado NO MOMENTO da ligacao. A foto vai ser
			// apagada, o evento removido, o contacto desaparece -- e a memoria
			// tem de continuar a ler-se ("deste-lhe um livro de aguarelas")
			// mesmo quando aquilo a que se refere ja nao existe.
			$table->addColumn('label', 'string', ['notnull' => true, 'length' => 255, 'default' => '']);

			$table->addColumn('created_at', 'string', ['notnull' => true, 'length' => 32]);

			$table->setPrimaryKey(['id']);
			$table->addIndex(['episode_id'], 'recall_links_ep_idx');
			// O sentido inverso e metade do valor: "tudo o que me lembro sobre
			// esta pessoa", "o que aconteceu a volta desta foto".
			$table->addIndex(['kind', 'ref'], 'recall_links_kind_ref_idx');
		}

		return $schema;
	}
}
