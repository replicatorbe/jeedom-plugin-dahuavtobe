<?php
/*
 * Chargé d'ordinaire par Jeedom, cœur déjà en place ; mais le fichier reste
 * joignable en direct, et isConnect() n'y existerait pas : erreur fatale dans
 * http.error. require_once ne recharge rien dans le cas normal.
 */
require_once dirname(__FILE__) . '/../../../core/php/core.inc.php';
include_file('core', 'authentification', 'php');
if (!isConnect('admin')) {
	http_response_code(401);
	die('401 - Unauthorized');
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
				<input class="configKey form-control" data-l1key="snapshot_keep" type="number" min="1" />
			</div>
			<div class="col-lg-6">
				<span class="help-block">{{Nombre de photos gardées par portier. Au-delà, les plus anciennes sont effacées.}}</span>
			</div>
		</div>
	</fieldset>

	<fieldset>
		<legend><i class="fas fa-user-tag"></i> {{Analyse des visiteurs}}</legend>
		<div class="form-group">
			<div class="col-lg-offset-1 col-lg-10">
				<span class="help-block">{{À chaque sonnerie d'un portier où elle est cochée, les photos du visiteur sont envoyées au service ci-dessous, qui dit s'il s'agit d'un livreur, d'un démarcheur, d'un professionnel ou d'un simple visiteur. Ce sont des photos de personnes : elles quittent votre réseau. Avec OpenAI, elles ne servent pas à entraîner les modèles mais sont conservées jusqu'à trente jours ; un modèle local compatible (Ollama, par exemple) les garde chez vous. Seules les sonneries sont analysées, jamais les ouvertures par badge ou par code.}}</span>
			</div>
		</div>
		<div class="form-group">
			<label class="col-lg-4 control-label">{{Clé API}}</label>
			<div class="col-lg-3">
				<input type="password" class="configKey form-control" data-l1key="ai_apikey" autocomplete="new-password" placeholder="sk-…" />
			</div>
			<div class="col-lg-5">
				<span class="help-block">{{Sans clé, aucune photo ne quitte Jeedom, même si l'analyse est cochée sur un portier.}}</span>
			</div>
		</div>
		<div class="form-group">
			<label class="col-lg-4 control-label">{{Adresse de l'API}}</label>
			<div class="col-lg-3">
				<input class="configKey form-control" data-l1key="ai_base_url" placeholder="https://api.openai.com/v1" />
			</div>
			<div class="col-lg-5">
				<span class="help-block">{{Toute API compatible OpenAI convient, chemin de version compris : https://api.openai.com/v1, ou http://192.168.1.20:11434/v1 pour un Ollama local.}}</span>
			</div>
		</div>
		<div class="form-group">
			<label class="col-lg-4 control-label">{{Modèle}}</label>
			<div class="col-lg-3">
				<input class="configKey form-control" data-l1key="ai_model" placeholder="gpt-6-luna" />
			</div>
			<div class="col-lg-5">
				<span class="help-block">{{Il doit savoir lire des images. Le petit modèle d'OpenAI répond en deux secondes environ, pour une fraction de centime par visite.}}</span>
			</div>
		</div>
		<div class="form-group">
			<label class="col-lg-4 control-label">{{Photos par visite}}</label>
			<div class="col-lg-2">
				<input class="configKey form-control" data-l1key="ai_images" type="number" min="1" max="4" />
			</div>
			<div class="col-lg-6">
				<span class="help-block">{{De 1 à 4, à deux secondes d'intervalle. La personne qui sonne se tient souvent tout contre le portier, hors du cadre : c'est en reculant qu'on voit son colis ou sa tablette. Chaque photo de plus retarde la réponse de deux secondes.}}</span>
			</div>
		</div>
		<div class="form-group">
			<label class="col-lg-4 control-label">{{Seuil de confiance}}</label>
			<div class="col-lg-2">
				<div class="input-group">
					<input class="configKey form-control" data-l1key="ai_threshold" type="number" min="0" max="100" />
					<span class="input-group-addon">%</span>
				</div>
			</div>
			<div class="col-lg-6">
				<span class="help-block">{{Sous ce seuil, le plugin ne tranche pas et classe la visite « indéterminé » : une notification « livreur » pour un démarcheur trompe davantage qu'une notification sans catégorie.}}</span>
			</div>
		</div>
		<div class="form-group">
			<label class="col-lg-4 control-label">{{Délai de réponse (s)}}</label>
			<div class="col-lg-2">
				<input class="configKey form-control" data-l1key="ai_timeout" type="number" min="5" max="60" />
			</div>
			<div class="col-lg-6">
				<span class="help-block">{{Au-delà, la visite est classée « indéterminé » avec la raison, et les actions de cette catégorie sont jouées : on est prévenu même quand le service est en panne. De 5 à 60 secondes.}}</span>
			</div>
		</div>
		<div class="form-group">
			<label class="col-lg-4 control-label">{{Indication pour le modèle}}</label>
			<div class="col-lg-6">
				<textarea class="configKey form-control" data-l1key="ai_context" rows="2" maxlength="500" placeholder="{{Une camionnette blanche est souvent garée en face. Nous recevons beaucoup de colis Vinted.}}"></textarea>
			</div>
			<div class="col-lg-2">
				<span class="help-block">{{Facultatif. Ce que le modèle ne peut pas deviner de votre rue.}}</span>
			</div>
		</div>
	</fieldset>

	<fieldset>
		<legend><i class="fas fa-history"></i> {{Rattrapage des sonneries}}</legend>
		<div class="form-group">
			<label class="col-lg-4 control-label">{{Relire le journal toutes les}}</label>
			<div class="col-lg-2">
				<div class="input-group">
					<input class="configKey form-control" data-l1key="call_history_interval" />
					<span class="input-group-addon">min</span>
				</div>
			</div>
			<div class="col-lg-6">
				<span class="help-block">{{Le portier tient le journal de ses appels. Le relire permet de retrouver les sonneries survenues pendant que Jeedom n'écoutait pas — mise à jour, redémarrage, coupure réseau — et sert de filet si le flux d'événements de votre modèle ne portait pas la sonnerie. La commande « Appels manqués (24 h) » en dépend : c'est cette relecture qui la tient à jour. 0 désactive le rattrapage, et ce compteur reste alors vide.}}</span>
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
