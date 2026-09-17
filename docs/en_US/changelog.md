# Changelog

## 0.1

First release.

It connects straight to a Dahua VTO door station, with no recorder in between,
and needs neither a cloud service nor a manufacturer account.

**Receiving visitors**

- The **Doorbell** command goes to 1 as soon as the button is pressed, and falls
  back on its own after a few seconds — the device announces the press, never
  its end.
- The call state follows what happens: ringing, answered, hung up, missed.
- A picture of the visitor is taken at the very moment of the ring, by the
  daemon, before any scenario has had time to wake up. It is shown directly on
  the dashboard, with the time it was taken.
- Recovery re-reads the device call log and finds rings that happened while
  Jeedom was not listening — an update, a restart, a network outage.

**The door**

- The lock state is read from the device, never assumed.
- A picture is also taken on unlock: a card or a keypad code does not ring, and
  without it nothing would say who just came in.
- The unlock command is created invisible and refuses to run until it has been
  allowed in the plugin configuration.

**Under the hood**

- Two transports for listening to events, CGI long-polling and the native DHIP
  protocol, switching either way when one of them refuses the connection.
- Snapshots are served only to users with read rights on the device: knowing a
  picture's address is not enough to get it.
- No video stream address is exposed: it would only have been usable with the
  device password written into it.
- Plugin settings take effect without restarting the daemon.

Tested on a DHI-VTO2211G-WP, firmware 4.511.0000000.0.R.
