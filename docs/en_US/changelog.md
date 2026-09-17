# Changelog

## 0.1

First release.

- Direct connection to a Dahua VTO door station, with no recorder in between.
- Connection test showing the device model, firmware version and clock.
- On-demand snapshot, kept in rotation and served after a session check.
- Commands for the doorbell, the call, the door and the device state.
- Door unlock, locked by default and created invisible.
- Snapshots are served only to users with read rights on the door station.
- How long the Doorbell command stays at 1 is now a setting.
- The visitor's picture is shown directly on the dashboard, with the time it was
  taken.
- The RTSP address command is gone: it would only have been usable with the
  device password written into it.
- Plugin settings take effect without restarting the daemon.
- The Health page tells a stopped daemon apart from an unreachable door station.
- The door state is read from the device at startup instead of being assumed closed.
- A picture is taken on door unlock, not only on ring.
