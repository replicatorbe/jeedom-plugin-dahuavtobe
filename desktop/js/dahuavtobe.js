/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Jeedom is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
 */

/* ================================================================== OUTILS */

function dahuavtobeEl(_id) {
  return document.getElementById(_id)
}

/* Tout ce qui vient du portier est du texte, jamais du balisage : un nom de
   modèle ou un libellé d'événement n'a pas à pouvoir écrire dans la page. */
function dahuavtobeEscape(_text) {
  var div = document.createElement('div')
  div.textContent = (_text === null || _text === undefined) ? '' : String(_text)
  return div.innerHTML
}

function dahuavtobeAjax(_action, _data, _success) {
  var payload = { action: _action }
  for (var key in _data) {
    if (Object.prototype.hasOwnProperty.call(_data, key)) { payload[key] = _data[key] }
  }
  domUtils.ajax({
    type: 'POST',
    url: 'plugins/dahuavtobe/core/ajax/dahuavtobe.ajax.php',
    data: payload,
    dataType: 'json',
    /* Le callback d'erreur de domUtils.ajax ne reçoit qu'un seul argument,
       contrairement à celui de jQuery. */
    error: function (error) {
      dahuavtobeStatus('', null)
      domUtils.handleAjaxError(error)
    },
    success: _success
  })
}

function dahuavtobeStatus(_text, _level) {
  var span = dahuavtobeEl('span_dahuavtobeStatus')
  if (span === null) { return }
  span.textContent = _text
  span.className = _level ? 'label label-' + _level : ''
}

function dahuavtobeCurrentId() {
  var input = document.querySelector('.eqLogicAttr[data-l1key="id"]')
  return (input === null) ? '' : input.value
}

/* ============================================================ PORTIER */

function dahuavtobeTest() {
  var id = dahuavtobeCurrentId()
  if (id === '') {
    jeedomUtils.showAlert({ message: '{{Enregistrez le portier avant de tester la connexion.}}', level: 'warning' })
    return
  }
  dahuavtobeStatus('{{Interrogation…}}', 'info')
  dahuavtobeAjax('testConnection', { id: id }, function (result) {
    var info = result.result
    var text = init(info.type)
    if (isset(info.version)) { text += ' — ' + info.version }
    dahuavtobeStatus(text, 'success')

    /* L'horloge du portier est souvent restée en UTC. Le signaler tout de suite
       évite de chercher longtemps pourquoi un appel s'affiche deux heures plus
       tôt qu'il n'a eu lieu. */
    if (isset(info.time)) {
      jeedomUtils.showAlert({
        message: '{{Heure du portier}} : ' + dahuavtobeEscape(info.time)
               + ' — {{si elle ne correspond pas à l\'heure locale, le plugin réhorodate les événements à leur arrivée.}}',
        level: 'info'
      })
    }
  })
}

function dahuavtobeSnapshot() {
  var id = dahuavtobeCurrentId()
  if (id === '') {
    jeedomUtils.showAlert({ message: '{{Enregistrez le portier avant de prendre une photo.}}', level: 'warning' })
    return
  }
  dahuavtobeStatus('{{Capture…}}', 'info')
  dahuavtobeAjax('snapshot', { id: id }, function (result) {
    var img = dahuavtobeEl('img_dahuavtobeSnapshot')
    if (img !== null) {
      img.src = result.result.url
      img.style.display = ''
    }
    dahuavtobeStatus('{{Photo prise}}', 'success')
  })
}

function dahuavtobeDaemonStatus() {
  dahuavtobeAjax('daemonStatus', {}, function (result) {
    var info = result.result.deamon
    var lines = '{{État du processus}} : ' + dahuavtobeEscape(info.state)
    lines += '<br>{{Peut être lancé}} : ' + dahuavtobeEscape(info.launchable)
    if (isset(info.launchable_message) && info.launchable_message !== '') {
      lines += ' (' + dahuavtobeEscape(info.launchable_message) + ')'
    }
    if (result.result.status && result.result.status.stations) {
      var stations = result.result.status.stations
      for (var i = 0; i < stations.length; i++) {
        lines += '<br>' + dahuavtobeEscape(stations[i].name) + ' : '
               + dahuavtobeEscape(stations[i].state)
               + ' (' + dahuavtobeEscape(stations[i].transport) + ')'
      }
    }
    bootbox.alert(lines)
  })
}

/* =============================================================== VISITEURS */

function dahuavtobeMarkModified() {
  jeeFrontEnd.modifyWithoutSave = true
  window.modifyWithoutSave = true
}

/* Une ligne d'action, calquée sur le sélecteur d'action des scénarios
   (desktop/modal/cmd.configure.php), comme dans le plugin dahua. L'ordre
   html() → setJeeValues → appendChild → replaceWith est celui du coeur : le
   HTML des options contient des <script> que seul Element.prototype.html()
   exécute. */
function dahuavtobeAddAction(_category, _action) {
  var container = document.querySelector('.dahuavtobeActions[data-category="' + _category + '"]')
  if (container === null) { return }
  var action = _action || {}
  if (!isset(action.options)) { action.options = {} }

  var div = '<div class="dahuavtobeAction expression">'
  div += '<input class="expressionAttr" data-l1key="type" style="display:none;" value="action">'
  div += '<div class="form-group">'
  div += '<div class="col-sm-1">'
  div += '<input type="checkbox" class="expressionAttr" data-l1key="options" data-l2key="enable" checked title="{{Décocher pour désactiver cette action}}">'
  div += '<input type="checkbox" class="expressionAttr" data-l1key="options" data-l2key="background" title="{{Exécuter en parallèle des autres actions}}">'
  div += '</div>'
  div += '<div class="col-sm-5">'
  div += '<div class="input-group">'
  div += '<span class="input-group-btn">'
  div += '<a class="btn btn-default btn-sm bt_dahuavtobeRemoveAction roundedLeft"><i class="fas fa-minus-circle"></i></a>'
  div += '</span>'
  div += '<input class="expressionAttr form-control input-sm cmdAction" data-l1key="cmd">'
  div += '<span class="input-group-btn">'
  div += '<a class="btn btn-default btn-sm bt_dahuavtobeListAction" title="{{Choisir un bloc (scénario, variable, message...)}}"><i class="fas fa-tasks"></i></a>'
  div += '<a class="btn btn-default btn-sm bt_dahuavtobeListCmd roundedRight" title="{{Choisir une commande}}"><i class="fas fa-list-alt"></i></a>'
  div += '</span>'
  div += '</div>'
  div += '</div>'
  div += '<div class="col-sm-6 actionOptions"></div>'
  div += '</div>'
  div += '</div>'

  var wrapper = document.createElement('div')
  wrapper.html(div)
  wrapper.setJeeValues(action, '.expressionAttr')
  container.appendChild(wrapper)
  var nodes = Array.prototype.slice.call(wrapper.childNodes)
  wrapper.replaceWith(...nodes)
  if (nodes.length > 0) {
    dahuavtobeRefreshActionOptions(nodes[0], init(action.cmd, ''), action.options)
  }
}

/* Options rendues en asynchrone : la variante synchrone figerait l'onglet le
   temps d'un aller-retour PAR action. */
function dahuavtobeRefreshActionOptions(_line, _expression, _options) {
  jeedom.cmd.displayActionOption(_expression, _options, function (html) {
    var target = _line.querySelector('.actionOptions')
    if (target !== null) {
      target.html(html)
      jeedomUtils.taAutosize()
    }
  })
}

/* Affiche le résultat d'une analyse demandée à la main. */
function dahuavtobeShowAnalysis(_result) {
  var box = dahuavtobeEl('div_dahuavtobeAnalyse')
  if (box === null) { return }
  var html
  if (_result.ok) {
    var below = (_result.categorie !== 'indetermine' && _result.confiance < _result.seuil)
    html = '<b>' + dahuavtobeEscape(_result.libelle) + '</b> — ' + dahuavtobeEscape(_result.confiance) + ' %'
    if (below) {
      html += ' <span class="label label-warning">{{sous le seuil de}} ' + dahuavtobeEscape(_result.seuil)
            + ' % : {{la visite sera classée « indéterminé »}}</span>'
    }
    html += '<br>' + dahuavtobeEscape(_result.description)
    if (_result.indices && _result.indices.length > 0) {
      html += '<br><small>{{Indices}} : ' + dahuavtobeEscape(_result.indices.join(', ')) + '</small>'
    }
    html += '<br><small>' + dahuavtobeEscape(_result.modele) + ' — ' + dahuavtobeEscape(_result.duree_ms) + ' ms</small>'
  } else {
    html = '<b>{{Analyse impossible}}</b> : ' + dahuavtobeEscape(_result.erreur)
  }
  box.className = 'alert ' + (_result.ok ? 'alert-info' : 'alert-danger')
  box.innerHTML = html
  box.style.display = ''
}

function dahuavtobeAnalyse() {
  var id = dahuavtobeCurrentId()
  if (id === '') {
    jeedomUtils.showAlert({ message: '{{Enregistrez le portier avant de lancer une analyse.}}', level: 'warning' })
    return
  }
  var box = dahuavtobeEl('div_dahuavtobeAnalyse')
  if (box !== null) {
    box.className = 'alert alert-info'
    box.textContent = '{{Analyse en cours…}}'
    box.style.display = ''
  }
  dahuavtobeAjax('analyse', { id: id }, function (result) {
    dahuavtobeShowAnalysis(result.result)
  })
}

/* Appelée par plugin.template.js juste avant l'enregistrement. Les actions sont
   des listes rangées par catégorie : elles ne tiennent pas dans data-lXkey, et
   sont donc collectées à la main. */
function saveEqLogic(_eqLogic) {
  if (!isset(_eqLogic.configuration)) {
    _eqLogic.configuration = {}
  }
  var actions = {}
  document.querySelectorAll('.dahuavtobeActions').forEach(function (container) {
    actions[container.getAttribute('data-category')] =
      container.querySelectorAll('.dahuavtobeAction').getJeeValues('.expressionAttr')
  })
  _eqLogic.configuration.ai_actions = actions
  return _eqLogic
}

/* Le coeur ne réinitialise que les .eqLogicAttr : les actions du portier
   précédent resteraient affichées, et seraient enregistrées sur celui-ci. */
function dahuavtobePrintActions(_eqLogic) {
  var configuration = (isset(_eqLogic) && isset(_eqLogic.configuration)) ? _eqLogic.configuration : {}
  var all = (isset(configuration.ai_actions) && typeof configuration.ai_actions === 'object') ? configuration.ai_actions : {}
  document.querySelectorAll('.dahuavtobeActions').forEach(function (container) {
    container.innerHTML = ''
    var category = container.getAttribute('data-category')
    var list = Array.isArray(all[category]) ? all[category] : []
    for (var i = 0; i < list.length; i++) {
      dahuavtobeAddAction(category, list[i])
    }
  })
  var box = dahuavtobeEl('div_dahuavtobeAnalyse')
  if (box !== null) {
    box.style.display = 'none'
    box.innerHTML = ''
  }
}

/* ============================================================== DIAGNOSTIC */

/* Rafraîchissement du journal brut. Il tourne tant que l'onglet Diagnostic est
   visible, et s'arrête dès qu'on le quitte : cette page sert à regarder arriver
   une sonnerie en direct, pas à interroger Jeedom en permanence. */
var dahuavtobeRawTimer = null

function dahuavtobeRawRefresh() {
  var pre = dahuavtobeEl('pre_dahuavtobeRaw')
  var id = dahuavtobeCurrentId()

  /* La page entière a été remplacée — on a quitté le plugin. Sans cet arrêt, la
     minuterie continuerait de battre toutes les trois secondes jusqu'au
     rechargement du navigateur, pour rien. */
  if (pre === null) {
    dahuavtobeRawStop()
    return
  }
  if (id === '') { return }

  /* L'onglet a pu être quitté entre deux tours : inutile d'appeler pour rien. */
  if (pre.offsetParent === null) { return }

  dahuavtobeAjax('rawEvents', { id: id }, function (result) {
    var events = result.result
    if (!events || events.length === 0) {
      pre.textContent = '{{Aucun événement reçu pour le moment. Le démon doit tourner, et le portier doit avoir quelque chose à dire : faites sonner.}}'
      return
    }
    var lines = []
    for (var i = 0; i < events.length; i++) {
      var e = events[i]
      var line = e.time + '  ' + e.code + '  ' + e.action + '  index=' + e.index
      if (e.data && Object.keys(e.data).length > 0) {
        line += '  ' + JSON.stringify(e.data)
      }
      /* Cette page sert à savoir ce que le portier envoie : une fin fabriquée
         par le plugin doit se distinguer d'une observation, sinon on lui
         attribue un message qu'il n'a jamais émis. */
      if (e.synthetic) {
        line += '   ({{fin produite par le plugin}})'
      }
      lines.push(line)
    }
    pre.textContent = lines.join('\n')
  })
}

function dahuavtobeRawStart() {
  dahuavtobeRawStop()
  dahuavtobeRawRefresh()
  dahuavtobeRawTimer = window.setInterval(dahuavtobeRawRefresh, 3000)
}

function dahuavtobeRawStop() {
  if (dahuavtobeRawTimer !== null) {
    window.clearInterval(dahuavtobeRawTimer)
    dahuavtobeRawTimer = null
  }
}

/* Hook appelé par plugin.template.js une fois l'équipement chargé. */
function printEqLogic(_eqLogic) {
  var state = dahuavtobeEl('div_dahuavtobeState')
  if (state !== null) {
    var model = init(_eqLogic.configuration.model, '')
    state.textContent = (model === '')
      ? '{{Modèle inconnu — lancez un test de connexion.}}'
      : model + ' — ' + init(_eqLogic.configuration.firmware, '{{version inconnue}}')
  }
  var img = dahuavtobeEl('img_dahuavtobeSnapshot')
  if (img !== null) {
    img.style.display = 'none'
    img.removeAttribute('src')
  }
  dahuavtobeStatus('', null)
  dahuavtobeRawStop()
  dahuavtobePrintActions(_eqLogic)
}

/* ============================================================== COMMANDES */

function addCmdToTable(_cmd) {
  if (!isset(_cmd)) {
    var _cmd = { configuration: {} }
  }
  if (!isset(_cmd.configuration)) {
    _cmd.configuration = {}
  }

  var tr = '<td>'
  /* Sans ce champ, chaque enregistrement détruit puis recrée les commandes :
     l'historique est perdu et les scénarios pointent dans le vide. */
  tr += '<span class="cmdAttr" data-l1key="id" style="display:none;"></span>'
  tr += '<div class="input-group">'
  tr += '<input class="cmdAttr form-control input-sm roundedLeft" data-l1key="name" placeholder="{{Nom}}">'
  tr += '<span class="input-group-btn">'
  tr += '<a class="cmdAction btn btn-sm btn-default" data-l1key="chooseIcon" title="{{Choisir une icône}}"><i class="fas fa-icons"></i></a>'
  tr += '</span>'
  tr += '<span class="cmdAttr input-group-addon roundedRight" data-l1key="display" data-l2key="icon" style="font-size:19px;padding:0 5px 0 0!important;"></span>'
  tr += '</div>'
  tr += '</td>'
  tr += '<td>'
  tr += '<span class="type" type="' + init(_cmd.type) + '">' + jeedom.cmd.availableType() + '</span>'
  tr += '<span class="subType" subType="' + init(_cmd.subType) + '"></span>'
  tr += '</td>'
  tr += '<td>'
  tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isVisible" checked>{{Afficher}}</label>'
  tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isHistorized" checked>{{Historiser}}</label>'
  tr += '<span class="cmdAttr" data-l1key="htmlstate" style="display:inline-block;margin-left:5px;"></span>'
  tr += '</td>'
  tr += '<td>'
  if (is_numeric(_cmd.id)) {
    tr += '<a class="btn btn-default btn-xs cmdAction" data-action="configure"><i class="fas fa-cogs"></i></a> '
    tr += '<a class="btn btn-default btn-xs cmdAction" data-action="test"><i class="fas fa-rss"></i> {{Tester}}</a> '
  }
  tr += '</td>'

  /* Ligne créée en DOM : insertAdjacentHTML sur une table génère un <tbody> par
     insertion, et toutes les commandes se retrouveraient dans la même ligne. */
  var newRow = document.createElement('tr')
  newRow.innerHTML = tr
  newRow.classList.add('cmd')
  newRow.setAttribute('data-cmd_id', init(_cmd.id))
  newRow.setAttribute('title', '{{Identifiant interne}} : ' + init(_cmd.logicalId))
  document.getElementById('table_cmd').querySelector('tbody').appendChild(newRow)
  newRow.setJeeValues(_cmd, '.cmdAttr')
  /* L'ordre compte : changeType après setJeeValues, jamais l'inverse. */
  jeedom.cmd.changeType(newRow, init(_cmd.subType))
}

/* =============================================================== ÉCOUTEURS */

/* Les pages de plugin sont chargées en ajax : DOMContentLoaded a déjà eu lieu
   quand ce script s'exécute. Les écouteurs sont donc posés par délégation sur
   un conteneur qui, lui, existe déjà. */
var dahuavtobeContainer = document.getElementById('div_pageContainer') || document.body

dahuavtobeContainer.addEventListener('click', function (_event) {
  var target = _event.target
  if (target === null) { return }

  if (target.closest('#bt_dahuavtobeTest') !== null) {
    _event.preventDefault()
    dahuavtobeTest()
    return
  }
  if (target.closest('#bt_dahuavtobeSnapshot') !== null) {
    _event.preventDefault()
    dahuavtobeSnapshot()
    return
  }
  if (target.closest('#bt_dahuavtobeDaemonStatus') !== null) {
    _event.preventDefault()
    dahuavtobeDaemonStatus()
    return
  }
  if (target.closest('#bt_dahuavtobeAnalyse') !== null) {
    _event.preventDefault()
    dahuavtobeAnalyse()
    return
  }

  /* --- Actions selon le visiteur --- */
  var button = target.closest('.bt_dahuavtobeAddAction')
  if (button !== null) {
    _event.preventDefault()
    dahuavtobeAddAction(button.getAttribute('data-category'), {})
    dahuavtobeMarkModified()
    return
  }
  button = target.closest('.bt_dahuavtobeRemoveAction')
  if (button !== null) {
    _event.preventDefault()
    button.closest('.dahuavtobeAction').remove()
    dahuavtobeMarkModified()
    return
  }
  button = target.closest('.bt_dahuavtobeListCmd')
  if (button !== null) {
    _event.preventDefault()
    var cmdLine = button.closest('.dahuavtobeAction')
    jeedom.cmd.getSelectModal({ cmd: { type: 'action' } }, function (result) {
      cmdLine.querySelector('.expressionAttr[data-l1key="cmd"]').jeeValue(result.human)
      dahuavtobeRefreshActionOptions(cmdLine, result.human, '')
      dahuavtobeMarkModified()
    })
    return
  }
  button = target.closest('.bt_dahuavtobeListAction')
  if (button !== null) {
    _event.preventDefault()
    var blockLine = button.closest('.dahuavtobeAction')
    jeedom.getSelectActionModal({}, function (result) {
      /* Ces blocs n'ont de sens que dans un scénario. Les actions tournent dans
         leur propre processus, un « wait » ne bloquerait rien, mais il n'y a
         ni scénario à arrêter ni valeur à rendre. */
      var refuses = ['stop', 'scenario_return', 'icon', 'tag']
      if (refuses.indexOf(result.human) !== -1) {
        jeedomUtils.showAlert({
          message: '{{Ce bloc n\'a de sens que dans un scénario. Passez par un scénario appelé depuis cette action.}}',
          level: 'warning',
          timeOut: 10000
        })
        return
      }
      blockLine.querySelector('.expressionAttr[data-l1key="cmd"]').jeeValue(result.human)
      dahuavtobeRefreshActionOptions(blockLine, result.human, '')
      dahuavtobeMarkModified()
    })
    return
  }

  /* Le journal brut ne se remplit que quand on le regarde. */
  var tab = target.closest('a[href="#diagtab"]')
  if (tab !== null) {
    dahuavtobeRawStart()
    return
  }
  if (target.closest('a[data-toggle="tab"]') !== null
   || target.closest('.eqLogicAction[data-action="returnToThumbnailDisplay"]') !== null) {
    dahuavtobeRawStop()
  }
})

/* Le coeur n'arme modifyWithoutSave que sur ses propres champs : sans cela, on
   quitte la page en perdant ses actions sans le moindre avertissement. */
dahuavtobeContainer.addEventListener('change', function (_event) {
  if (_event.target !== null && _event.target.closest('.dahuavtobeAction') !== null) {
    dahuavtobeMarkModified()
  }
})

/* Réaffiche les options quand la commande d'une action est saisie à la main. */
dahuavtobeContainer.addEventListener('focusout', function (_event) {
  var input = (_event.target !== null) ? _event.target.closest('.dahuavtobeAction .cmdAction') : null
  if (input === null) { return }
  var line = input.closest('.dahuavtobeAction')
  var current = line.getJeeValues('.expressionAttr')[0]
  dahuavtobeRefreshActionOptions(line, input.jeeValue(), init(current.options))
})
