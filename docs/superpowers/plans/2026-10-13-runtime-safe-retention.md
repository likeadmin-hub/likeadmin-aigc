# Successful update runtime retention

User-approved rule: after a committed, healthy update, keep the current immutable
runtime and the previous generation plus shared-file backup needed for one
rollback. Never stop a process, alter a task, or delete a version still in use.

Implementation:

1. Bootstrap pins selected code generations with shared filesystem leases until
   request/CLI shutdown. A short selection gate prevents pointer-read/rename races.
2. Only a committed current journal with a known SQL outcome is collectible.
   Fresh PHP verifies application roots, autoload/service discovery and, for
   system/app bootstrap updates, the committed update receipt and version markers.
3. At each activation, snapshot unconfirmed PHP process identities using Linux
   boot ID, PID and process start ticks. Existing interpreters, including FPM
   masters with old opcode caches, must exit naturally. Reader registrations are
   bound to the selected generation; new unregistered/orphan FPM children also
   defer maintenance. Unreadable process inventory defers cleanup. No process
   receives a signal. Conservatively retaining extra generations is expected
   until old FPM masters/workers have drained; no forced restart is performed.
4. Under a nonblocking update writer lease and short selection gate, quarantine
   older unoccupied generations/backups. Recursively delete outside the selection
   gate, using a two-second deletion budget and subsequent retries. Preserve
   journal receipts and lease files to prevent SQL replay and inode-lock races.
5. Dispatch after successful commit and writer release. Existing crontab execution
   and ordinary HTTP bootstrap asynchronously retry deferred cleanup (also
   covering first installation by an older updater), with a sixty-second backoff and a
   separate dispatch lease. Maintenance failures cannot fail a successful update.

No database migration, queue/billing mutation, process termination, external
deployment or customer-directory cleanup is part of this change. Extra occupied
versions are temporary exceptions to the two-generation target.

Validation: real PHP child lazy-loads its old code after two updates and a cleanup
attempt; after normal exit retention converges to two generations/one backup.
Check failed health, active updates, unknown SQL, writer/reader/selection leases,
legacy interpreter identity/PID reuse, symlinks, protected site files and replay
of a committed archive whose old code has been pruned. Run existing updater and
packaging regressions after checkpoint integration into local develop.
