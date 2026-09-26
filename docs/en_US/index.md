# Dahua VTO plugin

This plugin connects Jeedom to a Dahua **VTO** door station, directly, over your
local network. No recorder, no cloud, no manufacturer account.

When a visitor rings, Jeedom knows within a second and keeps a picture of them.
You can then trigger whatever you like: a phone notification, a light, a message
on a speaker.

## Before you start

You need three things:

1. **The door station's IP address.** Reserve it in your router: if it changes,
   the plugin will find nothing and will not be able to say why.
2. **An account on the door station** — the one you use for its web interface.
3. **A route from Jeedom to the device.** If they sit on separate networks, open
   ports 80 and 5000 between them.

To check before installing anything, from a console on the Jeedom machine:

```bash
curl --digest -u 'admin:your_password' \
  'http://192.168.1.50/cgi-bin/magicBox.cgi?action=getDeviceType'
```

The device should answer `type=VTO2211G-WP`, or the equivalent for your model.

## Installation

1. Install the plugin, then enable it.
2. Open its configuration and read through the settings. The defaults are fine.
3. Back on the plugin page, click **Add a door station**.
4. Give it a name, pick a parent object — without one, the device shows on no
   dashboard — then enter the address, username and password.
5. Click **Test connection**. The plugin shows the model and firmware version:
   that is your proof that address and credentials are right.
6. **Save.** Commands are created and the daemon starts listening.

## What you see next

The **Diagnostic** tab shows events exactly as the device sends them, before any
interpretation. Keep that page open the first time: codes vary between models and
firmware versions, and this is where you see what actually arrives when someone
presses the button.

On the dashboard, the **Doorbell** command goes to 1 on every call, then falls
back on its own after a few seconds — the duration is a plugin setting. The
device announces the button press, never its end: without that fall-back the
command would stay at 1 and no scenario would fire on the next visit.

So trigger on **Doorbell**, not on **Call state**, whose text changes with the
interface language.

The **Snapshot** command shows the visitor's picture directly on the dashboard,
with the time it was taken; a click opens it full size.

Only five commands are visible at first. The others — last call, missed call,
last access, door left open, snapshot file — exist and are kept up to date,
they are simply hidden so the dashboard stays readable. One tick box in the
Commands tab shows any of them.

The **Snapshot** command holds the address of the latest picture. That address
is protected: you must be logged in to Jeedom **and** have read access to the
door station. It therefore shows in the interface, but an outside service
(Telegram, an e-mail) cannot load it on its own.

To attach the visitor's picture to a notification, use the hidden **Snapshot
file** command instead: it holds the path of the same picture on the Jeedom
disk, for example
`/var/www/html/plugins/dahuavtobe/data/snapshots/vto12_20260923-081500_1a2b3c4d.jpg`.
Most notification plugins accept a file path as an attachment; the field name
varies from one to another (`files`, `file`, "File"…).

The picture arrives a moment after the ring, so use the **Snapshot file**
command itself as the trigger. A scenario triggered by **Doorbell** would go
out before the picture, with the one from the previous visit.

```
Trigger: #[Entrance][Door station][Snapshot file]#

[Home][Phone][Send] : message = Someone is at the door,
                      files = #[Entrance][Door station][Snapshot file]#
```

This trigger fires on every new picture: a ring, but also a card or keypad
unlock and a capture requested by hand.

A picture is also taken on every door unlock, card and keypad included: those
do not ring the door station, and without it nothing would say who just came in.
The setting can be turned off in the plugin configuration.

If a snapshot fails, the **Snapshot** command keeps the picture from the previous
visit, for want of anything better, and the plugin writes it to the log: that is
the only way to know a displayed face is not the visitor waiting outside.

## What if Jeedom was not listening?

The door station keeps its own call log. The plugin re-reads it every fifteen
minutes by default — the interval is a setting, and 0 turns it off — to recover
rings that happened during an update, a restart or a network outage. The
**Missed calls (24 h)** command counts the unanswered rings of the last day;
that is the one to look at when you get home.

That counter depends on recovery. A missed call announced live bumps it by one
straight away, but it is the log re-reading that redoes the exact count and
drops calls older than 24 hours out of the window. With recovery turned off,
the counter therefore stays **empty** — or frozen on its last value if recovery
ran before: a figure that only ever went up, never
forgetting last week's visits, would mislead more than it would help.

This recovery is a safety net, not a real-time path, and it is worth knowing
why. The device only writes its record when the call **ends**, once the ringing
has stopped, and the plugin only re-reads the log on its interval. A recovered
call therefore shows up several minutes late. To react on the spot, use the
**Doorbell** command.

Recovery never triggers the Doorbell command, on purpose: that command is your
scenario trigger, and lighting up the house at midnight for a visitor who left
at four in the afternoon would help nobody. It also never goes back more than
48 hours.

On the very first run the plugin only sets a marker: the device log sometimes
holds years of calls, all from before the plugin was installed, and announcing
them as missed rings would make no sense.

## Knowing who rang

The plugin can have a vision model look at the visitor's photos and tell you
whether it is a delivery person, a door-to-door canvasser or someone you are
expecting. It is off by default.

### Getting started

1. In the plugin configuration, **Visitor analysis** section, enter an API key.
   The other settings are fine as they are.
2. On the equipment, **Visitors** tab, tick **Analyse visitors**.
3. Under **Actions per visitor**, add an action to each category you want to be
   told about. A category without an action does nothing.
4. Save, then click **Analyse the last photo** to check that everything answers.

### What happens on a ring

1. The first photo goes out immediately, as before: the **Snapshot** command and
   existing scenarios wait for nothing.
2. The daemon takes two more photos, two seconds apart. Whoever rings often
   stands right against the door station, out of frame or cut by its edge: they
   step into view as they move back, with their parcel or tablet.
3. The photos go to the model, which answers in two to three seconds.
4. The **Visitor** commands are updated, then the category's actions run in the
   background.

Allow five to eight seconds between the ring and the answer.

### Categories

| Value | Meaning |
|---|---|
| `livreur` | Delivery or mail: carrier, parcel, postman |
| `demarcheur` | Door-to-door canvassing: charity, salesperson, pollster, religious group |
| `professionnel` | Service visit: technician, meter reader, tradesman, official |
| `visiteur` | A person with no professional cue |
| `vide` | Someone rang, but no photo shows them |
| `indetermine` | The model is unsure, or the analysis failed |

The **Visitor** command holds these values verbatim, and they are never
translated: they are what a scenario compares against.

`vide` is common, and it is not a false ring. On a door station filming the
street through a wide-angle lens, the person ringing often stays to the side,
out of frame.

`indetermine` covers two cases: an answer below the **confidence threshold**
(70 % by default), or an analysis that could not run — service unreachable, key
refused, timeout, no photo taken. In the second case, the **Visitor -
description** command and the `#erreur#` tag say why. Give this category an
action if you want to be told even when the service is down.

### Action tags

Usable in every option of an action: title, message, attached file.

| Tag | Content |
|---|---|
| `#libelle#` | The readable category: "Livreur", "Démarcheur"… (in the Jeedom language) |
| `#categorie#` | The raw category: `livreur`, `demarcheur`… |
| `#description#` | One sentence about the visitor, or why the analysis failed |
| `#indices#` | What decided the model: "yellow vest, parcel" |
| `#confiance#` | The model's confidence, in % |
| `#image#` | Path of the best photo of the visit, to attach |
| `#date#` | Time of the ring |
| `#portier#` | Name of the equipment |
| `#erreur#` | Why the analysis failed, empty otherwise |
| `#categorie_brute#` | The model's answer before the threshold was applied |

Example, for a delivery person:

```
[Home][Phone][Send] : title   = #libelle# at the door
                     message = #description#
                     files   = #image#
```

The same information is available to scenarios through the hidden commands
**Visitor - description**, **Visitor - confidence**, **Visitor - image file**
and **Visitor - date**. Trigger: the **Visitor** command. It fires on every
visit, even when two delivery people ring one after the other.

### What the analysis will never do

The model is sometimes wrong. A canvasser could also hold up a sign written to
fool it. Its answer informs, it never opens anything: a command of this door
station, or a lock- or gate-opening command, is refused as an action and the log
says so. A scenario called from an action is not checked: do not put an unlock
in it.

### Your photos, and your visitors'

These are photos of people, and they leave your network. OpenAI does not use API
data to train its models, but keeps it for up to thirty days. To keep the photos
at home, run a local vision model with a compatible API (Ollama, for instance)
and enter its address in **API address**.

Only rings are analysed. Badge or code entries, and photos taken by hand, never
leave.

### Tuning the analysis

- **Hint for the model**: what it cannot guess about your street, for instance
  "A white van is often parked across the road". Without that sentence, a van
  parked at a neighbour's could pass for a delivery.
- **Photos per visit**: 3 by default. Each extra photo delays the answer by two
  seconds.
- **Confidence threshold**: the higher it is, the more `indetermine` visits, and
  the fewer wrong categories.
- **Analyse the last photo** (tab button, or the hidden command of the same
  name): does exactly what a ring does, on a single photo. Commands are updated
  **and actions are run**: that is how to test a notification without ringing
  your own door.
- The `dahuavtobe` log records every answer, with the category proposed by the
  model, its confidence and the response time.

## Opening the door from Jeedom

The **Open door** command exists but is deliberately restrained: it is created
invisible, and it refuses to run until the matching box is ticked in the plugin
configuration. An action command runs just as easily from a stray click as from a
scenario, and a front door is not a lamp switch.

It also asks for confirmation when run from the interface (dashboard widget,
mobile app). That only covers clicks: a scenario or an HTTP API call opens the
door without asking, and only the plugin setting holds them back. You can remove
the confirmation in the command's advanced configuration (gear icon, "Confirm
action"); a plugin update will not put it back.

Once enabled, unlocks coming from Jeedom are written to the device's access log
with the user id set in the configuration, so you can tell them apart from a card
or a keypad code.

## Troubleshooting

**"The door station is unreachable."** The address changed, or the network does
not reach it. Check with the `curl` command above.

**"The door station refused the credentials."** Watch for passwords ending in
punctuation — the final character is part of the password.

**The daemon does not start.** Read the `dahuavtobed` log (Analysis → Logs).

**Nothing arrives when someone rings.** Open the Diagnostic tab and ring. If
events appear there but the Doorbell command does not move, your model uses a
code that is not mapped yet: open a ticket with those lines attached.

**Call times are off.** The device clock is often left on UTC. The plugin
re-stamps events on arrival, but setting the clock and timezone on the device is
cleaner.

## What the plugin does not do

- It does not show live video: it shows the picture taken on the ring. The
  device's RTSP stream stays reachable with a player such as VLC, which will ask
  you for the credentials — the plugin stores them nowhere but in the device
  configuration.
- It does not answer or talk to the visitor: that stays with the indoor monitor.
- It recognises no one. Visitor analysis sorts a visit into a category from
  clothing and objects; it identifies no face.
- It is not affiliated with Dahua.
