<?php
/** @var array $_ */
/** @var \OCP\IL10N $l */
$token = \OCP\Util::callRegister();
?>
<div id="recall" class="recall"
     data-episodes-url="<?php p($_['ocsEpisodesUrl']); ?>"
     data-contacts-url="<?php p($_['ocsContactsUrl']); ?>"
     data-files-url="<?php p($_['ocsFilesUrl']); ?>">

	<?php if ($_['error'] !== ''): ?>
		<div class="recall-error"><?php p($_['error']); ?></div>
	<?php endif; ?>

	<?php if ($_['onThisDay'] !== []): ?>
		<section class="recall-onthisday">
			<h2><?php p($l->t('Neste dia, em anos anteriores')); ?></h2>
			<?php krsort($_['onThisDay']); ?>
			<?php foreach ($_['onThisDay'] as $year => $episodes): ?>
				<h3><?php p($year); ?></h3>
				<ul>
					<?php foreach ($episodes as $episode): ?>
						<li>
							<span class="recall-date"><?php p($episode['occurred_at']); ?></span>
							<strong><?php p($episode['title']); ?></strong>
							<?php if ($episode['type'] !== ''): ?>
								<span class="recall-tag"><?php p($episode['type']); ?></span>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endforeach; ?>
		</section>
	<?php endif; ?>

	<section class="recall-new">
		<h2><?php p($l->t('Guardar uma memoria')); ?></h2>
		<form method="post" action="<?php p($_['createUrl']); ?>">
			<input type="hidden" name="requesttoken" value="<?php p($token); ?>">

			<label for="recall-title"><?php p($l->t('O que aconteceu')); ?></label>
			<input type="text" id="recall-title" name="title" required
			       placeholder="<?php p($l->t('ex: prenda de anos da Sofia')); ?>">

			<div class="recall-row">
				<span>
					<label for="recall-date"><?php p($l->t('Quando aconteceu')); ?></label>
					<input type="date" id="recall-date" name="occurred_at" required>
				</span>
				<span>
					<label for="recall-precision"><?php p($l->t('Precisao')); ?></label>
					<select id="recall-precision" name="occurred_precision">
						<option value="day"><?php p($l->t('dia certo')); ?></option>
						<option value="month"><?php p($l->t('so o mes')); ?></option>
						<option value="year"><?php p($l->t('so o ano')); ?></option>
					</select>
				</span>
				<span>
					<label for="recall-type"><?php p($l->t('Tipo')); ?></label>
					<input type="text" id="recall-type" name="type" list="recall-types"
					       placeholder="<?php p($l->t('ex: compra')); ?>">
					<datalist id="recall-types">
						<option value="compra"></option>
						<option value="viagem"></option>
						<option value="saude"></option>
						<option value="escola"></option>
						<option value="ideia"></option>
						<option value="prenda"></option>
					</datalist>
				</span>
			</div>

			<label for="recall-contact"><?php p($l->t('Pessoa (opcional)')); ?></label>
			<div class="recall-picker">
				<input type="text" id="recall-contact" name="link_contact_label"
				       autocomplete="off"
				       placeholder="<?php p($l->t('escreve o nome de um contacto')); ?>">
				<input type="hidden" id="recall-contact-ref" name="link_contact_ref" value="">
				<ul id="recall-contact-results" hidden></ul>
			</div>

			<label for="recall-file"><?php p($l->t('Foto, documento ou pasta (opcional)')); ?></label>
			<div class="recall-picker">
				<input type="text" id="recall-file" name="link_file" autocomplete="off"
				       placeholder="<?php p($l->t('cola aqui a "ligacao interna" do ficheiro')); ?>">
				<p id="recall-file-feedback" class="recall-hint" hidden></p>
			</div>

			<label for="recall-body"><?php p($l->t('Detalhes (opcional)')); ?></label>
			<textarea id="recall-body" name="body" rows="3"></textarea>

			<button type="submit" class="button primary"><?php p($l->t('Guardar')); ?></button>
		</form>
	</section>

	<section class="recall-list">
		<h2><?php p($l->t('Memorias')); ?></h2>

		<form method="get" action="<?php p($_['indexUrl']); ?>" class="recall-search">
			<input type="search" name="q" value="<?php p($_['search']); ?>"
			       placeholder="<?php p($l->t('Procurar...')); ?>">
			<button type="submit" class="button"><?php p($l->t('Procurar')); ?></button>
		</form>

		<?php if ($_['episodes'] === []): ?>
			<p class="recall-empty"><?php p($l->t('Ainda nao ha memorias guardadas.')); ?></p>
		<?php else: ?>
			<table>
				<thead>
					<tr>
						<th><?php p($l->t('Quando')); ?></th>
						<th><?php p($l->t('O que')); ?></th>
						<th><?php p($l->t('Tipo')); ?></th>
						<th><?php p($l->t('Ligacoes')); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($_['episodes'] as $episode): ?>
						<tr>
							<td class="recall-date"><?php p($episode['occurred_at']); ?></td>
							<td>
								<strong><?php p($episode['title']); ?></strong>
								<?php if (!empty($episode['body'])): ?>
									<div class="recall-body"><?php p($episode['body']); ?></div>
								<?php endif; ?>
							</td>
							<td>
								<?php if ($episode['type'] !== ''): ?>
									<span class="recall-tag"><?php p($episode['type']); ?></span>
								<?php endif; ?>
							</td>
							<td>
								<?php foreach ($episode['links'] as $link): ?>
									<?php if (in_array($link['kind'], ['photo', 'file', 'folder'], true)): ?>
										<a class="recall-tag" target="_blank" rel="noreferrer noopener"
										   href="<?php p($_['fileLinkBase'] . $link['ref']); ?>"
										   title="<?php p($link['kind'] . ': ' . $link['ref']); ?>">
											<?php p($link['label']); ?>
										</a>
									<?php else: ?>
										<span class="recall-tag" title="<?php p($link['kind'] . ': ' . $link['ref']); ?>">
											<?php p($link['label']); ?>
										</span>
									<?php endif; ?>
								<?php endforeach; ?>
							</td>
							<td>
								<form method="post"
								      action="<?php p($episode['delete_url']); ?>"
								      data-delete-id="<?php p($episode['id']); ?>"
								      onsubmit="return confirm('<?php p($l->t('Apagar esta memoria?')); ?>');">
									<input type="hidden" name="requesttoken" value="<?php p($token); ?>">
									<button type="submit" class="button"><?php p($l->t('Apagar')); ?></button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</section>
</div>
