<?php
if (!isConnect('admin')) {
	throw new Exception('{{401 - Accès non autorisé}}');
}
$plugin = plugin::byId('dahuavtobe');
sendVarToJS('eqType', $plugin->getId());
$eqLogics = eqLogic::byType($plugin->getId());
?>

<div class="row row-overflow">
	<div class="col-xs-12 eqLogicThumbnailDisplay">
		<legend><i class="fas fa-cog"></i> {{Gestion}}</legend>
		<div class="eqLogicThumbnailContainer">
			<div class="cursor eqLogicAction logoPrimary" data-action="add">
				<i class="fas fa-plus-circle"></i>
				<br>
				<span>{{Ajouter un portier}}</span>
			</div>
			<div class="cursor logoSecondary" id="bt_dahuavtobeDaemonStatus">
				<i class="fas fa-heartbeat"></i>
				<br>
				<span>{{État du démon}}</span>
			</div>
			<div class="cursor eqLogicAction logoSecondary" data-action="gotoPluginConf">
				<i class="fas fa-wrench"></i>
				<br>
				<span>{{Configuration}}</span>
			</div>
		</div>

		<legend><i class="fas fa-list"></i> {{Mes portiers}}</legend>
		<?php
		if (count($eqLogics) == 0) {
			echo '<div class="alert alert-info" style="margin:5px;">';
			echo '<b>{{Aucun portier pour le moment. Pour démarrer :}}</b>';
			echo '<ol style="margin:5px 0 0 0;padding-left:20px;">';
			echo '<li>{{Relevez l\'adresse IP du portier et réservez-la dans votre routeur. Si elle change, le plugin ne trouvera plus rien et ne saura pas dire pourquoi.}}</li>';
			echo '<li>{{Cliquez sur « Ajouter un portier », donnez-lui un nom, puis saisissez son adresse et les identifiants de son interface web.}}</li>';
			echo '<li>{{Utilisez « Tester la connexion » : le plugin affiche le modèle et la version du portier. Les commandes sont créées à l\'enregistrement, et le démon se met à écouter les appels.}}</li>';
			echo '</ol>';
			echo '<span class="help-block" style="margin:8px 0 0 0;">{{Le plugin parle directement au portier sur votre réseau local : ni enregistreur, ni cloud, ni compte constructeur. Ce plugin n\'est pas affilié à Dahua.}}</span>';
			echo '</div>';
		}
		echo '<div class="input-group" style="margin:5px;">';
		echo '<input class="form-control roundedLeft" placeholder="{{Rechercher}}" id="in_searchEqlogic">';
		echo '<div class="input-group-btn">';
		echo '<a id="bt_resetSearch" class="btn" style="width:30px"><i class="fas fa-times"></i></a>';
		echo '<a class="btn roundedRight hidden" id="bt_pluginDisplayAsTable" data-coreSupport="1" data-state="0"><i class="fas fa-grip-lines"></i></a>';
		echo '</div>';
		echo '</div>';
		echo '<div class="eqLogicThumbnailContainer">';
		foreach ($eqLogics as $eqLogic) {
			$opacity = ($eqLogic->getIsEnable()) ? '' : 'disableCard';
			echo '<div class="eqLogicDisplayCard cursor ' . $opacity . '" data-eqLogic_id="' . $eqLogic->getId() . '">';
			echo '<i class="fas fa-door-closed" style="font-size:4em;"></i>';
			echo '<br>';
			echo '<span class="name">' . $eqLogic->getHumanName(true, true) . '</span>';
			echo '<span class="hiddenAsCard displayTableRight hidden">';
			echo ($eqLogic->getIsVisible() == 1) ? '<i class="fas fa-eye" title="{{Equipement visible}}"></i>' : '<i class="fas fa-eye-slash" title="{{Equipement non visible}}"></i>';
			echo '</span>';
			echo '</div>';
		}
		echo '</div>';
		?>
	</div>

	<div class="col-xs-12 eqLogic" style="display: none;">
		<div class="input-group pull-right" style="display:inline-flex">
			<span class="input-group-btn">
				<a class="btn btn-default btn-sm eqLogicAction roundedLeft" data-action="configure"><i class="fas fa-cogs"></i><span class="hidden-xs"> {{Configuration avancée}}</span></a>
				<a class="btn btn-default btn-sm eqLogicAction" data-action="copy"><i class="fas fa-copy"></i><span class="hidden-xs"> {{Dupliquer}}</span></a>
				<a class="btn btn-sm btn-success eqLogicAction" data-action="save"><i class="fas fa-check-circle"></i> {{Sauvegarder}}</a>
				<a class="btn btn-sm btn-danger eqLogicAction roundedRight" data-action="remove"><i class="fas fa-minus-circle"></i> {{Supprimer}}</a>
			</span>
		</div>
		<ul class="nav nav-tabs" role="tablist">
			<li role="presentation"><a href="#" class="eqLogicAction" aria-controls="home" role="tab" data-toggle="tab" data-action="returnToThumbnailDisplay"><i class="fas fa-arrow-circle-left"></i></a></li>
			<li role="presentation" class="active"><a href="#eqlogictab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-tachometer-alt"></i><span class="hidden-xs"> {{Équipement}}</span></a></li>
			<li role="presentation"><a href="#diagtab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-stethoscope"></i><span class="hidden-xs"> {{Diagnostic}}</span></a></li>
			<li role="presentation"><a href="#commandtab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-list"></i><span class="hidden-xs"> {{Commandes}}</span></a></li>
		</ul>

		<div class="tab-content">
			<!-- ========================= ÉQUIPEMENT ========================= -->
			<div role="tabpanel" class="tab-pane active" id="eqlogictab">
				<br>
				<div class="col-lg-6">
					<form class="form-horizontal">
						<fieldset>
							<legend><i class="fas fa-tag"></i> {{Général}}</legend>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Nom}}</label>
								<div class="col-sm-6">
									<input type="text" class="eqLogicAttr form-control" data-l1key="id" style="display:none;">
									<input type="text" class="eqLogicAttr form-control" data-l1key="name" placeholder="{{Portier}}">
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Objet parent}}</label>
								<div class="col-sm-6">
									<select class="eqLogicAttr form-control" data-l1key="object_id">
										<option value="">{{Aucun}}</option>
										<?php
										foreach ((jeeObject::buildTree(null, false)) as $object) {
											echo '<option value="' . $object->getId() . '">' . $object->getHumanName(true, true) . '</option>';
										}
										?>
									</select>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Catégorie}}</label>
								<div class="col-sm-8">
									<?php
									foreach (jeedom::getConfiguration('eqLogic:category') as $key => $value) {
										echo '<label class="checkbox-inline">';
										echo '<input type="checkbox" class="eqLogicAttr" data-l1key="category" data-l2key="' . $key . '">' . $value['name'];
										echo '</label>';
									}
									?>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Activer}}</label>
								<div class="col-sm-8">
									<input type="checkbox" class="eqLogicAttr" data-l1key="isEnable" checked>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Visible}}</label>
								<div class="col-sm-8">
									<input type="checkbox" class="eqLogicAttr" data-l1key="isVisible" checked>
								</div>
							</div>
						</fieldset>
					</form>
				</div>

				<div class="col-lg-6">
					<form class="form-horizontal">
						<fieldset>
							<legend><i class="fas fa-door-open"></i> {{Portier}}</legend>

							<div class="form-group">
								<label class="col-sm-3 control-label">{{Adresse}}</label>
								<div class="col-sm-5">
									<input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="ip" placeholder="192.168.1.50">
								</div>
								<div class="col-sm-4">
									<span class="help-block" style="margin:0;">{{Adresse IP ou nom d'hôte du portier. Réservez-la dans votre routeur.}}</span>
								</div>
							</div>

							<div class="form-group">
								<label class="col-sm-3 control-label">{{Identifiant}}</label>
								<div class="col-sm-5">
									<input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="username" placeholder="admin">
								</div>
								<div class="col-sm-4">
									<span class="help-block" style="margin:0;">{{Le compte de l'interface web du portier.}}</span>
								</div>
							</div>

							<div class="form-group">
								<label class="col-sm-3 control-label">{{Mot de passe}}</label>
								<div class="col-sm-5">
									<input type="password" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="password" autocomplete="new-password">
								</div>
								<div class="col-sm-4">
									<span class="help-block" style="margin:0;">{{Il est conservé tel quel dans la base de Jeedom : le portier n'accepte que l'authentification Digest, qui exige le mot de passe en clair à chaque requête.}}</span>
								</div>
							</div>

							<div class="form-group">
								<label class="col-sm-3 control-label">{{Transport}}</label>
								<div class="col-sm-5">
									<select class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="transport">
										<option value="auto">{{Automatique}}</option>
										<option value="cgi">{{CGI (HTTP)}}</option>
										<option value="dhip">{{DHIP (natif)}}</option>
									</select>
								</div>
								<div class="col-sm-4">
									<span class="help-block" style="margin:0;">{{Comment le démon écoute les événements. En automatique, il essaie le protocole natif puis retombe sur le HTTP. Ne le figez que si vous savez lequel fonctionne chez vous.}}</span>
								</div>
							</div>

							<div class="form-group">
								<label class="col-sm-3 control-label">{{Ports}}</label>
								<div class="col-sm-8">
									<div class="row">
										<div class="col-sm-6">
											<div class="input-group">
												<span class="input-group-addon">HTTP</span>
												<input type="number" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="http_port" placeholder="80">
											</div>
										</div>
										<div class="col-sm-6">
											<div class="input-group">
												<span class="input-group-addon">DHIP</span>
												<input type="number" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="dhip_port" placeholder="5000">
											</div>
										</div>
									</div>
									<span class="help-block" style="margin:4px 0 0 0;">{{Les valeurs d'usine conviennent à la quasi-totalité des portiers. Le port DHIP d'un portier est 5000, et non 37777 comme sur un enregistreur.}}</span>
								</div>
							</div>

							<div class="form-group">
								<label class="col-sm-3 control-label">{{Canal vidéo}}</label>
								<div class="col-sm-5">
									<input type="number" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="channel" placeholder="1">
								</div>
								<div class="col-sm-4">
									<span class="help-block" style="margin:0;">{{1 sauf portier à plusieurs caméras.}}</span>
								</div>
							</div>

							<div class="form-group">
								<label class="col-sm-3 control-label"></label>
								<div class="col-sm-9">
									<a class="btn btn-default btn-sm" id="bt_dahuavtobeTest"><i class="fas fa-plug"></i> {{Tester la connexion}}</a>
									<a class="btn btn-default btn-sm" id="bt_dahuavtobeSnapshot"><i class="fas fa-camera"></i> {{Prendre une photo}}</a>
									<span id="span_dahuavtobeStatus" style="margin-left:10px;"></span>
								</div>
							</div>

							<div class="form-group">
								<label class="col-sm-3 control-label"></label>
								<div class="col-sm-9">
									<img id="img_dahuavtobeSnapshot" style="max-width:100%;display:none;border-radius:var(--border-radius);">
								</div>
							</div>
						</fieldset>
					</form>
				</div>
			</div>

			<!-- ========================== DIAGNOSTIC ========================= -->
			<div role="tabpanel" class="tab-pane" id="diagtab">
				<br>
				<div class="col-xs-12">
					<div class="alert alert-info" id="div_dahuavtobeState">{{Chargement…}}</div>
					<legend><i class="fas fa-bell"></i> {{Derniers événements reçus}}</legend>
					<span class="help-block">{{Les événements tels que le portier les a envoyés, avant toute interprétation. C'est ici qu'on voit ce qui arrive réellement quand quelqu'un sonne — les codes varient d'un modèle et d'un micrologiciel à l'autre. Laissez cette page ouverte et faites sonner : la liste se remplit toute seule.}}</span>
					<pre id="pre_dahuavtobeRaw" style="max-height:420px;overflow:auto;"></pre>
				</div>
			</div>

			<!-- ========================== COMMANDES ========================== -->
			<div role="tabpanel" class="tab-pane" id="commandtab">
				<br>
				<div class="col-xs-12">
					<table id="table_cmd" class="table table-bordered table-condensed">
						<thead>
							<tr>
								<th style="width:250px;">{{Nom}}</th>
								<th style="width:120px;">{{Type}}</th>
								<th>{{Paramètres}}</th>
								<th style="width:120px;">{{Actions}}</th>
							</tr>
						</thead>
						<tbody></tbody>
					</table>
				</div>
			</div>
		</div>
	</div>
</div>

<?php include_file('desktop', 'dahuavtobe', 'js', 'dahuavtobe'); ?>
<?php include_file('core', 'plugin.template', 'js'); ?>
