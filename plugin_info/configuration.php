<?php
if (!isConnect('admin')) {
	throw new Exception('{{401 - Accès non autorisé}}');
}
?>
<form class="form-horizontal">
	<fieldset>
		<legend><i class="fas fa-cogs"></i> {{Démon}}</legend>
		<div class="form-group">
			<label class="col-lg-4 control-label">{{Port des ordres}}</label>
			<div class="col-lg-2">
				<input class="configKey form-control" data-l1key="socketport" />
			</div>
			<div class="col-lg-6">
				<span class="help-block">{{Port local par lequel Jeedom transmet ses ordres au démon. À changer seulement si ce port est déjà pris sur la machine.}}</span>
			</div>
		</div>
		<div class="form-group">
			<label class="col-lg-4 control-label">{{Battement du flux (s)}}</label>
			<div class="col-lg-2">
				<input class="configKey form-control" data-l1key="event_heartbeat" />
			</div>
			<div class="col-lg-6">
				<span class="help-block">{{Le portier envoie un signe de vie à cet intervalle. Sans lui, une coupure réseau serait indiscernable d'une journée sans visiteur : le plugin resterait muet en croyant écouter.}}</span>
			</div>
		</div>
		<div class="form-group">
			<label class="col-lg-4 control-label">{{Durée de la sonnerie (s)}}</label>
			<div class="col-lg-2">
				<input class="configKey form-control" data-l1key="pulse_duration" />
			</div>
			<div class="col-lg-6">
				<span class="help-block">{{Combien de temps la commande « Sonnerie » reste à 1. Le portier annonce l'appui, jamais sa fin : c'est le plugin qui rend la commande à zéro, sans quoi elle y resterait et aucun scénario ne se redéclencherait.}}</span>
			</div>
		</div>
		<div class="form-group">
			<label class="col-lg-4 control-label">{{Délai de reconnexion (s)}}</label>
			<div class="col-lg-2">
				<input class="configKey form-control" data-l1key="reconnect_delay" />
			</div>
			<div class="col-lg-6">
				<span class="help-block">{{Attente avant de retenter après une coupure. Le délai double à chaque échec consécutif, pour ne pas marteler un portier hors tension.}}</span>
			</div>
		</div>
	</fieldset>

	<fieldset>
		<legend><i class="fas fa-camera"></i> {{Captures}}</legend>
		<div class="form-group">
			<label class="col-lg-4 control-label">{{Photographier à la sonnerie}}</label>
			<div class="col-lg-2">
				<input type="checkbox" class="configKey" data-l1key="snapshot_on_ring" />
			</div>
			<div class="col-lg-6">
				<span class="help-block">{{La photo est prise par le démon au moment même de la sonnerie, avant que le moindre scénario ne se réveille : c'est la seule façon d'avoir le visiteur encore devant l'objectif.}}</span>
			</div>
		</div>
		<div class="form-group">
			<label class="col-lg-4 control-label">{{Photographier à l'ouverture}}</label>
			<div class="col-lg-2">
				<input type="checkbox" class="configKey" data-l1key="snapshot_on_unlock" />
			</div>
			<div class="col-lg-6">
				<span class="help-block">{{Une ouverture par badge ou par code ne fait pas sonner le portier : sans cette photo, rien dans Jeedom ne dit qui vient d'entrer.}}</span>
			</div>
		</div>
		<div class="form-group">
			<label class="col-lg-4 control-label">{{Images conservées}}</label>
			<div class="col-lg-2">
				<input class="configKey form-control" data-l1key="snapshot_keep" />
			</div>
			<div class="col-lg-6">
				<span class="help-block">{{Nombre de photos gardées par portier. Au-delà, les plus anciennes sont effacées.}}</span>
			</div>
		</div>
	</fieldset>

	<fieldset>
		<legend><i class="fas fa-unlock"></i> {{Ouverture de la porte}}</legend>
		<div class="form-group">
			<label class="col-lg-4 control-label">{{Autoriser l'ouverture}}</label>
			<div class="col-lg-2">
				<input type="checkbox" class="configKey" data-l1key="allow_open_door" />
			</div>
			<div class="col-lg-6">
				<span class="help-block">{{Décoché, la commande « Ouvrir la porte » refuse de s'exécuter, même appelée depuis un scénario. La commande est également créée invisible : ouvrir sa porte d'entrée ne doit pas être à portée de clic involontaire.}}</span>
			</div>
		</div>
		<div class="form-group">
			<label class="col-lg-4 control-label">{{Identifiant utilisateur}}</label>
			<div class="col-lg-2">
				<input class="configKey form-control" data-l1key="open_door_userid" />
			</div>
			<div class="col-lg-6">
				<span class="help-block">{{Identifiant inscrit dans le journal d'accès du portier pour les ouvertures venant de Jeedom. Il permet de les distinguer d'un badge ou d'un code.}}</span>
			</div>
		</div>
	</fieldset>
</form>
