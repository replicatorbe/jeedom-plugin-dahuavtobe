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
last access, door left open — exist and are kept up to date, they are simply
hidden so the dashboard stays readable. One tick box in the Commands tab shows
any of them.

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

## Opening the door from Jeedom

The **Open door** command exists but is deliberately restrained: it is created
invisible, and it refuses to run until the matching box is ticked in the plugin
configuration. An action command runs just as easily from a stray click as from a
scenario, and a front door is not a lamp switch.

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
- It is not affiliated with Dahua.
