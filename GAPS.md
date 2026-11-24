# Gaps

What is weak or unverified, for the points the brief weighs: correct money, robustness, UX.

## Money and robustness

- **Orphan generation.** If the connection drops exactly while Pixelforge accepts a request and stays down for all 5 attempts (about 75 s), we fail the generation for free but Pixelforge may still complete and bill it. We pay the cost. It is logged `critical`; there is no screen for it, and the reconciler skips generations we already failed.
- **Overrun by design.** The estimate is a forecast, so a real price can exceed the amount held; it is charged in full and the balance may go below zero (new generations are blocked until the user adds credit).
- **The report is checked against `GET /v1/usage` by hand.** Days are UTC; if the operators' records use another time zone, the days will not line up.
- **One server, one worker.** Every deploy restarts the containers: a few seconds of downtime, no failover.

## Verification

- **Pixelforge is called for real only by hand.** A recorded session is the fixture, so fixtures can drift from the real API. There is no live test suite.
- **Concurrency** is tested with a second PHP process, only for the four cases that move money: a success is charged once, cancel against send, two submits of one user against one balance, a submit against a charge being written. Other races (for example the dispatcher against the reconciler) rely on locks that are not tested this way.
- **No browser tests.** Components are untested; plain frontend logic, lint and types are. The UI was checked by hand on a desktop; the small-screen layout was not.
