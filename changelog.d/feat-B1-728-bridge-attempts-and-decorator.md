### Changed

- The generated `BridgeAttemptDto` gains an optional `reason`, regenerated
  from the contract hub after `POST /bridge/authorize/attempts` started
  accepting the refusal reason (F4.7 in plan 68). `road.bridge` does not send
  it yet.
